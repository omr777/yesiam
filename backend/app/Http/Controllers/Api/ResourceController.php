<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\Resource;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ResourceController extends Controller
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
    | LISTAR RECURSOS ACTIVOS
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Resource::class);

        /*
         * FASE 20
         *
         * Por defecto solo recursos activos.
         *
         * Quien administra recursos puede pedir
         * también los inactivos con
         * ?include_inactive=1 para conservar
         * trazabilidad del catálogo.
         */
        $includeInactive =
            $request->boolean('include_inactive')
            && Gate::allows('viewInactive', Resource::class);

        $resources = Resource::query()
            ->when(
                ! $includeInactive,
                fn ($query) => $query->where('is_active', true)
            )
            ->orderByDesc('is_active')
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
            $resource = Resource::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'description' =>
                    $validated['description'] ?? null,
                'is_active' => true,
            ]);

            $this->audit->resourceCreated(
                $request->user(),
                $resource,
                $request->ip()
            );

            return $resource;
        }, 3);

        return response()->json([
            'message' => 'Recurso creado correctamente.',
            'data' => $resource,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR RECURSO
    |--------------------------------------------------------------------------
    |
    | FASE 18
    |
    | Recuperamos el recurso bajo bloqueo
    | antes de calcular y guardar cambios.
    |
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

        $updatedResource = DB::transaction(function () use (
            $request,
            $resource,
            $validated
        ) {
            /*
             * 1. Bloquear el registro actual.
             */
            $lockedResource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 2. Repetir la autorización
             * usando el modelo actualizado.
             */
            Gate::authorize(
                'update',
                $lockedResource
            );

            /*
             * 3. Conservar los valores
             * anteriores reales.
             */
            $before = $lockedResource->only([
                'name',
                'code',
            ]);

            /*
             * 4. Preparar los cambios.
             */
            $lockedResource->fill($validated);

            $changes = [];

            foreach (['name', 'code'] as $field) {
                if ($lockedResource->isDirty($field)) {
                    $changes[$field] = [
                        'old' => $before[$field],
                        'new' => $lockedResource->{$field},
                    ];
                }
            }

            /*
             * No almacenar la descripción
             * completa en la auditoría.
             */
            if ($lockedResource->isDirty('description')) {
                $changes['description_changed'] = true;
            }

            /*
             * 5. Escribir y auditar
             * únicamente cambios reales.
             */
            if ($changes !== []) {
                $lockedResource->save();

                $this->audit->resourceUpdated(
                    $request->user(),
                    $lockedResource,
                    $changes,
                    $request->ip()
                );
            }

            return $lockedResource;

        }, 3);

        return response()->json([
            'message' => 'Recurso actualizado correctamente.',
            'data' => $updatedResource->fresh(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DESACTIVAR RECURSO
    |--------------------------------------------------------------------------
    |
    | FASE 18 - REVOCACIÓN AUTOMÁTICA
    |
    | 1. Autorizar operación.
    | 2. Bloquear recurso.
    | 3. Revalidar autorización y estado.
    | 4. Bloquear concesiones activas.
    | 5. Revocar cada concesión.
    | 6. Auditar cada revocación.
    | 7. Desactivar recurso.
    | 8. Auditar desactivación.
    |
    | Toda la operación es transaccional.
    |
    */

    public function deactivate(
        Request $request,
        Resource $resource
    ): JsonResponse {
        /*
         * 1. AUTORIZACIÓN INICIAL
         */
        Gate::authorize(
            'deactivate',
            $resource
        );

        $actor = $request->user();
        $ipAddress = $request->ip();

        /*
         * 2. TRANSACCIÓN
         *
         * El recurso y las concesiones
         * se modifican de forma atómica.
         */
        $result = DB::transaction(function () use (
            $resource,
            $actor,
            $ipAddress
        ) {
            /*
             * PASO 1: BLOQUEAR RECURSO
             *
             * AccessGrantController también
             * bloquea el recurso antes de
             * confirmar una concesión.
             */
            $lockedResource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * PASO 2: REVALIDAR AUTORIZACIÓN
             */
            Gate::authorize(
                'deactivate',
                $lockedResource
            );

            /*
             * PASO 3: REVALIDAR ESTADO
             *
             * Evitamos procesar dos veces
             * una desactivación.
             */
            if (! $lockedResource->is_active) {
                abort(
                    409,
                    'Este recurso ya está desactivado.'
                );
            }

            /*
             * PASO 4: BLOQUEAR CONCESIONES
             *
             * Solo procesamos concesiones
             * que actualmente están activas.
             *
             * El orden por ID facilita
             * mantener un recorrido estable.
             */
            $activeGrants = AccessGrant::query()
                ->where(
                    'resource_id',
                    $lockedResource->id
                )
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $revokedCount = 0;

            /*
             * PASO 5: REVOCAR CONCESIONES
             */
            foreach ($activeGrants as $grant) {
                $grant->forceFill([
                    'status' => 'revoked',

                    'revoked_by_user_id' =>
                        $actor->id,

                    'revoked_at' => now(),

                    'revocation_reason' =>
                        'Revocación automática por desactivación del recurso.',
                ]);

                $grant->save();

                /*
                 * PASO 6: AUDITAR REVOCACIÓN
                 *
                 * Reutilizamos AuditService.
                 *
                 * Si falla esta auditoría,
                 * se revierte la transacción.
                 */
                $this->audit->accessRevoked(
                    $actor,
                    $grant,
                    $ipAddress
                );

                $revokedCount++;
            }

            /*
             * PASO 7: DESACTIVAR RECURSO
             */
            $lockedResource->forceFill([
                'is_active' => false,
            ])->save();

            /*
             * PASO 8: AUDITAR DESACTIVACIÓN
             */
            $this->audit->resourceDeactivated(
                $actor,
                $lockedResource,
                $ipAddress
            );

            return [
                'resource' => $lockedResource,
                'revoked_count' => $revokedCount,
            ];

        }, 3);

        /*
         * RESPUESTA
         *
         * Conservamos el objeto 'data'
         * y añadimos el número de accesos
         * automáticamente revocados.
         */
        return response()->json([
            'message' => 'Recurso desactivado correctamente.',
            'data' => $result['resource']->fresh(),
            'revoked_access_grants_count' =>
                $result['revoked_count'],
        ]);
    }
}
