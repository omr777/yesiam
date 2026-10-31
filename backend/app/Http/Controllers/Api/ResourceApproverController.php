<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResourceApproverController extends Controller
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
    | ASIGNAR RESPONSABLE A UN RECURSO
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request,
        Resource $resource
    ): JsonResponse {

        // 1. Autorizar al administrador.
        Gate::authorize('assignApprover', $resource);

        // 2. Validar el usuario seleccionado.
        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
            ],
        ]);

        // 3. Identificar al administrador autenticado.
        $actor = $request->user();

        /*
        |--------------------------------------------------------------------------
        | TRANSACCIÓN: ASIGNACIÓN + AUDITORÍA
        |--------------------------------------------------------------------------
        */

        $assignment = DB::transaction(function () use (
            $validated,
            $resource,
            $actor,
            $request
        ) {

            // 4. Bloquear el recurso.
            $lockedResource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 5. Comprobar que siga activo.
            if (! $lockedResource->is_active) {
                abort(
                    409,
                    'No se pueden asignar responsables a un recurso inactivo.'
                );
            }

            // 6. Bloquear el usuario seleccionado.
            $approver = User::query()
                ->whereKey($validated['user_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // 7. Comprobar que esté activo.
            if ($approver->status !== 'active') {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'El responsable debe tener una cuenta activa.',
                    ],
                ]);
            }

            // 8. Comprobar el permiso requerido.
            if (! $approver->hasPermission('access.approve')) {
                throw ValidationException::withMessages([
                    'user_id' => [
                        'El usuario no tiene permiso para aprobar accesos.',
                    ],
                ]);
            }

            // 9. Evitar asignaciones duplicadas.
            $alreadyAssigned = DB::table('resource_approvers')
                ->where('resource_id', $lockedResource->id)
                ->where('user_id', $approver->id)
                ->exists();

            if ($alreadyAssigned) {
                abort(
                    409,
                    'Este usuario ya es responsable del recurso.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 10. CREAR ASIGNACIÓN
            |--------------------------------------------------------------------------
            */

            $lockedResource->approvers()->attach(
                $approver->id,
                [
                    'assigned_by_user_id' => $actor->id,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | 11. REGISTRAR AUDITORÍA
            |--------------------------------------------------------------------------
            */

            $this->audit->resourceApproverAssigned(
                $actor,
                $lockedResource,
                $approver,
                $request->ip()
            );

            /*
            |--------------------------------------------------------------------------
            | 12. DEVOLVER DATOS
            |--------------------------------------------------------------------------
            */

            return [
                'resource_id' => $lockedResource->id,
                'user_id' => $approver->id,
                'assigned_by_user_id' => $actor->id,
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | RESPUESTA HTTP
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' => 'Responsable asignado correctamente.',
            'data' => $assignment,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | FASE 20 - CONSULTAR RESPONSABLES
    |--------------------------------------------------------------------------
    |
    | GET /api/resources/{resource}/approvers
    |
    | Solo campos necesarios para la gestión.
    |
    */

    public function index(Resource $resource): JsonResponse
    {
        Gate::authorize('viewApprovers', $resource);

        $approvers = $resource->approvers()
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'users.status',
            ])
            ->map(fn (User $approver) => [
                'id' => $approver->id,
                'name' => $approver->name,
                'email' => $approver->email,
                'status' => $approver->status,
                'assigned_at' => $approver->pivot->created_at,
            ])
            ->values();

        return response()->json([
            'data' => $approvers,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FASE 20 - RETIRAR RESPONSABLE
    |--------------------------------------------------------------------------
    |
    | DELETE /api/resources/{resource}/approvers/{user}
    |
    | Las solicitudes pendientes no se pierden:
    | quedan disponibles para los demás
    | responsables del recurso.
    |
    */

    public function destroy(
        Request $request,
        Resource $resource,
        User $user
    ): JsonResponse {
        Gate::authorize('assignApprover', $resource);

        $actor = $request->user();

        DB::transaction(function () use (
            $resource,
            $user,
            $actor,
            $request
        ) {
            $lockedResource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedResource->is_active) {
                abort(
                    409,
                    'No se pueden modificar los responsables de un recurso inactivo.'
                );
            }

            $isAssigned = DB::table('resource_approvers')
                ->where('resource_id', $lockedResource->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->exists();

            if (! $isAssigned) {
                abort(
                    404,
                    'Este usuario no es responsable del recurso.'
                );
            }

            $lockedResource->approvers()->detach($user->id);

            $this->audit->resourceApproverRemoved(
                $actor,
                $lockedResource,
                $user,
                'manual',
                $request->ip()
            );
        }, 3);

        return response()->json([
            'message' => 'Responsable retirado correctamente.',
            'data' => [
                'resource_id' => $resource->id,
                'user_id' => $user->id,
            ],
        ]);
    }
}
