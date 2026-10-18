<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR DEPARTAMENTOS
    |--------------------------------------------------------------------------
    */

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Department::class);

        return response()->json([
            'data' => Department::orderBy('name')->get(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR DEPARTAMENTO
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Department::class);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'unique:departments,name',
            ],
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                'unique:departments,code',
            ],
        ]);

        $department = DB::transaction(function () use (
            $request,
            $validated
        ) {
            // 1. Crear el departamento.
            $department = Department::create($validated);

            // 2. Registrar quién lo creó.
            $this->audit->departmentCreated(
                $request->user(),
                $department,
                $request->ip()
            );

            return $department;
        });

        return response()->json([
            'message' => 'Departamento creado correctamente.',
            'data' => $department,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR DEPARTAMENTO
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Department $department
    ): JsonResponse {
        Gate::authorize('update', $department);

        if (! $department->is_active) {
            return response()->json([
                'message' => 'No se puede modificar un departamento inactivo.',
            ], 409);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('departments', 'name')
                    ->ignore($department->id),
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('departments', 'code')
                    ->ignore($department->id),
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $department,
            $validated
        ) {
            // Guardamos los valores anteriores.
            $before = $department->only([
                'name',
                'code',
            ]);

            // Aplicamos los nuevos valores en memoria.
            $department->fill($validated);

            $changes = [];

            foreach (['name', 'code'] as $field) {
                if ($department->isDirty($field)) {
                    $changes[$field] = [
                        'old' => $before[$field],
                        'new' => $department->{$field},
                    ];
                }
            }

            // Solo guardamos y auditamos si hubo
            // un cambio real.
            if ($changes !== []) {
                $department->save();

                $this->audit->departmentUpdated(
                    $request->user(),
                    $department,
                    $changes,
                    $request->ip()
                );
            }
        });

        return response()->json([
            'message' => 'Departamento actualizado correctamente.',
            'data' => $department->fresh(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DESACTIVAR DEPARTAMENTO
    |--------------------------------------------------------------------------
    */

    public function deactivate(
        Request $request,
        Department $department
    ): JsonResponse {
        Gate::authorize('deactivate', $department);

        if (! $department->is_active) {
            return response()->json([
                'message' => 'El departamento ya está inactivo.',
            ], 409);
        }

        DB::transaction(function () use (
            $request,
            $department
        ) {
            // 1. Desactivar el departamento.
            $department->update([
                'is_active' => false,
            ]);

            // 2. Registrar la desactivación.
            $this->audit->departmentDeactivated(
                $request->user(),
                $department,
                $request->ip()
            );
        });

        return response()->json([
            'message' => 'Departamento desactivado correctamente.',
            'data' => $department->fresh(),
        ]);
    }
}
