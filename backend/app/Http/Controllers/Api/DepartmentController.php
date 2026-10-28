<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\Department;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | DEPENDENCIAS
    |--------------------------------------------------------------------------
    */

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
        Gate::authorize(
            'viewAny',
            Department::class
        );

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
        Gate::authorize(
            'create',
            Department::class
        );

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
            /*
             * 1. CREAR DEPARTAMENTO
             */
            $department = Department::create(
                $validated
            );

            /*
             * 2. REGISTRAR AUDITORÍA
             */
            $this->audit->departmentCreated(
                $request->user(),
                $department,
                $request->ip()
            );

            return $department;
        });

        return response()->json([
            'message' =>
                'Departamento creado correctamente.',
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
        Gate::authorize(
            'update',
            $department
        );

        if (! $department->is_active) {
            return response()->json([
                'message' =>
                    'No se puede modificar un departamento inactivo.',
            ], 409);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique(
                    'departments',
                    'name'
                )->ignore($department->id),
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(
                    'departments',
                    'code'
                )->ignore($department->id),
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $department,
            $validated
        ) {
            /*
             * 1. CONSERVAR VALORES ANTERIORES
             */
            $before = $department->only([
                'name',
                'code',
            ]);

            /*
             * 2. APLICAR CAMBIOS EN MEMORIA
             */
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

            /*
             * 3. GUARDAR Y AUDITAR
             *
             * Solo si hubo cambios reales.
             */
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
            'message' =>
                'Departamento actualizado correctamente.',
            'data' => $department->fresh(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DESACTIVAR DEPARTAMENTO
    |--------------------------------------------------------------------------
    |
    | FASE 17.7.3
    |
    | Reglas:
    |
    | 1. Verificar autorización.
    | 2. Bloquear el departamento.
    | 3. Revalidar autorización y estado.
    | 4. Localizar concesiones activas
    |    dependientes del departamento.
    | 5. Revocar concesiones incompatibles.
    | 6. Auditar cada revocación.
    | 7. Desactivar el departamento.
    | 8. Auditar la desactivación.
    |
    | Todas las escrituras se realizan
    | dentro de una transacción.
    |
    */

    public function deactivate(
        Request $request,
        Department $department
    ): JsonResponse {
        /*
         * 1. AUTORIZACIÓN INICIAL
         */
        Gate::authorize(
            'deactivate',
            $department
        );

        /*
         * 2. CONSERVAR RESPUESTA ORIGINAL
         *
         * Una desactivación repetida
         * devuelve HTTP 409.
         */
        if (! $department->is_active) {
            return response()->json([
                'message' =>
                    'El departamento ya está inactivo.',
            ], 409);
        }

        $actor = $request->user();
        $ipAddress = $request->ip();

        /*
         * 3. TRANSACCIÓN
         *
         * La desactivación y sus revocaciones
         * deben confirmarse juntas.
         */
        $result = DB::transaction(function () use (
            $department,
            $actor,
            $ipAddress
        ) {
            /*
             * PASO 1: BLOQUEAR DEPARTAMENTO
             *
             * Protegemos el registro durante
             * esta operación administrativa.
             */
            $lockedDepartment = Department::query()
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * PASO 2: REPETIR AUTORIZACIÓN
             *
             * No confiar únicamente
             * en la comprobación inicial.
             */
            Gate::authorize(
                'deactivate',
                $lockedDepartment
            );

            /*
             * PASO 3: REVALIDAR ESTADO
             */
            if (! $lockedDepartment->is_active) {
                abort(
                    409,
                    'El departamento ya está inactivo.'
                );
            }

            /*
             * PASO 4: RECUPERAR CONCESIONES
             * ACTIVAS QUE DEPENDEN DEL
             * DEPARTAMENTO.
             *
             * Condiciones:
             *
             * - La concesión está activa.
             * - El usuario pertenece al
             *   departamento a desactivar.
             * - El recurso tiene restricciones
             *   departamentales.
             *
             * No revocamos automáticamente
             * recursos sin restricciones.
             */
            $activeGrants = AccessGrant::query()
                ->where('status', 'active')
                ->whereHas(
                    'user',
                    function ($query) use (
                        $lockedDepartment
                    ) {
                        $query->where(
                            'department_id',
                            $lockedDepartment->id
                        );
                    }
                )
                ->whereHas(
                    'resource',
                    function ($query) {
                        $query->where(
                            'department_restricted',
                            true
                        );
                    }
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $revokedCount = 0;

            /*
             * PASO 5: REVOCAR CONCESIONES
             *
             * Cada concesión mantiene
             * su registro histórico.
             */
            foreach ($activeGrants as $grant) {
                $grant->forceFill([
                    'status' => 'revoked',

                    'revoked_by_user_id' =>
                        $actor->id,

                    'revoked_at' => now(),

                    'revocation_reason' =>
                        'Revocación automática por desactivación del departamento.',
                ]);

                $grant->save();

                /*
                 * PASO 6: AUDITAR REVOCACIÓN
                 *
                 * Reutilizamos el servicio
                 * de auditoría existente.
                 *
                 * No registramos un acceso
                 * concedido ni alteramos
                 * las solicitudes originales.
                 */
                $this->audit->accessRevoked(
                    $actor,
                    $grant,
                    $ipAddress
                );

                $revokedCount++;
            }

            /*
             * PASO 7: DESACTIVAR DEPARTAMENTO
             */
            $lockedDepartment->forceFill([
                'is_active' => false,
            ])->save();

            /*
             * PASO 8: AUDITAR DESACTIVACIÓN
             *
             * Este evento y las revocaciones
             * forman parte de la misma
             * transacción.
             */
            $this->audit->departmentDeactivated(
                $actor,
                $lockedDepartment,
                $ipAddress
            );

            return [
                'department' => $lockedDepartment,
                'revoked_count' => $revokedCount,
            ];

        }, 3);

        /*
         * 4. RESPUESTA HTTP
         *
         * Conservamos data como objeto
         * de departamento y añadimos
         * el número de accesos revocados
         * en el nivel superior.
         */
        return response()->json([
            'message' =>
                'Departamento desactivado y accesos incompatibles revocados correctamente.',

            'data' => $result['department']->fresh(),

            'revoked_access_grants_count' =>
                $result['revoked_count'],
        ]);
    }
}
