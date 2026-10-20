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
    | INYECCIÓN DEL SERVICIO DE AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR MIS SOLICITUDES
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
    | CREAR SOLICITUD DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        // 1. Comprobar si puede solicitar accesos.
        Gate::authorize('create', AccessRequest::class);

        // 2. Validar únicamente los campos permitidos.
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

        // 3. Identificar al empleado autenticado.
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
            // 4. Bloquear el recurso durante la operación.
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

            // 5. Comprobar solicitudes pendientes duplicadas.
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

            /*
             * 7. Recuperar los valores asignados por MySQL.
             * Esto incluye status = 'pending'.
             */
            $newRequest->refresh();

            // 8. Registrar el evento de auditoría.
            $this->audit->accessRequested(
                $actor,
                $newRequest,
                $request->ip()
            );

            return $newRequest;
        });

        /*
        |--------------------------------------------------------------------------
        | RESPUESTA
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' => 'Solicitud de acceso creada correctamente.',
            'data' => $accessRequest->load(
                'resource:id,name,code,is_active'
            ),
        ], 201);
    }
}
