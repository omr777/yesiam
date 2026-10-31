<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| YESIAM - ADMINISTRACIÓN DE IDENTIDADES
|--------------------------------------------------------------------------
|
| FASE 20
|
| Completa el ciclo de vida que ya cubría
| UserController (Joiner, Mover, Leaver):
|
| GET   /api/users/{user}          Detalle.
| PATCH /api/users/{user}/activate Activación.
| PUT   /api/users/{user}/roles    Roles.
|
| Mismos principios que el resto del backend:
|
| - Políticas explícitas (UserPolicy).
| - Transacciones con lockForUpdate().
| - Nueva verificación tras el bloqueo.
| - Auditoría dentro de la transacción.
| - Ningún secreto en la auditoría.
|
*/

class IdentityAdministrationController extends Controller
{
    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | DETALLE DE IDENTIDAD
    |--------------------------------------------------------------------------
    */

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        $user->loadMissing([
            'department:id,name,code,is_active',
            'roles:id,code,name',
        ]);

        $managedResources = $user->managedResources()
            ->orderBy('resources.name')
            ->get([
                'resources.id',
                'resources.name',
                'resources.code',
                'resources.is_active',
            ])
            ->map(fn (Resource $resource) => [
                'id' => $resource->id,
                'name' => $resource->name,
                'code' => $resource->code,
                'is_active' => (bool) $resource->is_active,
            ])
            ->values();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'department_id' => $user->department_id,
                'department' => $user->department ? [
                    'id' => $user->department->id,
                    'name' => $user->department->name,
                    'code' => $user->department->code,
                    'is_active' =>
                        (bool) $user->department->is_active,
                ] : null,
                'roles' => $user->roles
                    ->sortBy('code')
                    ->values()
                    ->map(fn (Role $role) => [
                        'code' => $role->code,
                        'name' => $role->name,
                    ]),
                'managed_resources' => $managedResources,
                'active_access_grants_count' => $user
                    ->accessGrants()
                    ->where('status', 'active')
                    ->count(),
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVAR IDENTIDAD
    |--------------------------------------------------------------------------
    |
    | suspended -> active
    |
    | Al activar se genera una contraseña
    | temporal aleatoria. Se devuelve UNA
    | sola vez en la respuesta para que el
    | administrador la entregue por un canal
    | seguro. Solo se guarda su hash.
    |
    | Una identidad desactivada (Leaver)
    | no se reactiva por esta vía.
    |
    */

    public function activate(
        Request $request,
        User $user
    ): JsonResponse {
        Gate::authorize('activate', $user);

        if ($user->status !== 'suspended') {
            return response()->json([
                'message' => $user->status === 'active'
                    ? 'La identidad ya está activa.'
                    : 'Una identidad desactivada no puede reactivarse.',
            ], 409);
        }

        $actor = $request->user();
        $ipAddress = $request->ip();

        $temporaryPassword = Str::password(16);

        $activatedUser = DB::transaction(function () use (
            $user,
            $actor,
            $ipAddress,
            $temporaryPassword
        ) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::authorize('activate', $lockedUser);

            if ($lockedUser->status !== 'suspended') {
                abort(
                    409,
                    'La identidad ya no está suspendida.'
                );
            }

            $previousStatus = $lockedUser->status;

            $lockedUser->forceFill([
                'status' => 'active',
                'password' => Hash::make($temporaryPassword),
            ])->save();

            $this->audit->identityActivated(
                $actor,
                $lockedUser,
                $previousStatus,
                $ipAddress
            );

            return $lockedUser;
        }, 3);

        return response()
            ->json([
                'message' => 'Identidad activada correctamente.',
                'data' => [
                    'id' => $activatedUser->id,
                    'status' => $activatedUser->status,
                    'temporary_password' => $temporaryPassword,
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }

    /*
    |--------------------------------------------------------------------------
    | ASIGNAR ROLES
    |--------------------------------------------------------------------------
    |
    | Reemplaza el conjunto completo de roles.
    |
    | Si la identidad pierde el permiso
    | access.approve, se retira de todos los
    | recursos donde era responsable. Así no
    | quedan responsables "fantasma" (Assume
    | Breach / Least Privilege).
    |
    */

    public function updateRoles(
        Request $request,
        User $user
    ): JsonResponse {
        Gate::authorize('assignRoles', $user);

        $validated = $request->validate([
            'roles' => [
                'required',
                'array',
                'min:1',
                'max:10',
            ],
            'roles.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('roles', 'code'),
            ],
        ]);

        if ($user->status === 'disabled') {
            return response()->json([
                'message' =>
                    'No se pueden modificar los roles de una identidad desactivada.',
            ], 409);
        }

        $actor = $request->user();
        $ipAddress = $request->ip();

        $newRoleCodes = collect($validated['roles'])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $result = DB::transaction(function () use (
            $user,
            $actor,
            $ipAddress,
            $newRoleCodes
        ) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::authorize('assignRoles', $lockedUser);

            if ($lockedUser->status === 'disabled') {
                abort(
                    409,
                    'No se pueden modificar los roles de una identidad desactivada.'
                );
            }

            $previousRoleCodes = $lockedUser->roles()
                ->orderBy('roles.code')
                ->pluck('roles.code')
                ->all();

            if ($previousRoleCodes === $newRoleCodes) {
                return [
                    'user' => $lockedUser,
                    'changed' => false,
                    'removed' => [],
                ];
            }

            $roles = Role::query()
                ->whereIn('code', $newRoleCodes)
                ->get();

            $lockedUser->roles()->sync(
                $roles->pluck('id')->all()
            );

            /*
             * Retirar responsabilidades de aprobación
             * si el nuevo conjunto ya no las permite.
             */
            $removedResourceIds = [];

            if (! $lockedUser->hasPermission('access.approve')) {
                $managed = $lockedUser->managedResources()
                    ->orderBy('resources.id')
                    ->lockForUpdate()
                    ->get();

                foreach ($managed as $resource) {
                    $lockedUser->managedResources()
                        ->detach($resource->id);

                    $this->audit->resourceApproverRemoved(
                        $actor,
                        $resource,
                        $lockedUser,
                        'role_change',
                        $ipAddress
                    );

                    $removedResourceIds[] = (int) $resource->id;
                }
            }

            $this->audit->identityRolesChanged(
                $actor,
                $lockedUser,
                $previousRoleCodes,
                $newRoleCodes,
                $removedResourceIds,
                $ipAddress
            );

            return [
                'user' => $lockedUser,
                'changed' => true,
                'removed' => $removedResourceIds,
            ];
        }, 3);

        return response()->json([
            'message' => $result['changed']
                ? 'Roles actualizados correctamente.'
                : 'Los roles ya estaban actualizados.',
            'data' => [
                'id' => $result['user']->id,
                'roles' => $newRoleCodes,
                'changed' => $result['changed'],
                'removed_approver_resource_ids' =>
                    $result['removed'],
            ],
        ]);
    }
}
