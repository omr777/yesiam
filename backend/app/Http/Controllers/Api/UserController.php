<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessEligibilityService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | SERVICIOS
    |--------------------------------------------------------------------------
    */

    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessEligibilityService $eligibility
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | JOINER - CREAR UNA IDENTIDAD
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

        $user = DB::transaction(function () use (
            $validated,
            $request
        ) {
            $employeeRole = Role::query()
                ->where('code', 'employee')
                ->firstOrFail();

            $user = new User();

            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->department_id = $validated['department_id'];

            /*
             * Las identidades nuevas requieren
             * activación posterior.
             */
            $user->status = 'suspended';

            /*
             * Contraseña aleatoria desconocida
             * para evitar acceso inicial directo.
             */
            $user->password = Hash::make(
                Str::random(64)
            );

            $user->save();

            /*
             * Asignación inicial del rol employee.
             */
            $user->roles()->attach(
                $employeeRole->id
            );

            /*
             * Auditoría de creación.
             */
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
    | MOVER - CAMBIAR DE DEPARTAMENTO
    |--------------------------------------------------------------------------
    |
    | FASE 17.6
    |
    | 1. Verificar autorización.
    | 2. Validar departamento de destino.
    | 3. Bloquear identidad.
    | 4. Bloquear recursos con concesiones activas.
    | 5. Revalidar departamento de destino.
    | 6. Actualizar departamento.
    | 7. Reevaluar concesiones activas.
    | 8. Revocar las que dejan de ser elegibles.
    | 9. Auditar las revocaciones y el traslado.
    |
    | Todo ocurre en una transacción.
    |
    */

    public function changeDepartment(
        Request $request,
        User $user
    ): JsonResponse {
        /*
         * AUTORIZACIÓN INICIAL
         */
        Gate::authorize(
            'changeDepartment',
            $user
        );

        /*
         * Mantener comportamiento existente:
         * no trasladar identidades desactivadas.
         */
        if ($user->status === 'disabled') {
            return response()->json([
                'message' =>
                    'No se puede trasladar una identidad desactivada.',
            ], 409);
        }

        /*
         * VALIDACIÓN INICIAL
         */
        $validated = $request->validate([
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('is_active', true),
            ],
        ]);

        $newDepartmentId = (int) $validated['department_id'];

        $actor = $request->user();
        $ipAddress = $request->ip();

        /*
         * TRANSACCIÓN DE GOBIERNO DE ACCESOS
         */
        $result = DB::transaction(function () use (
            $user,
            $newDepartmentId,
            $actor,
            $ipAddress
        ) {
            /*
             * PASO 1: BLOQUEAR LA IDENTIDAD
             *
             * Evita procesar simultáneamente
             * modificaciones sobre esta identidad
             * que utilicen el mismo protocolo.
             */
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * PASO 2: REPETIR AUTORIZACIÓN
             */
            Gate::authorize(
                'changeDepartment',
                $lockedUser
            );

            if ($lockedUser->status === 'disabled') {
                abort(
                    409,
                    'No se puede trasladar una identidad desactivada.'
                );
            }

            $previousDepartmentId =
                $lockedUser->department_id === null
                    ? null
                    : (int) $lockedUser->department_id;

            /*
             * Evitar movimientos sin cambios.
             */
            if ($previousDepartmentId === $newDepartmentId) {
                abort(
                    409,
                    'El usuario ya pertenece a ese departamento.'
                );
            }

            /*
             * PASO 3: IDENTIFICAR RECURSOS
             * CON CONCESIONES ACTIVAS
             *
             * Consultamos sus IDs antes de
             * adquirir los bloqueos de recursos.
             */
            $resourceIds = $lockedUser
                ->accessGrants()
                ->where('status', 'active')
                ->distinct()
                ->pluck('resource_id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            /*
             * PASO 4: BLOQUEAR RECURSOS
             *
             * Orden estable por ID.
             *
             * Esto ayuda a coordinar cambios
             * de elegibilidad con el movimiento.
             */
            $lockedResources = Resource::query()
                ->whereIn('id', $resourceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /*
             * PASO 5: REVALIDAR DESTINO
             *
             * Un departamento podría haber sido
             * desactivado desde la validación HTTP.
             */
            $destination = Department::query()
                ->whereKey($newDepartmentId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $destination) {
                throw ValidationException::withMessages([
                    'department_id' => [
                        'El departamento de destino ya no está activo.',
                    ],
                ]);
            }

            /*
             * PASO 6: RECUPERAR CONCESIONES
             * ACTIVAS Y BLOQUEARLAS
             *
             * Las revocadas previamente
             * no deben modificarse.
             */
            $activeGrants = $lockedUser
                ->accessGrants()
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /*
             * PASO 7: ACTUALIZAR DEPARTAMENTO
             *
             * El servicio de elegibilidad
             * evaluará el NUEVO departamento.
             */
            $lockedUser->department_id = $newDepartmentId;
            $lockedUser->save();

            $revokedCount = 0;

            /*
             * PASO 8: EVALUAR CADA CONCESIÓN
             */
            foreach ($activeGrants as $grant) {
                $resource = $lockedResources->get(
                    $grant->resource_id
                );

                /*
                 * Si no podemos recuperar
                 * un recurso, no continuamos
                 * con un traslado parcial.
                 */
                if (! $resource) {
                    abort(
                        409,
                        'No fue posible verificar uno de los recursos asignados.'
                    );
                }

                /*
                 * Evaluación centralizada.
                 *
                 * No se revoca simplemente por
                 * haber cambiado de departamento.
                 *
                 * Se revoca únicamente si el
                 * acceso dejó de ser elegible.
                 */
                $decision = $this->eligibility->evaluate(
                    $lockedUser,
                    $resource
                );

                if ($decision['eligible']) {
                    continue;
                }

                /*
                 * PASO 9: REVOCAR ACCESO
                 */
                $grant->forceFill([
                    'status' => 'revoked',
                    'revoked_by_user_id' => $actor->id,
                    'revoked_at' => now(),
                    'revocation_reason' =>
                        'Revocación automática por cambio de departamento o pérdida de elegibilidad.',
                ]);

                $grant->save();

                /*
                 * PASO 10: AUDITAR REVOCACIÓN
                 *
                 * Reutilizamos AuditService.
                 */
                $this->audit->accessRevoked(
                    $actor,
                    $grant,
                    $ipAddress
                );

                $revokedCount++;
            }

            /*
             * PASO 11: AUDITAR MOVIMIENTO
             *
             * Se registra después de las
             * revocaciones exitosas.
             */
            $this->audit->identityMoved(
                $actor,
                $lockedUser,
                $previousDepartmentId,
                $newDepartmentId,
                $ipAddress
            );

            return [
                'user' => $lockedUser,
                'previous_department_id' =>
                    $previousDepartmentId,
                'revoked_count' => $revokedCount,
            ];

        }, 3);

        /*
         * RESPUESTA
         *
         * Conserva los datos anteriores
         * y añade el número de revocaciones.
         */
        return response()->json([
            'message' =>
                'Departamento actualizado correctamente.',
            'data' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'previous_department_id' =>
                    $result['previous_department_id'],
                'department_id' =>
                    $result['user']->department_id,
                'revoked_access_grants_count' =>
                    $result['revoked_count'],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | LEAVER - DESACTIVAR IDENTIDAD
    |--------------------------------------------------------------------------
    |
    | Al desactivar una identidad:
    |
    | 1. Verificar autorización.
    | 2. Bloquear la identidad.
    | 3. Revocar todas sus concesiones activas.
    | 4. Registrar cada revocación en auditoría.
    | 5. Desactivar la identidad.
    | 6. Eliminar sesiones persistidas.
    | 7. Revocar tokens de Sanctum.
    | 8. Registrar la baja.
    |
    | Todo ocurre en una misma transacción.
    |
    */

    public function deactivate(
        Request $request,
        User $user
    ): JsonResponse {
        /*
         * AUTORIZACIÓN INICIAL
         */
        Gate::authorize(
            'deactivate',
            $user
        );

        /*
         * Mantener el comportamiento original:
         * una identidad ya desactivada
         * devuelve HTTP 409.
         */
        if ($user->status === 'disabled') {
            return response()->json([
                'message' =>
                    'La identidad ya está desactivada.',
            ], 409);
        }

        /*
         * El módulo de sesiones debe utilizar
         * almacenamiento persistente en MySQL.
         */
        abort_unless(
            config('session.driver') === 'database',
            503,
            'La revocación de sesiones no está configurada.'
        );

        $actor = $request->user();
        $ipAddress = $request->ip();

        /*
         * TRANSACCIÓN DE DESPROVISIONAMIENTO
         *
         * Si falla una revocación, su auditoría
         * o la desactivación, Laravel revierte
         * los cambios de esta operación.
         */
        $result = DB::transaction(function () use (
            $user,
            $actor,
            $ipAddress
        ) {
            /*
             * PASO 1: BLOQUEAR LA IDENTIDAD
             *
             * Esto coordina la desactivación
             * con las concesiones que siguen
             * el protocolo de bloqueo de YESIAM.
             */
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Repetir autorización después
             * de adquirir el bloqueo.
             */
            Gate::authorize(
                'deactivate',
                $lockedUser
            );

            /*
             * Otra operación podría haber
             * desactivado la identidad mientras
             * esperábamos el bloqueo.
             */
            if ($lockedUser->status === 'disabled') {
                abort(
                    409,
                    'La identidad ya está desactivada.'
                );
            }

            $previousStatus = $lockedUser->status;

            /*
             * PASO 2: RECUPERAR CONCESIONES ACTIVAS
             *
             * Solamente revocamos concesiones
             * cuyo estado es active.
             *
             * Las concesiones ya revocadas
             * permanecen intactas.
             */
            $activeGrants = $lockedUser
                ->accessGrants()
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $revokedCount = 0;

            /*
             * PASO 3: REVOCAR CADA CONCESIÓN
             */
            foreach ($activeGrants as $grant) {
                $grant->forceFill([
                    'status' => 'revoked',
                    'revoked_by_user_id' => $actor->id,
                    'revoked_at' => now(),
                    'revocation_reason' =>
                        'Revocación automática por desactivación de identidad.',
                ]);

                $grant->save();

                /*
                 * Auditoría individual.
                 *
                 * Reutilizamos el método
                 * accessRevoked() existente.
                 */
                $this->audit->accessRevoked(
                    $actor,
                    $grant,
                    $ipAddress
                );

                $revokedCount++;
            }

            /*
             * PASO 4: DESACTIVAR LA IDENTIDAD
             */
            $lockedUser->status = 'disabled';

            $lockedUser->save();

            /*
             * PASO 5: ELIMINAR SESIONES
             */
            DB::table('sessions')
                ->where('user_id', $lockedUser->id)
                ->delete();

            /*
             * PASO 6: ELIMINAR TOKENS SANCTUM
             */
            $lockedUser->tokens()->delete();

            /*
             * PASO 7: AUDITAR LA BAJA
             */
            $this->audit->identityDeactivated(
                $actor,
                $lockedUser,
                $previousStatus,
                $ipAddress
            );

            return [
                'user' => $lockedUser,
                'revoked_count' => $revokedCount,
            ];

        }, 3);

        /*
         * RESPUESTA DE LA API
         *
         * Mantiene los campos anteriores
         * y añade el número de accesos revocados.
         */
        return response()->json([
            'message' =>
                'Identidad desactivada y accesos revocados correctamente.',
            'data' => [
                'id' => $result['user']->id,
                'status' => 'disabled',
                'revoked_access_grants_count' =>
                    $result['revoked_count'],
            ],
        ]);
    }
}
