<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GrantBeforeResourceDeactivationConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | FASE 18.3.3
    | GRANT ANTES DE DESACTIVAR EL RECURSO
    |--------------------------------------------------------------------------
    |
    | No usamos RefreshDatabase.
    |
    | La prueba necesita que un segundo proceso
    | pueda observar transacciones reales de MySQL.
    |
    | Seguridad:
    | únicamente se permite yesiam_test.
    |
    */

    public function test_granted_access_is_revoked_when_resource_is_deactivated(): void
    {
        $db = DB::connection();

        /*
         * 1. VERIFICAR BASE DE DATOS REAL
         */
        $this->assertSame(
            'mysql',
            $db->getDriverName()
        );

        $databaseName = $db->selectOne(
            'SELECT DATABASE() AS database_name'
        )->database_name;

        $this->assertSame(
            'yesiam_test',
            $databaseName,
            'Esta prueba solo puede ejecutarse en yesiam_test.'
        );

        $admin = null;
        $employee = null;
        $resource = null;
        $accessRequest = null;
        $child = null;

        try {
            /*
             * 2. PREPARAR RBAC
             */
            if (! Role::query()
                ->where('code', 'iam_admin')
                ->exists()) {
                $this->seed(RbacSeeder::class);
            }

            $adminRole = Role::query()
                ->where('code', 'iam_admin')
                ->firstOrFail();

            /*
             * 3. CREAR IDENTIDADES TEMPORALES
             */
            $admin = User::factory()->create([
                'status' => 'active',
            ]);

            $admin->roles()->attach(
                $adminRole->id
            );

            $employee = User::factory()->create([
                'status' => 'active',
            ]);

            /*
             * 4. CREAR RECURSO TEMPORAL
             */
            $code = 'C18-' . Str::upper(
                Str::random(12)
            );

            $resource = Resource::create([
                'name' => 'Concurrencia ' . $code,
                'code' => $code,
                'description' =>
                    'Prueba de Grant antes de desactivación.',
                'is_active' => true,
            ]);

            /*
             * 5. CREAR SOLICITUD APROBADA
             */
            $accessRequest = AccessRequest::create([
                'requester_user_id' =>
                    $employee->id,
                'resource_id' =>
                    $resource->id,
                'justification' =>
                    'Solicitud de prueba de concurrencia.',
            ]);

            $accessRequest->forceFill([
                'status' => 'approved',
                'decided_by_user_id' => $admin->id,
                'decision_reason' =>
                    'Solicitud aprobada para prueba.',
                'decided_at' => now(),
            ])->save();

            /*
             * 6. ABRIR TRANSACCIÓN PRINCIPAL
             *
             * Mantendremos abierta la transacción
             * después de ejecutar el endpoint Grant.
             *
             * El controlador utiliza una transacción
             * anidada en esta misma conexión.
             */
            $db->beginTransaction();

            /*
             * 7. CONCEDER ACCESO POR EL ENDPOINT REAL
             */
            $grantResponse = $this
                ->actingAs($admin, 'web')
                ->postJson(
                    "/api/access-requests/{$accessRequest->id}/grant"
                );

            $grantResponse->assertCreated();

            $grantId = $grantResponse->json('data.id');

            $this->assertNotNull(
                $grantId,
                'Grant no devolvió el ID de la concesión.'
            );

            /*
             * 8. COMPROBAR CONCESIÓN NO CONFIRMADA
             *
             * Nuestra conexión puede ver su escritura,
             * pero otra sesión no debería poder
             * modificar el mismo recurso todavía.
             */
            $this->assertDatabaseHas(
                'access_grants',
                [
                    'id' => $grantId,
                    'resource_id' => $resource->id,
                    'status' => 'active',
                ]
            );

            $this->assertGreaterThan(
                0,
                $db->transactionLevel()
            );

            /*
             * 9. PREPARAR PROCESO SECUNDARIO
             *
             * Ejecutará el endpoint real de
             * desactivación de recursos.
             */
            $childScript = <<<'PHP'
require getcwd() . '/vendor/autoload.php';

$app = require getcwd() . '/bootstrap/app.php';

$app->make(
    \Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();

$databaseName = $db->selectOne(
    'SELECT DATABASE() AS database_name'
)->database_name;

if (
    $db->getDriverName() !== 'mysql'
    || $databaseName !== 'yesiam_test'
) {
    fwrite(STDERR, "Base de datos no autorizada.\n");
    exit(90);
}

$db->statement(
    'SET SESSION innodb_lock_wait_timeout = 15'
);

$adminId = (int) $argv[1];
$resourceId = (int) $argv[2];

$admin = \App\Models\User::findOrFail(
    $adminId
);

\Illuminate\Support\Facades\Auth::guard('web')
    ->setUser($admin);

\Illuminate\Support\Facades\Auth::shouldUse('web');

$request = \Illuminate\Http\Request::create(
    "/api/resources/{$resourceId}/deactivate",
    'PATCH',
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

$kernel->terminate($request, $response);
PHP;

            $child = new Process(
                [
                    PHP_BINARY,
                    '-r',
                    $childScript,
                    '--',
                    (string) $admin->id,
                    (string) $resource->id,
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
             * 10. ESPERAR A QUE EL PROCESO
             * SECUNDARIO ESTÉ PREPARADO
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
                'El proceso secundario no inició: '
                    . $child->getErrorOutput()
            );

            /*
             * Damos oportunidad al proceso B
             * de intentar adquirir el bloqueo
             * mientras Grant aún no confirma.
             */
            usleep(300000);

            $this->assertTrue(
                $child->isRunning(),
                'La desactivación terminó antes '
                    . 'de confirmar Grant. Salida: '
                    . $child->getOutput()
                    . ' ' . $child->getErrorOutput()
            );

            /*
             * 11. CONFIRMAR GRANT
             *
             * Se liberan los bloqueos que
             * conserva nuestra transacción.
             */
            $db->commit();

            /*
             * 12. COMPLETAR DESACTIVACIÓN
             */
            $child->wait();

            $this->assertTrue(
                $child->isSuccessful(),
                'Error del proceso secundario: '
                    . $child->getErrorOutput()
                    . ' ' . $child->getOutput()
            );

            $this->assertStringContainsString(
                'YESIAM_STATUS:200',
                $child->getOutput()
            );

            /*
             * 13. VERIFICAR ESTADO FINAL
             */
            $this->assertDatabaseHas(
                'resources',
                [
                    'id' => $resource->id,
                    'is_active' => false,
                ]
            );

            $this->assertDatabaseHas(
                'access_grants',
                [
                    'id' => $grantId,
                    'resource_id' => $resource->id,
                    'status' => 'revoked',
                    'revoked_by_user_id' => $admin->id,
                ]
            );

            /*
             * No debe quedar ningún acceso
             * activo para el recurso.
             */
            $this->assertSame(
                0,
                AccessGrant::query()
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->where('status', 'active')
                    ->count()
            );

            /*
             * 14. VERIFICAR AUDITORÍAS
             *
             * Esperamos:
             * - concesión
             * - revocación
             * - desactivación del recurso
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
             * 15. TERMINAR PROCESO SECUNDARIO
             * SI CONTINÚA ACTIVO
             */
            if (
                $child !== null
                && $child->isRunning()
            ) {
                $child->stop(2);
            }

            /*
             * 16. REVERTIR TRANSACCIÓN ABIERTA
             * SI HUBO UNA FALLA
             */
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }

            /*
             * 17. ELIMINAR DATOS DE PRUEBA
             *
             * Solo se eliminan registros
             * asociados a nuestras identidades
             * y al recurso temporal.
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

                DB::table('resources')
                    ->where(
                        'id',
                        $resource->id
                    )
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
                    ->whereIn(
                        'id',
                        $userIds
                    )
                    ->delete();
            }
        }
    }
}
