<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\Resource;
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
    | SERVICIO DE AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR MIS PROPIAS SOLICITUDES
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AccessRequest::class);

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
    | CONSULTAR SOLICITUDES PENDIENTES ASIGNADAS
    |--------------------------------------------------------------------------
    */

    public function assigned(Request $request): JsonResponse
    {
        // 1. Autorizar mediante AccessRequestPolicy.
        Gate::authorize(
            'viewAssigned',
            AccessRequest::class
        );

        // 2. Identificar al responsable autenticado.
        $actor = $request->user();

        /*
         * 3. Buscar exclusivamente solicitudes pendientes
         * de recursos que el usuario administra.
         */
        $requests = AccessRequest::query()
            ->where('status', 'pending')

            // Evitar mostrar solicitudes propias.
            ->where('requester_user_id', '!=', $actor->id)

            // Solo solicitantes con cuentas activas.
            ->whereHas('requester', function ($query) {
                $query->where('status', 'active');
            })

            // Solo recursos activos asignados al responsable.
            ->whereHas('resource', function ($query) use ($actor) {
                $query->where('is_active', true);

                $query->whereHas(
                    'approvers',
                    function ($approvers) use ($actor) {
                        $approvers->where(
                            'users.id',
                            $actor->id
                        );
                    }
                );
            })

            // Información necesaria para revisar solicitudes.
            ->with([
                'requester:id,name,status',
                'resource:id,name,code,is_active',
            ])

            // Ordenar primero las solicitudes recientes.
            ->orderByDesc('created_at')
            ->orderByDesc('id')

            // Limitar el volumen de resultados.
            ->paginate(20);

        return response()->json($requests);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SOLICITUD DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        // 1. Verificar permiso para solicitar acceso.
        Gate::authorize('create', AccessRequest::class);

        // 2. Validar los campos recibidos.
        $validated = $request->validate([
            'resource_id' => [
                'required',
                'integer',
                Rule::exists('resources', 'id')
                    ->where('is_active', true),
            ],
            'justification' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
        ]);

        // 3. Obtener la identidad autenticada.
        $actor = $request->user();

        /*
        |--------------------------------------------------------------------------
        | TRANSACCIÓN: SOLICITUD + AUDITORÍA
        |--------------------------------------------------------------------------
        */

        $accessRequest = DB::transaction(function () use (
            $validated,
            $actor,
            $request
        ) {
            // 4. Bloquear y comprobar el recurso.
            $resource = Resource::query()
                ->whereKey($validated['resource_id'])
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

            // 5. Impedir solicitudes pendientes duplicadas.
            $alreadyPending = AccessRequest::query()
                ->where('requester_user_id', $actor->id)
                ->where('resource_id', $resource->id)
                ->where('status', 'pending')
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'resource_id' => [
                        'Ya tienes una solicitud pendiente para este recurso.',
                    ],
                ]);
            }

            // 6. Crear la solicitud.
            $newRequest = AccessRequest::create([
                'requester_user_id' => $actor->id,
                'resource_id' => $resource->id,
                'justification' => $validated['justification'],
            ]);

            // 7. Recuperar el estado asignado por MySQL.
            $newRequest->refresh();

            // 8. Registrar la operación en auditoría.
            $this->audit->accessRequested(
                $actor,
                $newRequest,
                $request->ip()
            );

            return $newRequest;
        });

        return response()->json([
            'message' => 'Solicitud de acceso creada correctamente.',
            'data' => $accessRequest->load(
                'resource:id,name,code,is_active'
            ),
        ], 201);
    }
}
