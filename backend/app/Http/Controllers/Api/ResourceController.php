<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ResourceController extends Controller
{
    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | LISTAR RECURSOS ACTIVOS
    |--------------------------------------------------------------------------
    */

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Resource::class);

        $resources = Resource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $resources,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR UN RECURSO
    |--------------------------------------------------------------------------
    */

    public function show(Resource $resource): JsonResponse
    {
        Gate::authorize('view', $resource);

        return response()->json([
            'data' => $resource,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR RECURSO
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Resource::class);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                'unique:resources,code',
            ],
            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $resource = DB::transaction(function () use (
            $request,
            $validated
        ) {
            // 1. Crear el recurso.
            $resource = Resource::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ]);

            // 2. Registrar su creación en auditoría.
            $this->audit->resourceCreated(
                $request->user(),
                $resource,
                $request->ip()
            );

            return $resource;
        });

        return response()->json([
            'message' => 'Recurso creado correctamente.',
            'data' => $resource,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR RECURSO
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Resource $resource
    ): JsonResponse {
        Gate::authorize('update', $resource);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('resources', 'code')
                    ->ignore($resource->id),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $resource,
            $validated
        ) {
            // 1. Guardar los valores anteriores.
            $before = $resource->only([
                'name',
                'code',
            ]);

            // 2. Preparar los nuevos valores.
            $resource->fill($validated);

            $changes = [];

            // 3. Detectar cambios de nombre y código.
            foreach (['name', 'code'] as $field) {
                if ($resource->isDirty($field)) {
                    $changes[$field] = [
                        'old' => $before[$field],
                        'new' => $resource->{$field},
                    ];
                }
            }

            // Registrar que cambió la descripción
            // sin guardar su contenido en auditoría.
            if ($resource->isDirty('description')) {
                $changes['description_changed'] = true;
            }

            // 4. Persistir únicamente si hubo cambios.
            if ($changes !== []) {
                $resource->save();

                // 5. Registrar los cambios en auditoría.
                $this->audit->resourceUpdated(
                    $request->user(),
                    $resource,
                    $changes,
                    $request->ip()
                );
            }
        });

        return response()->json([
            'message' => 'Recurso actualizado correctamente.',
            'data' => $resource->fresh(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DESACTIVAR RECURSO
    |--------------------------------------------------------------------------
    */

    public function deactivate(
        Request $request,
        Resource $resource
    ): JsonResponse {
        Gate::authorize('deactivate', $resource);

        DB::transaction(function () use (
            $request,
            $resource
        ) {
            // 1. Desactivar el recurso.
            $resource->update([
                'is_active' => false,
            ]);

            // 2. Registrar quién lo desactivó.
            $this->audit->resourceDeactivated(
                $request->user(),
                $resource,
                $request->ip()
            );
        });

        return response()->json([
            'message' => 'Recurso desactivado correctamente.',
            'data' => $resource->fresh(),
        ]);
    }
}
