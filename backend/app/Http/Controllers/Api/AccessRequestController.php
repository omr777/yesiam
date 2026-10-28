<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\User;
use App\Services\AccessEligibilityService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccessRequestController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | DEPENDENCIAS
    |--------------------------------------------------------------------------
    */

    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessEligibilityService $eligibility
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR MIS SOLICITUDES
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            AccessRequest::class
        );

        $requests = AccessRequest::query()
            ->where(
                'requester_user_id',
                $request->user()->id
            )
            ->with([
                'resource:id,name,code,is_active',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($requests);
    }

    /*
    |--------------------------------------------------------------------------
    | BANDEJA DE SOLICITUDES ASIGNADAS
    |--------------------------------------------------------------------------
    |
    | Solamente muestra solicitudes:
    |
    | - Pendientes.
    | - De otros usuarios.
    | - Con solicitantes activos.
    | - Con recursos activos.
    | - De recursos asignados al responsable.
    |
    */

    public function assigned(Request $request): JsonResponse
    {
        Gate::authorize(
            'viewAssigned',
            AccessRequest::class
        );

        $actor = $request->user();

        $requests = AccessRequest::query()
            ->where('status', 'pending')
            ->where(
                'requester_user_id',
                '!=',
                $actor->id
            )
            ->whereHas(
                'requester',
                function ($query) {
                    $query->where(
                        'status',
                        'active'
                    );
                }
            )
            ->whereHas(
                'resource',
                function ($query) use ($actor) {
                    $query->where(
                        'is_active',
                        true
                    );

                    $query->whereHas(
                        'approvers',
                        function ($approverQuery) use ($actor) {
                            $approverQuery->whereKey(
                                $actor->id
                            );
                        }
                    );
                }
            )
            ->with([
                'requester',
                'resource',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($requests);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SOLICITUD DE ACCESO
    |--------------------------------------------------------------------------
    |
    | FASE 17.7 - ORDEN DE BLOQUEOS
    |
    | Protocolo:
    |
    | 1. Autorizar y validar entrada.
    | 2. Bloquear usuario.
    | 3. Bloquear recurso.
    | 4. Comprobar elegibilidad.
    | 5. Evitar solicitudes pendientes duplicadas.
    | 6. Crear solicitud.
    | 7. Auditar la operación.
    |
    */

    public function store(Request $request): JsonResponse
    {
        /*
         * 1. AUTORIZACIÓN RBAC
         */
        Gate::authorize(
            'create',
            AccessRequest::class
        );

        /*
         * 2. VALIDACIÓN INICIAL
         */
        $validated = $request->validate([
            'resource_id' => [
                'required',
                'integer',
                Rule::exists(
                    'resources',
                    'id'
                )->where('is_active', true),
            ],
            'justification' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
        ]);

        $actor = $request->user();

        /*
         * 3. TRANSACCIÓN
         *
         * El parámetro 3 permite a Laravel
         * reintentar determinadas fallas
         * de concurrencia, como deadlocks.
         *
         * No sustituye la necesidad de
         * mantener bloqueos consistentes.
         */
        $accessRequest = DB::transaction(function () use (
            $validated,
            $actor,
            $request
        ) {
            /*
             * PASO 1: BLOQUEAR USUARIO
             *
             * ORDEN UNIFICADO:
             *
             * User -> Resource
             *
             * Coordinamos esta operación
             * con Mover y Leaver.
             */
            $currentUser = User::query()
                ->whereKey($actor->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * PASO 2: REVALIDAR IDENTIDAD
             *
             * No podemos confiar únicamente
             * en el estado observado antes
             * de iniciar la transacción.
             */
            if ($currentUser->status !== 'active') {
                abort(
                    403,
                    'La identidad no está activa.'
                );
            }

            /*
             * PASO 3: BLOQUEAR RECURSO
             *
             * Se adquiere después del bloqueo
             * del usuario.
             */
            $resource = Resource::query()
                ->whereKey(
                    $validated['resource_id']
                )
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $resource) {
                throw ValidationException::withMessages([
                    'resource_id' => [
                        'El recurso ya no está disponible.',
                    ],
                ]);
            }

            /*
             * PASO 4: ELEGIBILIDAD ACTUAL
             *
             * Verificamos el estado actual
             * del usuario y del recurso.
             */
            $decision = $this->eligibility->evaluate(
                $currentUser,
                $resource
            );

            if (! $decision['eligible']) {
                throw ValidationException::withMessages([
                    'resource_id' => [
                        'No cumples las condiciones de elegibilidad para solicitar este recurso.',
                    ],
                ]);
            }

            /*
             * PASO 5: PREVENIR DUPLICADOS
             *
             * El bloqueo del usuario ayuda
             * a serializar las solicitudes
             * de este usuario que respetan
             * el mismo protocolo.
             */
            $alreadyPending = AccessRequest::query()
                ->where(
                    'requester_user_id',
                    $currentUser->id
                )
                ->where(
                    'resource_id',
                    $resource->id
                )
                ->where(
                    'status',
                    'pending'
                )
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'resource_id' => [
                        'Ya tienes una solicitud pendiente para este recurso.',
                    ],
                ]);
            }

            /*
             * PASO 6: CREAR SOLICITUD
             */
            $newRequest = AccessRequest::create([
                'requester_user_id' => $currentUser->id,
                'resource_id' => $resource->id,
                'justification' =>
                    $validated['justification'],
            ]);

            $newRequest->refresh();

            /*
             * PASO 7: AUDITORÍA
             *
             * Si falla el registro de auditoría,
             * se revierte toda la transacción.
             */
            $this->audit->accessRequested(
                $currentUser,
                $newRequest,
                $request->ip()
            );

            return $newRequest;

        }, 3);

        /*
         * RESPUESTA EXITOSA
         */
        return response()->json([
            'message' =>
                'Solicitud de acceso creada correctamente.',

            'data' => $accessRequest->load(
                'resource:id,name,code,is_active'
            ),
        ], 201);
    }
}
