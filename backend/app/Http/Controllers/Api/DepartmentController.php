<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Department::class);

        return response()->json([
            'data' => Department::orderBy('name')->get(),
        ]);
    }

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

        $department = Department::create($validated);

        return response()->json([
            'message' => 'Departamento creado correctamente.',
            'data' => $department,
        ], 201);
    }
    
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

    $department->update($validated);

    return response()->json([
        'message' => 'Departamento actualizado correctamente.',
        'data' => $department->fresh(),
    ]);
}

    public function deactivate(Department $department): JsonResponse
    {
    Gate::authorize('deactivate', $department);

    if (! $department->is_active) {
        return response()->json([
            'message' => 'El departamento ya está inactivo.',
        ], 409);
     }

    $department->update([
        'is_active' => false,
     ]);

     return response()->json([
        'message' => 'Departamento desactivado correctamente.',
        'data' => $department->fresh(),
    ]);
    }

}
