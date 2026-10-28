<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Resource;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResourceDepartmentEligibilityController extends Controller
{
    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR ELEGIBILIDAD DE UN RECURSO
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        Resource $resource
    ): JsonResponse {
        Gate::authorize('view', $resource);

        $resource->load('eligibleDepartments');

        $departments = $resource->eligibleDepartments
            ->sortBy('id')
            ->values()
            ->map(function (Department $department) {
                return [
                    'id' => $department->id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'is_active' => (bool) $department->is_active,
                ];
            });

        return response()->json([
            'message' => 'Elegibilidad consultada correctamente.',
            'data' => [
                'resource_id' => $resource->id,
                'resource_name' => $resource->name,
                'department_restricted' =>
                    (bool) $resource->department_restricted,
                'eligible_departments' => $departments,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR ELEGIBILIDAD DEPARTAMENTAL
    |--------------------------------------------------------------------------
    |
    | Reglas:
    |
    | Sin restricción:
    |   department_restricted = false
    |   department_ids = []
    |
    | Restringido con departamentos:
    |   department_restricted = true
    |   department_ids = [1, 2, ...]
    |
    | Restringido sin departamentos:
    |   department_restricted = true
    |   department_ids = []
    |
    | En el último caso nadie es elegible.
    |
    */

    public function update(
        Request $request,
        Resource $resource
    ): JsonResponse {
        /*
         * 1. AUTORIZACIÓN INICIAL
         */
        Gate::authorize('update', $resource);

        /*
         * 2. VALIDACIÓN
         *
         * CORRECCIÓN FASE 17.4:
         *
         * Usamos "present" en lugar de "required"
         * para department_ids.
         *
         * El campo debe enviarse siempre,
         * pero puede contener un arreglo vacío.
         *
         * Un arreglo vacío es una configuración
         * válida para un recurso restringido.
         */
        $validated = $request->validate([
            'department_restricted' => [
                'required',
                'boolean',
            ],
            'department_ids' => [
                'present',
                'array',
                'max:100',
            ],
            'department_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(
                    'departments',
                    'id'
                )->where('is_active', true),
            ],
        ]);

        $restricted = (bool) $validated['department_restricted'];

        $departmentIds = array_map(
            'intval',
            $validated['department_ids']
        );

        sort($departmentIds);

        /*
         * 3. EVITAR CONFIGURACIONES AMBIGUAS
         *
         * Un recurso sin restricciones no debe
         * mantener una lista departamental.
         */
        if (! $restricted && count($departmentIds) > 0) {
            throw ValidationException::withMessages([
                'department_ids' => [
                    'Un recurso sin restricción no debe tener departamentos específicos.',
                ],
            ]);
        }

        $actor = $request->user();

        /*
         * 4. TRANSACCIÓN
         */
        $result = DB::transaction(function () use (
            $resource,
            $actor,
            $restricted,
            $departmentIds,
            $request
        ) {
            /*
             * Bloquear el recurso mientras
             * actualizamos su configuración.
             */
            $lockedResource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Revalidar autorización.
             */
            Gate::authorize(
                'update',
                $lockedResource
            );

            /*
             * 5. REVALIDAR DEPARTAMENTOS ACTIVOS
             */
            $activeDepartmentIds = Department::query()
                ->whereIn('id', $departmentIds)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($activeDepartmentIds !== $departmentIds) {
                throw ValidationException::withMessages([
                    'department_ids' => [
                        'Uno o más departamentos ya no están activos.',
                    ],
                ]);
            }

            /*
             * 6. CONFIGURACIÓN ANTERIOR
             */
            $previousRestricted =
                (bool) $lockedResource->department_restricted;

            $previousIds = $lockedResource
                ->eligibleDepartments()
                ->pluck('departments.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            sort($previousIds);

            /*
             * 7. IDEMPOTENCIA
             *
             * Si no hay cambios efectivos,
             * evitamos nuevas escrituras
             * y registros de auditoría.
             */
            if (
                $previousRestricted === $restricted
                && $previousIds === $departmentIds
            ) {
                return [
                    'resource' => $lockedResource,
                    'department_ids' => $departmentIds,
                    'changed' => false,
                ];
            }

            /*
             * 8. PROTEGER CONCESIONES ACTIVAS
             *
             * Rechazar configuraciones que
             * harían incompatibles los accesos
             * activos existentes.
             */
            if ($restricted) {
                $hasIneligibleActiveGrant = DB::table(
                    'access_grants as grants'
                )
                    ->join(
                        'users as users',
                        'users.id',
                        '=',
                        'grants.user_id'
                    )
                    ->leftJoin(
                        'departments as departments',
                        'departments.id',
                        '=',
                        'users.department_id'
                    )
                    ->where(
                        'grants.resource_id',
                        $lockedResource->id
                    )
                    ->where(
                        'grants.status',
                        'active'
                    )
                    ->where(function ($query) use (
                        $departmentIds
                    ) {
                        $query
                            ->whereNull('users.department_id')
                            ->orWhereNull('departments.id')
                            ->orWhere(
                                'departments.is_active',
                                false
                            )
                            ->orWhereNotIn(
                                'users.department_id',
                                $departmentIds
                            );
                    })
                    ->exists();

                if ($hasIneligibleActiveGrant) {
                    abort(
                        409,
                        'Existen concesiones activas incompatibles con la nueva restricción. Revísalas antes de cambiar la elegibilidad.'
                    );
                }
            }

            /*
             * 9. ACTUALIZAR RESTRICCIÓN
             *
             * Usamos forceFill porque este
             * atributo no está en fillable.
             */
            $lockedResource->forceFill([
                'department_restricted' => $restricted,
            ])->save();

            /*
             * 10. SINCRONIZAR DEPARTAMENTOS
             *
             * El arreglo puede estar vacío.
             *
             * En ese caso syncWithPivotValues
             * elimina las asociaciones anteriores.
             */
            $lockedResource
                ->eligibleDepartments()
                ->syncWithPivotValues(
                    $departmentIds,
                    [
                        'assigned_by_user_id' => $actor->id,
                    ]
                );

            /*
             * 11. AUDITORÍA
             */
            $this->audit->resourceUpdated(
                $actor,
                $lockedResource,
                [
                    'department_eligibility' => [
                        'previous' => [
                            'department_restricted' =>
                                $previousRestricted,
                            'department_ids' =>
                                $previousIds,
                        ],
                        'new' => [
                            'department_restricted' =>
                                $restricted,
                            'department_ids' =>
                                $departmentIds,
                        ],
                    ],
                ],
                $request->ip()
            );

            return [
                'resource' => $lockedResource,
                'department_ids' => $departmentIds,
                'changed' => true,
            ];
        }, 3);

        /*
         * 12. RESPUESTA HTTP
         */
        return response()->json([
            'message' => $result['changed']
                ? 'Elegibilidad departamental actualizada correctamente.'
                : 'La configuración ya estaba actualizada.',
            'data' => [
                'resource_id' => $result['resource']->id,
                'department_restricted' =>
                    (bool) $result['resource']->department_restricted,
                'department_ids' =>
                    $result['department_ids'],
                'changed' => $result['changed'],
            ],
        ]);
    }
}
