<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('is_active', true),
            ],
        ]);
        $user = DB::transaction(function () use ($validated) {
            $employeeRole = Role::where('code', 'employee')
                ->firstOrFail();

            $user = new User();

            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->department_id = $validated['department_id'];

            // La identidad requiere activación posterior.
            $user->status = 'suspended';

            // Contraseña aleatoria que nunca entregamos.
            $user->password = Hash::make(Str::random(64));

            $user->save();

            $user->roles()->attach($employeeRole->id);

            return $user;
        });

        return response()->json([
            'message' => 'Identidad creada correctamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'department_id' => $user->department_id,
                'roles' => ['employee'],
            ],
        ], 201);
    }

    public function changeDepartment(Request $request, User $user): JsonResponse
    {
        Gate::authorize('changeDepartment', $user);

        if ($user->status === 'disabled') {
            return response()->json([
                'message' => 'No se puede trasladar una identidad desactivada.',
            ], 409);
        }

        $validated = $request->validate([
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('is_active', true),
            ],
        ]);

        $newDepartmentId = (int) $validated['department_id'];

        if ((int) $user->department_id === $newDepartmentId) {
            return response()->json([
                'message' => 'El usuario ya pertenece a ese departamento.',
            ], 409);
        }

        $previousDepartmentId = $user->department_id;

        $user->department_id = $newDepartmentId;
        $user->save();

        return response()->json([
            'message' => 'Departamento actualizado correctamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'previous_department_id' => $previousDepartmentId,
                'department_id' => $user->department_id,
            ],
        ]);
    }
}
