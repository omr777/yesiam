<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UserController;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LeaverBeforeGrantConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | FASE 18 - LEAVER ANTES DE GRANT
    |--------------------------------------------------------------------------
    |
    | Requiere MySQL yesiam_test con
    | las migraciones ya aplicadas.
    |
    | No utiliza RefreshDatabase
    | ni DatabaseMigrations.
    |
    */

    public function test_disabled_identity_cannot_receive_concurrent_grant(): void
    {
        /*
         * 1. VERIFICAR LA BASE REAL
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
            'Seguridad: esta prueba solo utiliza yesiam_test.'
        );

        /*
         * 2. CONFIGURAR SESIONES
         *
         * Leaver exige sesiones en MySQL.
         * Restauraremos el valor original
         * al finalizar.
         */
        $originalSessionDriver = config(
            'session.driver'
        );

        config([
            'session.driver' => 'database',
        ]);

        $admin = null;
        $employee = null;
        $resource = null;
        $child = null;

        try {
            /*
             * 3. PREPARAR RBAC
             *
             * Usamos los roles existentes.
             * No ejecutamos migraciones.
             */
            if (! Role::query()
                ->where('code', 'iam_admin')
                ->exists()) {

                $this->seed(
                    RbacSeeder::class
                );
            }

            $adminRole = Role::query()
                ->where('code', 'iam_admin')
                ->firstOrFail();

            $employeeRole = Role::query()
                ->where('code', 'employee')
                ->firstOrFail();

            /*
             * 4. CREAR IDENTIDADES
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

            $employee->roles()->attach(
                $employeeRole->id
            );

            /*
             * 5. CREAR RECURSO
             *
             * Sin restricciones departamentales
             * para aislar Leaver vs Grant.
             */
            $suffix = Str::upper(
                Str::random(12)
            );

            $resource = Resource::create([
                'name' =>
                    'Leaver concurrency ' . $suffix,

                'code' =>
                    'LVRG-' . $suffix,

                'description' =>
                    'Recurso temporal de concurrencia.',

                'is_active' => true,
            ]);

            /*
             * 6. CREAR SOLICITUD APROBADA
             */
            $accessRequest = AccessRequest::create([
                'requester_user_id' =>
                    $employee->id,

                'resource_id' =>
                    $resource->id,

                'justification' =>
                    'Prueba de baja concurrente.',
            ]);

            $accessRequest->forceFill([
                'status' => 'approved',

                'decided_by_user_id' =>
                    $admin->id,

                'decision_reason' =>
                    'Aprobada para la prueba concurrente.',

                'decided_at' => now(),
            ])->save();

            /*
             * 7. LOCALIZAR LA RUTA REAL LEAVER
             *
             * Evitamos suponer su URL.
             */
            $route = Route::getRoutes()
                ->getByAction(
                    UserController::class
                        . '@deactivate'
                );

            $this->assertNotNull(
                $route,
                'No se encontró la ruta Leaver.'
            );

            $path = '/' . ltrim(
                preg_replace(
                    '/\{[^}]+\}/',
                    (string) $employee->id,
                    $route->uri()
                ),
                '/'
            );

            /*
             * 8. ABRIR TRANSACCIÓN PRINCIPAL
             *
             * Leaver ejecutará su transacción
             * interna, pero los cambios no
             * quedarán confirmados hasta
             * nuestro commit externo.
             */
            $db->beginTransaction();

            /*
             * 9. EJECUTAR LEAVER REAL
             */
            $this->actingAs($admin, 'web')
                ->json(
                    $route->methods()[0],
                    $path
                )
                ->assertOk()
                ->assertJsonPath(
                    'data.status',
                    'disabled'
                )
                ->assertJsonPath(
                    'data.revoked_access_grants_count',
                    0
                );

            /*
             * 10. COMPROBAR ESTADO LOCAL
             *
             * La conexión principal observa
             * el cambio todavía no confirmado.
             */
            $this->assertDatabaseHas(
                'users',
                [
                    'id' => $employee->id,
                    'status' => 'disabled',
                ]
            );

            /*
             * 11. PREPARAR PROCESO SECUNDARIO
             *
             * Intentará conceder acceso
             * mediante el endpoint real.
             */
            $childScript = <<<'WORKER'
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
        "Base no autorizada.\n"
    );

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

\Illuminate\Support\Facades\Auth::shouldUse(
    'web'
);

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

$response = $kernel->handle(
    $request
);

echo "YESIAM_STATUS:"
    . $response->getStatusCode()
    . "\n";

echo "YESIAM_BODY:"
    . $response->getContent()
    . "\n";

$kernel->terminate(
    $request,
    $response
);
WORKER;

            /*
             * 12. INICIAR SEGUNDO PROCESO
             */
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
                    'SESSION_DRIVER' => 'database',
                    'APP_DEBUG' => 'false',
                ]
            );

            $child->setTimeout(30);
            $child->start();

            /*
             * 13. ESPERAR PREPARACIÓN
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
                usleep(50000);
            }

            $this->assertStringContainsString(
                'YESIAM_READY',
                $child->getOutput(),
                'No inició Grant: '
                    . $child->getErrorOutput()
            );

            /*
             * 14. COMPROBAR SOLAPAMIENTO
             *
             * Leaver conserva el bloqueo
             * sobre el usuario.
             *
             * Grant debería esperar
             * antes de confirmar una concesión.
             */
            usleep(300000);

            $this->assertTrue(
                $child->isRunning(),
                'Grant terminó antes de confirmar Leaver: '
                    . $child->getOutput()
                    . ' '
                    . $child->getErrorOutput()
            );

            /*
             * 15. CONFIRMAR LEAVER
             *
             * Se libera el bloqueo del usuario.
             */
            $db->commit();

            /*
             * 16. ESPERAR RESULTADO DE GRANT
             */
            $child->wait();

            $this->assertTrue(
                $child->isSuccessful(),
                'Falló proceso Grant: '
                    . $child->getErrorOutput()
                    . ' '
                    . $child->getOutput()
            );

            /*
             * Grant debe rechazar la concesión
             * debido al usuario desactivado.
             */
            $this->assertStringContainsString(
                'YESIAM_STATUS:403',
                $child->getOutput()
            );

            /*
             * 17. COMPROBAR ESTADO FINAL
             */
            $this->assertDatabaseHas(
                'users',
                [
                    'id' => $employee->id,
                    'status' => 'disabled',
                ]
            );

            $this->assertDatabaseHas(
                'resources',
                [
                    'id' => $resource->id,
                    'is_active' => true,
                ]
            );

            /*
             * 18. NO DEBE EXISTIR CONCESIÓN
             *
             * Incluye cualquier estado,
             * no solamente concesiones activas.
             */
            $this->assertSame(
                0,
                DB::table('access_grants')
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->count()
            );

            /*
             * 19. COMPROBAR AUDITORÍA
             *
             * Leaver registra una baja.
             * Grant rechazado no registra
             * una concesión exitosa.
             */
            $this->assertSame(
                1,
                DB::table('audit_logs')
                    ->where(
                        'actor_user_id',
                        $admin->id
                    )
                    ->count()
            );

        } finally {

            /*
             * 20. LIMPIEZA GARANTIZADA
             */
            try {
                /*
                 * Detener proceso secundario
                 * antes de eliminar registros.
                 */
                if (
                    $child instanceof Process
                    && $child->isRunning()
                ) {
                    $child->stop(2);
                }

                /*
                 * Cerrar transacciones abiertas.
                 */
                while (
                    $db->transactionLevel() > 0
                ) {
                    $db->rollBack();
                }

                /*
                 * Eliminar auditorías temporales.
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
                 * Eliminar concesiones,
                 * solicitudes y recurso.
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

                    DB::table('resources')
                        ->where(
                            'id',
                            $resource->id
                        )
                        ->delete();
                }

                /*
                 * Eliminar relaciones y usuarios.
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

                    DB::table('sessions')
                        ->whereIn(
                            'user_id',
                            $userIds
                        )
                        ->delete();

                    DB::table('personal_access_tokens')
                        ->whereIn(
                            'tokenable_id',
                            $userIds
                        )
                        ->where(
                            'tokenable_type',
                            ($employee ?? $admin)->getMorphClass()
                        )
                        ->delete();

                    DB::table('users')
                        ->whereIn(
                            'id',
                            $userIds
                        )
                        ->delete();
                }

            } finally {

                /*
                 * 21. RESTAURAR CONFIGURACIÓN
                 *
                 * Evita afectar otras pruebas
                 * de PHPUnit.
                 */
                config([
                    'session.driver' =>
                        $originalSessionDriver,
                ]);
            }
        }
    }
}
