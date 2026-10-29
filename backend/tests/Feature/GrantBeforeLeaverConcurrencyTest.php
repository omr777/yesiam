<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UserController;
use App\Models\AccessGrant;
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

class GrantBeforeLeaverConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | FASE 18.3.6 - GRANT ANTES DE LEAVER
    |--------------------------------------------------------------------------
    |
    | Dos procesos PHP independientes.
    |
    | Grant confirma una concesión mientras
    | Leaver espera para desactivar al usuario.
    |
    | Al terminar:
    | - La identidad debe estar desactivada.
    | - La concesión debe estar revocada.
    | - Deben existir las auditorías correctas.
    |
    | No usamos RefreshDatabase
    | ni DatabaseMigrations.
    |
    | Exclusivamente yesiam_test.
    |
    */

    public function test_granted_access_is_revoked_after_concurrent_leaver(): void
    {
        /*
         * 1. VERIFICAR CONEXIÓN REAL
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
            'Esta prueba solo puede utilizar yesiam_test.'
        );

        /*
         * 2. CONFIGURACIÓN TEMPORAL DE SESIONES
         *
         * PHPUnit normalmente utiliza "array".
         *
         * Sin embargo, el método Leaver exige
         * sesiones persistentes en MySQL.
         *
         * Guardamos la configuración original
         * para restaurarla al terminar.
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
             * 3. VALIDAR CONFIGURACIÓN
             */
            $this->assertSame(
                'database',
                config('session.driver')
            );

            /*
             * 4. PREPARAR RBAC
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
             * 5. CREAR ADMINISTRADOR
             */
            $admin = User::factory()->create([
                'status' => 'active',
            ]);

            $admin->roles()->attach(
                $adminRole->id
            );

            /*
             * 6. CREAR EMPLEADO
             */
            $employee = User::factory()->create([
                'status' => 'active',
            ]);

            $employee->roles()->attach(
                $employeeRole->id
            );

            /*
             * 7. CREAR RECURSO SIN RESTRICCIÓN
             *
             * Aislamos Grant vs. Leaver.
             */
            $suffix = Str::upper(
                Str::random(12)
            );

            $resource = Resource::create([
                'name' => 'VPN Leaver ' . $suffix,
                'code' => 'LVR-' . $suffix,
                'description' =>
                    'Recurso temporal de concurrencia.',
                'is_active' => true,
            ]);

            /*
             * 8. CREAR SOLICITUD APROBADA
             */
            $accessRequest = AccessRequest::create([
                'requester_user_id' =>
                    $employee->id,

                'resource_id' =>
                    $resource->id,

                'justification' =>
                    'Acceso temporal para prueba de Leaver.',
            ]);

            $accessRequest->forceFill([
                'status' => 'approved',

                'decided_by_user_id' =>
                    $admin->id,

                'decision_reason' =>
                    'Solicitud aprobada para concurrencia.',

                'decided_at' => now(),
            ])->save();

            /*
             * 9. OBTENER RUTA REAL DE LEAVER
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

            $leaverPath = '/' . ltrim(
                preg_replace(
                    '/\{[^}]+\}/',
                    (string) $employee->id,
                    $route->uri()
                ),
                '/'
            );

            $leaverMethod = $route->methods()[0];

            /*
             * 10. INICIAR TRANSACCIÓN PRINCIPAL
             *
             * Grant utilizará una transacción
             * interna sin confirmar todavía
             * la transacción exterior.
             */
            $db->beginTransaction();

            /*
             * 11. EJECUTAR GRANT REAL
             */
            $response = $this
                ->actingAs($admin, 'web')
                ->postJson(
                    "/api/access-requests/{$accessRequest->id}/grant"
                );

            $response->assertCreated();

            $grantId = $response->json('data.id');

            $this->assertNotNull(
                $grantId,
                'Grant no devolvió el ID.'
            );

            /*
             * 12. VALIDAR CONCESIÓN LOCAL
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
             * 13. PROCESO SECUNDARIO
             *
             * Ejecuta Leaver mediante
             * el kernel HTTP de Laravel.
             */
            $childScript = <<<'PHP'
require getcwd() . '/vendor/autoload.php';

$app = require getcwd() . '/bootstrap/app.php';

$app->make(
    \Illuminate\Contracts\Console\Kernel::class
)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();

/*
 * Protección de base de datos.
 */
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

/*
 * Leaver requiere sesiones persistentes.
 */
if (config('session.driver') !== 'database') {
    fwrite(
        STDERR,
        "Session driver incorrecto.\n"
    );

    exit(91);
}

$db->statement(
    'SET SESSION innodb_lock_wait_timeout = 15'
);

$adminId = (int) $argv[1];
$leaverPath = $argv[2];
$leaverMethod = $argv[3];

$admin = \App\Models\User::findOrFail(
    $adminId
);

/*
 * Autenticación del administrador.
 */
\Illuminate\Support\Facades\Auth::guard('web')
    ->setUser($admin);

\Illuminate\Support\Facades\Auth::shouldUse(
    'web'
);

/*
 * Crear solicitud HTTP interna.
 */
$request = \Illuminate\Http\Request::create(
    $leaverPath,
    $leaverMethod,
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

/*
 * Señal de preparación.
 */
echo "YESIAM_READY\n";
fflush(STDOUT);

/*
 * Ejecutar desactivación real.
 */
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
             * 14. INICIAR PROCESO LEAVER
             *
             * Configuración independiente.
             *
             * SESSION_DRIVER=database garantiza
             * que el proceso hijo use sesiones
             * persistentes.
             */
            $child = new Process(
                [
                    PHP_BINARY,
                    '-r',
                    $childScript,
                    '--',
                    (string) $admin->id,
                    $leaverPath,
                    $leaverMethod,
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
             * 15. ESPERAR PREPARACIÓN
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
                'Leaver no inició: '
                . $child->getErrorOutput()
            );

            /*
             * 16. VERIFICAR SOLAPAMIENTO
             *
             * Grant conserva el bloqueo
             * sobre la identidad.
             *
             * Leaver debe esperar antes
             * de modificarla.
             *
             * La breve espera confirma
             * solapamiento, no identifica
             * directamente el bloqueo InnoDB.
             */
            usleep(300000);

            $this->assertTrue(
                $child->isRunning(),
                'Leaver terminó antes del commit. '
                . $child->getOutput()
                . ' '
                . $child->getErrorOutput()
            );

            /*
             * 17. CONFIRMAR GRANT
             */
            $db->commit();

            /*
             * 18. ESPERAR LEAVER
             */
            $child->wait();

            $this->assertTrue(
                $child->isSuccessful(),
                'Falló Leaver: '
                . $child->getErrorOutput()
                . ' '
                . $child->getOutput()
            );

            /*
             * Leaver debe responder 200.
             */
            $this->assertStringContainsString(
                'YESIAM_STATUS:200',
                $child->getOutput()
            );

            /*
             * Debe revocar una concesión.
             */
            $this->assertStringContainsString(
                'YESIAM_REVOKED:1',
                $child->getOutput()
            );

            /*
             * 19. VALIDAR IDENTIDAD
             */
            $this->assertDatabaseHas(
                'users',
                [
                    'id' => $employee->id,
                    'status' => 'disabled',
                ]
            );

            /*
             * 20. VALIDAR CONCESIÓN
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
             * 21. NO DEBEN QUEDAR ACCESOS ACTIVOS
             */
            $this->assertSame(
                0,
                AccessGrant::query()
                    ->where(
                        'user_id',
                        $employee->id
                    )
                    ->where('status', 'active')
                    ->count()
            );

            /*
             * 22. VERIFICAR AUDITORÍAS
             *
             * Esperamos:
             * - Concesión
             * - Revocación
             * - Desactivación
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

            /*
             * 23. VALIDAR SESIONES
             */
            $this->assertSame(
                0,
                DB::table('sessions')
                    ->where(
                        'user_id',
                        $employee->id
                    )
                    ->count()
            );

            /*
             * 24. VALIDAR TOKENS SANCTUM
             */
            $this->assertSame(
                0,
                DB::table('personal_access_tokens')
                    ->where(
                        'tokenable_type',
                        $employee->getMorphClass()
                    )
                    ->where(
                        'tokenable_id',
                        $employee->id
                    )
                    ->count()
            );

        } finally {

            /*
             * 25. LIMPIEZA DE LA PRUEBA
             *
             * Restauramos la configuración
             * original aunque alguna operación
             * o aserción falle.
             */
            try {
                /*
                 * Detener proceso secundario.
                 */
                if (
                    $child !== null
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
                 * Eliminar registros relacionados
                 * con el recurso temporal.
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
                 * Limpiar usuarios y sus relaciones.
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
                            $employee->getMorphClass()
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
                 * 26. RESTAURAR SESSION DRIVER
                 *
                 * Esta es la corrección principal.
                 *
                 * Si PHPUnit utilizaba "array",
                 * vuelve a utilizar "array".
                 *
                 * Evitamos afectar la siguiente
                 * prueba de la suite.
                 */
                config([
                    'session.driver' =>
                        $originalSessionDriver,
                ]);
            }
        }
    }
}
