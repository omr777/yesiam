<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GrantResourceDeactivationConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | PRUEBA DE INTEGRACIÓN CON DOS PROCESOS
    |--------------------------------------------------------------------------
    |
    | No usamos RefreshDatabase.
    |
    | Necesitamos registros confirmados
    | visibles desde un segundo proceso PHP.
    |
    | Esta prueba solo puede ejecutarse
    | en la base yesiam_test.
    |
    */

    public function test_grant_cannot_survive_concurrent_resource_deactivation(): void
    {
        $connection = DB::connection();

        /*
         * 1. PROTECCIÓN DE BASE DE DATOS
         */
        $this->assertSame(
            'mysql',
            $connection->getDriverName()
        );

        $databaseName = $connection
            ->selectOne(
                'SELECT DATABASE() AS name'
            )->name;

        $this->assertSame(
            'yesiam_test',
            $databaseName,
            'Esta prueba solo puede modificar yesiam_test.'
        );

        /*
         * 2. PREPARAR CONEXIÓN DE OBSERVACIÓN
         *
         * Se utiliza para verificar que
         * el segundo proceso realmente
         * bloqueó la solicitud de acceso.
         */
        $probeName = 'yesiam_phase18_probe';

        config([
            "database.connections.{$probeName}" =>
                config(
                    'database.connections.'
                    . config('database.default')
                ),
        ]);

        DB::purge($probeName);

        $probe = DB::connection($probeName);

        $this->assertSame(
            'yesiam_test',
            $probe->selectOne(
                'SELECT DATABASE() AS name'
            )->name
        );

        $this->assertNotEquals(
            $connection->selectOne(
                'SELECT CONNECTION_ID() AS id'
            )->id,
            $probe->selectOne(
                'SELECT CONNECTION_ID() AS id'
            )->id
        );

        $probe->statement(
            'SET SESSION innodb_lock_wait_timeout = 1'
        );

        $admin = null;
        $employee = null;
        $resource = null;
        $accessRequest = null;
        $child = null;

        try {
            /*
             * 3. PREPARAR RBAC EN LA BASE TEST
             *
             * No se ejecuta migrate:fresh.
             */
            if (! Role::query()
                ->where('code', 'iam_admin')
                ->exists()) {
                $this->seed(RbacSeeder::class);
            }

            $adminRole = Role::query()
                ->where('code', 'iam_admin')
                ->firstOrFail();

            $this->assertTrue(
                $adminRole->permissions()
                    ->where(
                        'permissions.code',
                        'access.grant'
                    )
                    ->exists()
            );

            $this->assertTrue(
                $adminRole->permissions()
                    ->where(
                        'permissions.code',
                        'resources.manage'
                    )
                    ->exists()
            );

            /*
             * 4. CREAR IDENTIDADES AISLADAS
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
             * 5. CREAR RECURSO AISLADO
             */
            $code = 'C18-' . Str::upper(
                Str::random(12)
            );

            $resource = Resource::create([
                'name' => 'Concurrencia ' . $code,
                'code' => $code,
                'description' =>
                    'Recurso temporal para Fase 18.',
                'is_active' => true,
            ]);

            /*
             * 6. PREPARAR SOLICITUD APROBADA
             */
            $accessRequest = AccessRequest::create([
                'requester_user_id' =>
                    $employee->id,
                'resource_id' =>
                    $resource->id,
                'justification' =>
                    'Acceso de prueba para concurrencia.',
            ]);

            $accessRequest->forceFill([
                'status' => 'approved',
                'decided_by_user_id' => $admin->id,
                'decision_reason' =>
                    'Aprobada para prueba de concurrencia.',
                'decided_at' => now(),
            ])->save();

            /*
             * 7. TRANSACCIÓN PRINCIPAL
             *
             * Bloqueamos el recurso antes
             * de iniciar el proceso secundario.
             */
            $connection->beginTransaction();

            $lockedResource = Resource::query()
                ->whereKey($resource->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTrue(
                (bool) $lockedResource->is_active
            );

            /*
             * 8. SEGUNDO PROCESO PHP
             *
             * Ejecutará una petición real
             * a AccessGrantController::store().
             *
             * El proceso verifica por sí mismo
             * que utiliza yesiam_test.
             */
            $childScript = <<<'PHP'
require getcwd() . '/vendor/autoload.php';

$app = require getcwd() . '/bootstrap/app.php';

$app->make(
    \Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

$actualDatabase = \Illuminate\Support\Facades\DB::selectOne(
    'SELECT DATABASE() AS name'
)->name;

if ($actualDatabase !== 'yesiam_test') {
    fwrite(STDERR, "Base no autorizada.\n");
    exit(90);
}

$adminId = (int) $argv[1];
$requestId = (int) $argv[2];

$admin = \App\Models\User::findOrFail($adminId);

\Illuminate\Support\Facades\Auth::guard('web')
    ->setUser($admin);

\Illuminate\Support\Facades\Auth::shouldUse('web');

$httpRequest = \Illuminate\Http\Request::create(
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

$response = $kernel->handle($httpRequest);

echo "YESIAM_STATUS:"
    . $response->getStatusCode()
    . "\n";

$kernel->terminate($httpRequest, $response);
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
             * 9. VERIFICAR INTERCALADO REAL
             *
             * Grant bloquea AccessRequest
             * antes de intentar bloquear Resource.
             *
             * Mientras el proceso principal
             * conserva Resource, intentamos
             * bloquear AccessRequest desde
             * una tercera sesión MySQL.
             *
             * El timeout 1205 demuestra
             * que otra sesión conserva
             * ese bloqueo.
             */
            $requestLockedByChild = false;

            for ($attempt = 0; $attempt < 12; $attempt++) {

                if (! $child->isRunning()) {
                    break;
                }

                $probe->beginTransaction();

                try {
                    $probe->table('access_requests')
                        ->where(
                            'id',
                            $accessRequest->id
                        )
                        ->lockForUpdate()
                        ->first();

                } catch (QueryException $exception) {
                    $mysqlCode = (int) (
                        $exception->errorInfo[1] ?? 0
                    );

                    if ($mysqlCode !== 1205) {
                        throw $exception;
                    }

                    $requestLockedByChild = true;

                } finally {
                    if ($probe->transactionLevel() > 0) {
                        $probe->rollBack();
                    }
                }

                if ($requestLockedByChild) {
                    break;
                }

                usleep(150000);
            }

            $this->assertTrue(
                $requestLockedByChild,
                'No se confirmó que Grant adquiriera el bloqueo de la solicitud. '
                . 'Salida: ' . $child->getOutput()
                . ' ' . $child->getErrorOutput()
            );

            /*
             * 10. DESACTIVAR RECURSO
             *
             * Ejecutamos el endpoint real.
             *
             * La conexión principal conserva
             * el bloqueo del recurso, por lo
             * que la operación puede trabajar
             * sobre ese mismo registro.
             */
            $this->actingAs($admin, 'web')
                ->patchJson(
                    "/api/resources/{$resource->id}/deactivate"
                )
                ->assertOk();

            /*
             * 11. CONFIRMAR DESACTIVACIÓN
             *
             * Liberamos el bloqueo del recurso.
             *
             * El proceso secundario puede
             * continuar con su transacción.
             */
            $connection->commit();

            /*
             * 12. ESPERAR RESULTADO DE GRANT
             */
            $child->wait();

            $this->assertTrue(
                $child->isSuccessful(),
                'El proceso secundario falló: '
                . $child->getErrorOutput()
                . ' ' . $child->getOutput()
            );

            /*
             * Como el recurso ya está inactivo,
             * la autorización interna debe
             * impedir conceder el acceso.
             */
            $this->assertStringContainsString(
                'YESIAM_STATUS:403',
                $child->getOutput()
            );

            /*
             * 13. COMPROBAR ESTADO FINAL
             */
            $this->assertDatabaseHas(
                'resources',
                [
                    'id' => $resource->id,
                    'is_active' => false,
                ]
            );

            $this->assertSame(
                0,
                DB::table('access_grants')
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->where('status', 'active')
                    ->count()
            );

            /*
             * No debe existir auditoría
             * de una concesión exitosa
             * que nunca ocurrió.
             */
            $this->assertSame(
                0,
                DB::table('audit_logs')
                    ->where(
                        'action',
                        'access.granted'
                    )
                    ->where(
                        'entity_id',
                        $accessRequest->id
                    )
                    ->count()
            );

        } finally {

            /*
             * 14. DETENER PROCESO SI QUEDÓ ACTIVO
             */
            if (
                $child !== null
                && $child->isRunning()
            ) {
                $child->stop(2);
            }

            /*
             * 15. CERRAR TRANSACCIONES ABIERTAS
             */
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }

            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            /*
             * 16. LIMPIAR ÚNICAMENTE
             * LOS DATOS DE ESTA PRUEBA
             *
             * No vaciamos tablas ni ejecutamos
             * migraciones destructivas.
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

            DB::disconnect($probeName);
        }
    }
}
