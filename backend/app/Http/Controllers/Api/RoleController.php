<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| YESIAM - CATÁLOGO DE ROLES
|--------------------------------------------------------------------------
|
| FASE 20
|
| GET /api/roles
|
| Catálogo de solo lectura para la
| asignación de roles. Requiere el mismo
| permiso que administrar identidades.
|
| Los roles y permisos se definen en
| RbacSeeder; no se crean desde la API.
|
*/

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $roles = Role::query()
            ->with('permissions:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'code' => $role->code,
                'name' => $role->name,
                'permissions' => $role->permissions
                    ->sortBy('code')
                    ->pluck('code')
                    ->values(),
            ])
            ->values();

        return response()->json([
            'data' => $roles,
        ]);
    }
}
