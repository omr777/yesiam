<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| YESIAM - PERFIL DE LA SESIÓN ACTUAL
|--------------------------------------------------------------------------
|
| FASE 20
|
| GET /api/user
|
| Devuelve la identidad autenticada junto con
| sus roles y permisos efectivos.
|
| Angular usa esta información SOLO para
| mostrar u ocultar opciones de navegación.
|
| La autorización real sigue en las
| políticas de Laravel: ocultar un botón
| nunca concede ni niega un permiso.
|
| No se exponen contraseñas, tokens
| ni campos internos.
|
*/

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'department:id,name,code,is_active',
            'roles:id,code,name',
        ]);

        /*
         * Permisos efectivos: la unión de los
         * permisos de todos los roles asignados.
         */
        $permissions = Permission::query()
            ->whereHas('roles.users', function ($query) use ($user) {
                $query->whereKey($user->id);
            })
            ->orderBy('code')
            ->pluck('code')
            ->values()
            ->all();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'department_id' => $user->department_id,
            'department' => $user->department ? [
                'id' => $user->department->id,
                'name' => $user->department->name,
                'code' => $user->department->code,
                'is_active' => (bool) $user->department->is_active,
            ] : null,
            'roles' => $user->roles
                ->sortBy('code')
                ->values()
                ->map(fn ($role) => [
                    'code' => $role->code,
                    'name' => $role->name,
                ])
                ->all(),
            'permissions' => $permissions,
        ]);
    }
}
