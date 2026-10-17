<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
}
