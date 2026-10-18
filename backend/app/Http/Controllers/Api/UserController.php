<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

class UserController extends Controller
{
    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | JOINER - Crear una identidad
    |--------------------------------------------------------------------------
    */
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

        $user = DB::transaction(function () use ($validated, $request) {

            $employeeRole = Role::where('code', 'employee')
                ->firstOrFail();

            $user = new User();

            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->department_id = $validated['department_id'];

            // Toda identidad nueva requiere activación posterior.
            $user->status = 'suspended';

            // Generamos una contraseña aleatoria desconocida.
            $user->password = Hash::make(Str::random(64));

            $user->save();

            // Asignamos únicamente el rol Empleado.
            $user->roles()->attach($employeeRole->id);

            // Registramos el evento de auditoría.
            $this->audit->identityCreated(
                $request->user(),
                $user,
                $request->ip()
            );

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

    /*
    |--------------------------------------------------------------------------
    | MOVER - Cambiar el departamento
    |--------------------------------------------------------------------------
    */
    public function changeDepartment(
        Request $request,
        User $user
    ): JsonResponse {

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

        $previousDepartmentId = $user->department_id === null
            ? null
            : (int) $user->department_id;

        DB::transaction(function () use (
            $request,
            $user,
            $previousDepartmentId,
            $newDepartmentId
        ) {
            // Actualizamos el departamento.
            $user->department_id = $newDepartmentId;
            $user->save();

            // Registramos el movimiento en auditoría.
            $this->audit->identityMoved(
                $request->user(),
                $user,
                $previousDepartmentId,
                $newDepartmentId,
                $request->ip()
            );
        });

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

    /*
    |--------------------------------------------------------------------------
    | LEAVER - Desactivar una identidad
    |--------------------------------------------------------------------------
    */
    public function deactivate(
        Request $request,
        User $user
    ): JsonResponse {

        Gate::authorize('deactivate', $user);

        if ($user->status === 'disabled') {
            return response()->json([
                'message' => 'La identidad ya está desactivada.',
            ], 409);
        }

        // La revocación requiere sesiones persistidas en MySQL.
        abort_unless(
            config('session.driver') === 'database',
            503,
            'La revocación de sesiones no está configurada.'
        );

        DB::transaction(function () use ($request, $user) {

            $previousStatus = $user->status;

            // 1. Desactivamos la identidad.
            $user->status = 'disabled';
            $user->save();

            // 2. Eliminamos sus sesiones almacenadas.
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            // 3. Revocamos sus tokens de Sanctum.
            $user->tokens()->delete();

            // 4. Registramos la baja en auditoría.
            $this->audit->identityDeactivated(
                $request->user(),
                $user,
                $previousStatus,
                $request->ip()
            );
        });

        return response()->json([
            'message' => 'Identidad desactivada correctamente.',
            'data' => [
                'id' => $user->id,
                'status' => 'disabled',
            ],
        ]);
    }
}
