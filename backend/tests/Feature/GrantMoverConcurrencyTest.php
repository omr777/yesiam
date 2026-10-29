<?php

namespace Tests\Feature;

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
use App\Http\Controllers\Api\UserController;

class GrantMoverConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | FASE 18.3.4 - MOVER ANTES DE GRANT
    |--------------------------------------------------------------------------
    |
    | No usamos RefreshDatabase:
    | necesitamos dos procesos con transacciones
    | independientes sobre registros confirmados.
    |
    | Exclusivamente para yesiam_test.
    |
    */

    public function test_grant_is_rejected_after_concurrent_department_move(): void
    {
        $db = DB::connection();

        // Protección contra ejecución en otra base.
        $this->assertSame('mysql', $db->getDriverName());

        $this->assertSame(
            'yesiam_test',
            $db->selectOne(
                'SELECT DATABASE() AS name'
            )->name
        );

        $finance = null;
        $sales = null;
        $admin = null;
        $employee = null;
        $resource = null;
        $child = null;

        try {
            /*
             * 1. Preparar roles existentes.
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
             * 2. Crear dos departamentos temporales.
             */
            $suffix = Str::upper(Str::random(10));

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
             * 3. Crear administrador y empleado.
             */
            $admin = User::factory()->create([
                'status' => 'active',
                'department_id' => $finance->id,
            ]);

            $admin->roles()->attach($adminRole->id);

            $employee = User::factory()->create([
                'status' => 'active',
                'department_id' => $finance->id,
            ]);

            $employee->roles()->attach(
                $employeeRole->id
            );

            /*
             * 4. Crear recurso restringido.
             *
             * Finanzas puede acceder.
             * Ventas no puede acceder.
             */
            $resource = Resource::create([
                'name' => 'ERP financiero ' . $suffix,
                'code' => 'ERP-' . $suffix,
                'description' =>
                    'Prueba temporal de concurrencia.',
                'is_active' => true,
            ]);

            $resource->forceFill([
                'department_restricted' => true,
            ])->save();

            $resource->eligibleDepartments()->attach(
                $finance->id,
                [
                    'assigned_by_user_id' =>
                        $admin->id,
                ]
            );

            /*
             * 5. Crear solicitud aprobada
             * cuando el empleado aún
             * pertenece a Finanzas.
             */
            $accessRequest = AccessRequest::create([
                'requester_user_id' =>
                    $employee->id,
                'resource_id' =>
                    $resource->id,
                'justification' =>
                    'Acceso necesario para prueba de concurrencia.',
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
             * 6. Abrir transacción principal.
             *
             * El endpoint Mover confirmará
             * solamente su transacción interna.
             * La externa seguirá abierta.
             */
            $db->beginTransaction();

            /*
             * 7. Encontrar la ruta real Mover.
             *
             * Evitamos inventar una URL.
             */
            $route = Route::getRoutes()->getByAction(
                UserController::class
                    . '@changeDepartment'
            );

            $this->assertNotNull(
                $route,
                'No se encontró la ruta de Mover.'
            );

            $uri = preg_replace(
                '/\{[^}]+\}/',
                (string) $employee->id,
                $route->uri()
            );

            /*
             * 8. Ejecutar Mover mediante
             * el controlador real.
             */
            $this->actingAs($admin, 'web')
                ->json(
                    $route->methods()[0],
                    '/' . ltrim($uri, '/'),
                    [
                        'department_id' =>
                            $sales->id,
                    ]
                )
                ->assertOk()
                ->assertJsonPath(
                    'data.revoked_access_grants_count',
                    0
                );

            /*
             * El cambio existe en la conexión
             * principal, pero todavía no
             * está confirmado.
             */
            $this->assertDatabaseHas('users', [
                'id' => $employee->id,
                'department_id' => $sales->id,
            ]);

            /*
             * 9. Iniciar segundo proceso.
             *
             * Intentará Grant sobre la
             * solicitud previamente aprobada.
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
    fwrite(STDERR, "Base no autorizada.\n");
    exit(90);
}

$db->statement(
    'SET SESSION innodb_lock_wait_timeout = 15'
);

$admin = \App\Models\User::findOrFail(
    (int) $argv[1]
);

$requestId = (int) $argv[2];

\Illuminate\Support\Facades\Auth::guard('web')
    ->setUser($admin);

\Illuminate\Support\Facades\Auth::shouldUse('web');

$request = \Illuminate\Http\Request::create(
    "/api/access-requests/{$requestId}/grant",
    'POST',
    [],
    [],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
    ]
);

$kernel = $app->make(
    \Illuminate\Contracts\Http\Kernel::class
);

echo "YESIAM_READY\n";
fflush(STDOUT);

$response = $kernel->handle($request);

echo "YESIAM_STATUS:"
    . $response->getStatusCode()
    . "\n";

echo "YESIAM_BODY:"
    . $response->getContent()
    . "\n";

$kernel->terminate($request, $response);
PHP;

            $child = new Process(
                [
                    PHP_BINARY,
                    '-r',
                    $childScript,
                    '--',
                    (string) $admin->id,
                    (string) $accessRequest->id,
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
             * 10. Esperar a que el proceso
             * secundario esté preparado.
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
                'Falló el inicio: '
                    . $child->getErrorOutput()
            );

            /*
             * Dar oportunidad al proceso
             * secundario de intentar Grant.
             *
             * Esta espera no demuestra por
             * sí sola qué bloqueo adquirió.
             */
            usleep(300000);

            $this->assertTrue(
                $child->isRunning(),
                'Grant terminó antes de confirmar Mover. '
                    . $child->getOutput()
                    . ' ' . $child->getErrorOutput()
            );

            /*
             * 11. Confirmar el movimiento.
             *
             * Ahora Grant podrá continuar
             * y evaluar el departamento
             * actualizado del empleado.
             */
            $db->commit();

            /*
             * 12. Obtener resultado del
             * proceso Grant.
             */
            $child->wait();

            $this->assertTrue(
                $child->isSuccessful(),
                $child->getErrorOutput()
                    . ' ' . $child->getOutput()
            );

            /*
             * La comprobación de elegibilidad
             * debe rechazar la concesión.
             */
            $this->assertStringContainsString(
                'YESIAM_STATUS:422',
                $child->getOutput()
            );

            $this->assertStringContainsString(
                'elegibilidad',
                $child->getOutput()
            );

            /*
             * 13. Validar estado final.
             */
            $this->assertDatabaseHas('users', [
                'id' => $employee->id,
                'department_id' => $sales->id,
            ]);

            $this->assertDatabaseHas('resources', [
                'id' => $resource->id,
                'is_active' => true,
            ]);

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
             * El intento fallido de Grant
             * no debe crear ninguna concesión.
             */
            $this->assertSame(
                0,
                AccessGrant::query()
                    ->where(
                        'access_request_id',
                        $accessRequest->id
                    )
                    ->count()
            );

        } finally {
            /*
             * 14. Limpiar el proceso y
             * las transacciones pendientes.
             */
            if (
                $child !== null
                && $child->isRunning()
            ) {
                $child->stop(2);
            }

            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            /*
             * 15. Eliminar solamente los
             * datos temporales de esta prueba.
             */
            if ($admin !== null) {
                DB::table('audit_logs')
                    ->where(
                        'actor_user_id',
                        $admin->id
                    )
                    ->delete();
            }

            if ($resource !== null) {
                DB::table(
                    'resource_department_eligibilities'
                )
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->delete();

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

                /*
                 * CORRECCIÓN:
                 *
                 * DB::table() utiliza Query Builder.
                 * Debemos filtrar por la columna
                 * primaria explícitamente.
                 */
                DB::table('resources')
                    ->where('id', $resource->id)
                    ->delete();
            }

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
                    ->whereIn('id', $userIds)
                    ->delete();
            }

            $departmentIds = array_values(
                array_filter([
                    $finance?->id,
                    $sales?->id,
                ])
            );

            if ($departmentIds !== []) {
                DB::table('departments')
                    ->whereIn('id', $departmentIds)
                    ->delete();
            }
        }
    }
}
