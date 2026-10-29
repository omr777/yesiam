<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UserController;
use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GrantBeforeMoverConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | FASE 18.3.5 - GRANT ANTES DE MOVER
    |--------------------------------------------------------------------------
    |
    | Se utilizan dos procesos PHP independientes.
    |
    | El proceso principal concede acceso dentro
    | de una transacción que mantiene abierta.
    |
    | El proceso secundario intenta mover al
    | empleado a un departamento no elegible.
    |
    | No usamos RefreshDatabase ni
    | DatabaseMigrations.
    |
    | Exclusivamente para yesiam_test.
    |
    */

    public function test_granted_access_is_revoked_after_concurrent_department_move(): void
    {
        /*
         * 1. VERIFICAR BASE REAL
         */
        $db = DB::connection();

        $this->assertSame(
            'mysql',
            $db->getDriverName()
        );

        $this->assertSame(
            'yesiam_test',
            $db->selectOne(
                'SELECT DATABASE() AS name'
            )->name,
            'Esta prueba solo puede ejecutarse en yesiam_test.'
        );

        $finance = null;
        $sales = null;
        $admin = null;
        $employee = null;
        $resource = null;
        $child = null;

        try {
            /*
             * 2. VERIFICAR RBAC
             */
            if (! Role::query()
                ->where('code', 'iam_admin')
                ->exists()) {
                $this->seed(RbacSeeder::class);
            }

            $adminRole = Role::query()
                ->where('code', 'iam_admin')
                ->firstOrFail();

            $employeeRole = Role::query()
                ->where('code', 'employee')
                ->firstOrFail();

            /*
             * 3. CREAR DEPARTAMENTOS TEMPORALES
             */
            $suffix = Str::upper(
                Str::random(10)
            );

            $finance = Department::create([
                'name' => 'Finanzas ' . $suffix,
                'code' => 'FIN-' . $suffix,
                'is_active' => true,
            ]);

            $sales = Department::create([
                'name' => 'Ventas ' . $suffix,
                'code' => 'SAL-' . $suffix,
                'is_active' => true,
            ]);

            /*
             * 4. CREAR ADMINISTRADOR
             */
            $admin = User::factory()->create([
                'status' => 'active',
                'department_id' => $finance->id,
            ]);

            $admin->roles()->attach(
                $adminRole->id
            );

            /*
             * 5. CREAR EMPLEADO DE FINANZAS
             */
            $employee = User::factory()->create([
                'status' => 'active',
                'department_id' => $finance->id,
            ]);

            $employee->roles()->attach(
                $employeeRole->id
            );

            /*
             * 6. CREAR RECURSO RESTRINGIDO
             */
            $resource = Resource::create([
                'name' => 'ERP financiero ' . $suffix,
                'code' => 'ERP-' . $suffix,
                'description' =>
                    'Recurso temporal de concurrencia.',
                'is_active' => true,
            ]);

            $resource->forceFill([
                'department_restricted' => true,
            ])->save();

            /*
             * El recurso solo admite Finanzas.
             */
            $resource->eligibleDepartments()->attach(
                $finance->id,
                [
                    'assigned_by_user_id' =>
                        $admin->id,
                ]
            );

            /*
             * 7. CREAR SOLICITUD APROBADA
             */
            $accessRequest = AccessRequest::create([
                'requester_user_id' =>
                    $employee->id,
                'resource_id' =>
                    $resource->id,
                'justification' =>
                    'Acceso requerido para prueba de concurrencia.',
            ]);

            $accessRequest->forceFill([
                'status' => 'approved',
                'decided_by_user_id' =>
                    $admin->id,
                'decision_reason' =>
                    'Solicitud aprobada para la prueba.',
                'decided_at' => now(),
            ])->save();

            /*
             * 8. IDENTIFICAR RUTA REAL DE MOVER
             *
             * Evitamos suponer la URL.
             */
            $moveRoute = Route::getRoutes()
                ->getByAction(
                    UserController::class
                        . '@changeDepartment'
                );

            $this->assertNotNull(
                $moveRoute,
                'No se encontró la ruta de cambio de departamento.'
            );

            $movePath = '/' . ltrim(
                preg_replace(
                    '/\{[^}]+\}/',
                    (string) $employee->id,
                    $moveRoute->uri()
                ),
                '/'
            );

            $moveMethod = $moveRoute->methods()[0];

            /*
             * 9. ABRIR TRANSACCIÓN PRINCIPAL
             *
             * El controlador Grant utilizará
             * una transacción anidada.
             *
             * Los cambios seguirán sin confirmar
             * hasta que hagamos commit aquí.
             */
            $db->beginTransaction();

            /*
             * 10. EJECUTAR GRANT REAL
             */
            $grantResponse = $this
                ->actingAs($admin, 'web')
                ->postJson(
                    "/api/access-requests/{$accessRequest->id}/grant"
                );

            $grantResponse->assertCreated();

            $grantId = $grantResponse->json(
                'data.id'
            );

            $this->assertNotNull(
                $grantId,
                'Grant no devolvió el ID de la concesión.'
            );

            /*
             * 11. VERIFICAR CONCESIÓN LOCAL
             */
            $this->assertDatabaseHas(
                'access_grants',
                [
                    'id' => $grantId,
                    'user_id' => $employee->id,
                    'resource_id' => $resource->id,
                    'status' => 'active',
                ]
            );

            $this->assertGreaterThan(
                0,
                $db->transactionLevel()
            );

            /*
             * 12. PREPARAR PROCESO SECUNDARIO
             *
             * Ejecutará Mover mediante el
             * kernel HTTP real de Laravel.
             */
            $childScript = <<<'PHP'
require getcwd() . '/vendor/autoload.php';

$app = require getcwd() . '/bootstrap/app.php';

$app->make(
    \Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();

if (
    $db->getDriverName() !== 'mysql'
    || $db->selectOne(
        'SELECT DATABASE() AS name'
    )->name !== 'yesiam_test'
) {
    fwrite(
        STDERR,
        "Base de datos no autorizada.\n"
    );

    exit(90);
}

$db->statement(
    'SET SESSION innodb_lock_wait_timeout = 15'
);

$adminId = (int) $argv[1];
$salesId = (int) $argv[2];
$movePath = $argv[3];
$moveMethod = $argv[4];

$admin = \App\Models\User::findOrFail(
    $adminId
);

\Illuminate\Support\Facades\Auth::guard('web')
    ->setUser($admin);

\Illuminate\Support\Facades\Auth::shouldUse(
    'web'
);

$request = \Illuminate\Http\Request::create(
    $movePath,
    $moveMethod,
    [],
    [],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ],
    json_encode(
        [
            'department_id' => $salesId,
        ],
        JSON_THROW_ON_ERROR
    )
);

$kernel = $app->make(
    \Illuminate\Contracts\Http\Kernel::class
);

echo "YESIAM_READY\n";
fflush(STDOUT);

$response = $kernel->handle(
    $request
);

echo "YESIAM_STATUS:"
    . $response->getStatusCode()
    . "\n";

$body = json_decode(
    $response->getContent(),
    true
);

echo "YESIAM_REVOKED:"
    . (
        $body['data']['revoked_access_grants_count']
        ?? 'missing'
    )
    . "\n";

echo "YESIAM_BODY:"
    . $response->getContent()
    . "\n";

$kernel->terminate(
    $request,
    $response
);
PHP;

            /*
             * 13. INICIAR PROCESO MOVER
             */
            $child = new Process(
                [
                    PHP_BINARY,
                    '-r',
                    $childScript,
                    '--',
                    (string) $admin->id,
                    (string) $sales->id,
                    $movePath,
                    $moveMethod,
                ],
                base_path(),
                [
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => 'yesiam_test',
                    'APP_DEBUG' => 'false',
                ]
            );

            $child->setTimeout(30);
            $child->start();

            /*
             * 14. ESPERAR PREPARACIÓN
             */
            $deadline = microtime(true) + 10;

            while (
                ! str_contains(
                    $child->getOutput(),
                    'YESIAM_READY'
                )
                && $child->isRunning()
                && microtime(true) < $deadline
            ) {
                usleep(100000);
            }

            $this->assertStringContainsString(
                'YESIAM_READY',
                $child->getOutput(),
                'No inició el proceso Mover: '
                    . $child->getErrorOutput()
            );

            /*
             * 15. COMPROBAR SOLAPAMIENTO
             *
             * Grant conserva el bloqueo
             * del usuario mientras su
             * transacción está abierta.
             *
             * Damos oportunidad a Mover
             * de intentar adquirirlo.
             *
             * La espera no constituye una
             * inspección directa de los
             * bloqueos internos de InnoDB.
             */
            usleep(300000);

            $this->assertTrue(
                $child->isRunning(),
                'Mover terminó antes del commit de Grant. '
                    . $child->getOutput()
                    . ' ' . $child->getErrorOutput()
            );

            /*
             * 16. CONFIRMAR GRANT
             *
             * Se liberan sus bloqueos.
             */
            $db->commit();

            /*
             * 17. ESPERAR RESULTADO DE MOVER
             */
            $child->wait();

            $this->assertTrue(
                $child->isSuccessful(),
                'Falló el proceso Mover: '
                    . $child->getErrorOutput()
                    . ' ' . $child->getOutput()
            );

            $this->assertStringContainsString(
                'YESIAM_STATUS:200',
                $child->getOutput()
            );

            /*
             * Mover debe revocar exactamente
             * la concesión del ERP financiero.
             */
            $this->assertStringContainsString(
                'YESIAM_REVOKED:1',
                $child->getOutput()
            );

            /*
             * 18. VERIFICAR DEPARTAMENTO FINAL
             */
            $this->assertDatabaseHas(
                'users',
                [
                    'id' => $employee->id,
                    'department_id' => $sales->id,
                    'status' => 'active',
                ]
            );

            /*
             * 19. VERIFICAR CONCESIÓN REVOCADA
             */
            $this->assertDatabaseHas(
                'access_grants',
                [
                    'id' => $grantId,
                    'user_id' => $employee->id,
                    'resource_id' => $resource->id,
                    'status' => 'revoked',
                    'revoked_by_user_id' => $admin->id,
                ]
            );

            /*
             * 20. NO DEBEN QUEDAR
             * CONCESIONES ACTIVAS INCOMPATIBLES
             */
            $this->assertSame(
                0,
                AccessGrant::query()
                    ->where(
                        'user_id',
                        $employee->id
                    )
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->where('status', 'active')
                    ->count()
            );

            /*
             * 21. VERIFICAR AUDITORÍAS
             *
             * Esperamos:
             *
             * - Acceso concedido.
             * - Acceso revocado.
             * - Identidad trasladada.
             */
            $this->assertSame(
                3,
                DB::table('audit_logs')
                    ->where(
                        'actor_user_id',
                        $admin->id
                    )
                    ->count()
            );

        } finally {

            /*
             * 22. DETENER PROCESO SI CONTINÚA
             */
            if (
                $child !== null
                && $child->isRunning()
            ) {
                $child->stop(2);
            }

            /*
             * 23. CERRAR TRANSACCIONES ABIERTAS
             */
            while (
                $db->transactionLevel() > 0
            ) {
                $db->rollBack();
            }

            /*
             * 24. LIMPIAR AUDITORÍAS TEMPORALES
             */
            if ($admin !== null) {
                DB::table('audit_logs')
                    ->where(
                        'actor_user_id',
                        $admin->id
                    )
                    ->delete();
            }

            /*
             * 25. LIMPIAR RECURSO Y CONCESIONES
             */
            if ($resource !== null) {
                DB::table('access_grants')
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->delete();

                DB::table('access_requests')
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->delete();

                DB::table(
                    'resource_department_eligibilities'
                )
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->delete();

                DB::table('resources')
                    ->where(
                        'id',
                        $resource->id
                    )
                    ->delete();
            }

            /*
             * 26. ELIMINAR RELACIONES DE ROLES
             * Y USUARIOS TEMPORALES
             */
            $userIds = array_values(
                array_filter([
                    $admin?->id,
                    $employee?->id,
                ])
            );

            if ($userIds !== []) {
                DB::table('user_roles')
                    ->whereIn(
                        'user_id',
                        $userIds
                    )
                    ->delete();

                DB::table('users')
                    ->whereIn(
                        'id',
                        $userIds
                    )
                    ->delete();
            }

            /*
             * 27. ELIMINAR DEPARTAMENTOS
             */
            $departmentIds = array_values(
                array_filter([
                    $finance?->id,
                    $sales?->id,
                ])
            );

            if ($departmentIds !== []) {
                DB::table('departments')
                    ->whereIn(
                        'id',
                        $departmentIds
                    )
                    ->delete();
            }
        }
    }
}
