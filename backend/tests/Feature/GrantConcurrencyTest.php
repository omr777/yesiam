<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GrantConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | FASE 18 - CONCURRENCIA SIN MIGRACIONES DESTRUCTIVAS
    |--------------------------------------------------------------------------
    |
    | No usamos DatabaseMigrations ni RefreshDatabase.
    |
    | Los procesos externos necesitan acceder
    | a registros previamente confirmados.
    |
    | Esta prueba crea y elimina exclusivamente
    | sus propios registros temporales.
    |
    */

    public function test_two_simultaneous_inserts_cannot_create_duplicate_active_access(): void
    {
        /*
         * 1. VERIFICAR LA BASE REAL
         *
         * Comprobamos la conexión activa antes
         * de crear cualquier registro.
         */
        $connection = DB::connection();

        $this->assertSame(
            'mysql',
            $connection->getDriverName()
        );

        $this->assertSame(
            'yesiam_test',
            $connection->selectOne(
                'SELECT DATABASE() AS name'
            )->name,
            'SEGURIDAD: esta prueba solo puede ejecutarse en yesiam_test.'
        );

        /*
         * 2. VERIFICAR ESQUEMA E ÍNDICE
         *
         * No ejecutamos migraciones destructivas.
         */
        $this->assertTrue(
            Schema::hasTable('access_grants'),
            'No existe el esquema de pruebas.'
        );

        $this->assertTrue(
            Schema::hasIndex(
                'access_grants',
                'access_grants_unique_active_user_resource'
            ),
            'Falta el índice UNIQUE de concesiones activas.'
        );

        $admin = null;
        $employee = null;
        $approver = null;
        $resource = null;

        $folder = null;
        $processA = null;
        $processB = null;

        try {
            /*
             * 3. CREAR IDENTIDADES TEMPORALES
             *
             * No necesitamos roles porque
             * probaremos la restricción UNIQUE
             * directamente en MySQL.
             */
            $admin = User::factory()->create([
                'status' => 'active',
            ]);

            $employee = User::factory()->create([
                'status' => 'active',
            ]);

            $approver = User::factory()->create([
                'status' => 'active',
            ]);

            /*
             * 4. CREAR RECURSO ÚNICO
             *
             * El sufijo evita colisiones
             * entre distintas ejecuciones.
             */
            $suffix = Str::upper(
                Str::random(12)
            );

            $resource = Resource::create([
                'name' => 'VPN simultánea ' . $suffix,
                'code' => 'VPN-RACE-' . $suffix,
                'description' =>
                    'Recurso temporal de concurrencia',
                'is_active' => true,
            ]);

            /*
             * 5. CREAR DOS SOLICITUDES APROBADAS
             *
             * Ambas corresponden al mismo
             * empleado y al mismo recurso.
             *
             * Las solicitudes tienen IDs
             * diferentes.
             */
            $firstRequest = $this->makeApprovedRequest(
                $employee,
                $resource,
                $approver
            );

            $secondRequest = $this->makeApprovedRequest(
                $employee,
                $resource,
                $approver
            );

            /*
             * 6. PREPARAR BARRERA DE SINCRONIZACIÓN
             *
             * Los dos procesos esperarán
             * hasta recibir una señal común.
             */
            $folder = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR
                . 'yesiam-race-'
                . bin2hex(random_bytes(8));

            if (! mkdir($folder, 0700)) {
                throw new RuntimeException(
                    'No fue posible crear la carpeta temporal.'
                );
            }

            $gate = $folder
                . DIRECTORY_SEPARATOR
                . 'start.flag';

            $readyA = $folder
                . DIRECTORY_SEPARATOR
                . 'worker-a.ready';

            $readyB = $folder
                . DIRECTORY_SEPARATOR
                . 'worker-b.ready';

            /*
             * 7. CÓDIGO DE LOS TRABAJADORES
             *
             * Cada trabajador utiliza PDO
             * y una conexión independiente.
             *
             * Ambos intentarán insertar
             * una concesión activa para
             * el mismo usuario y recurso.
             */
            $workerCode = <<<'WORKER'
$pdo = null;

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        getenv('YESIAM_HOST'),
        getenv('YESIAM_PORT'),
        getenv('YESIAM_DATABASE'),
        getenv('YESIAM_CHARSET')
    );

    $pdo = new PDO(
        $dsn,
        getenv('YESIAM_USERNAME'),
        getenv('YESIAM_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    /*
     * Verificar la base de datos real
     * utilizada por el trabajador.
     */
    if (
        $pdo->query(
            'SELECT DATABASE()'
        )->fetchColumn() !== 'yesiam_test'
    ) {
        throw new RuntimeException(
            'Conexión de trabajador fuera de yesiam_test.'
        );
    }

    /*
     * Limitar la espera por bloqueos.
     */
    $pdo->exec(
        'SET SESSION innodb_lock_wait_timeout = 10'
    );

    /*
     * Avisar que la conexión está lista.
     */
    if (
        file_put_contents(
            getenv('YESIAM_READY'),
            'ready'
        ) === false
    ) {
        throw new RuntimeException(
            'No se pudo crear la señal de preparación.'
        );
    }

    /*
     * Esperar la señal de inicio.
     */
    $deadline = microtime(true) + 15;

    while (
        ! is_file(
            getenv('YESIAM_GATE')
        )
    ) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException(
                'Se agotó el tiempo de espera.'
            );
        }

        usleep(20000);
    }

    /*
     * Ejecutar inserción independiente.
     */
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO access_grants '
        . '(access_request_id, user_id, resource_id, status, '
        . 'granted_by_user_id, granted_at, created_at, updated_at) '
        . "VALUES (?, ?, ?, 'active', ?, NOW(), NOW(), NOW())"
    );

    $statement->execute([
        (int) getenv('YESIAM_REQUEST_ID'),
        (int) getenv('YESIAM_EMPLOYEE_ID'),
        (int) getenv('YESIAM_RESOURCE_ID'),
        (int) getenv('YESIAM_ADMIN_ID'),
    ]);

    /*
     * Confirmar la concesión.
     */
    $pdo->commit();

    echo 'success';

} catch (PDOException $exception) {

    if (
        $pdo instanceof PDO
        && $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    /*
     * MySQL 1062:
     * Violación de restricción UNIQUE.
     */
    if (
        (int) (
            $exception->errorInfo[1] ?? 0
        ) === 1062
    ) {
        echo 'duplicate';
        exit(0);
    }

    fwrite(
        STDERR,
        $exception->getMessage()
    );

    exit(2);

} catch (Throwable $exception) {

    if (
        $pdo instanceof PDO
        && $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    fwrite(
        STDERR,
        $exception->getMessage()
    );

    exit(3);
}
WORKER;

            /*
             * 8. CONFIGURACIÓN DE MYSQL
             */
            $database = config(
                'database.connections.mysql'
            );

            $commonEnvironment = [
                'YESIAM_HOST' =>
                    (string) (
                        $database['host'] ?? '127.0.0.1'
                    ),

                'YESIAM_PORT' =>
                    (string) (
                        $database['port'] ?? '3306'
                    ),

                'YESIAM_DATABASE' =>
                    'yesiam_test',

                'YESIAM_CHARSET' =>
                    (string) (
                        $database['charset'] ?? 'utf8mb4'
                    ),

                'YESIAM_USERNAME' =>
                    (string) (
                        $database['username'] ?? ''
                    ),

                'YESIAM_PASSWORD' =>
                    (string) (
                        $database['password'] ?? ''
                    ),

                'YESIAM_GATE' =>
                    $gate,

                'YESIAM_EMPLOYEE_ID' =>
                    (string) $employee->id,

                'YESIAM_RESOURCE_ID' =>
                    (string) $resource->id,

                'YESIAM_ADMIN_ID' =>
                    (string) $admin->id,
            ];

            /*
             * 9. PREPARAR PROCESO A
             */
            $processA = new Process(
                [
                    PHP_BINARY,
                    '-r',
                    $workerCode,
                ],
                base_path(),
                $commonEnvironment + [
                    'YESIAM_READY' =>
                        $readyA,

                    'YESIAM_REQUEST_ID' =>
                        (string) $firstRequest->id,
                ]
            );

            /*
             * 10. PREPARAR PROCESO B
             */
            $processB = new Process(
                [
                    PHP_BINARY,
                    '-r',
                    $workerCode,
                ],
                base_path(),
                $commonEnvironment + [
                    'YESIAM_READY' =>
                        $readyB,

                    'YESIAM_REQUEST_ID' =>
                        (string) $secondRequest->id,
                ]
            );

            $processA->setTimeout(30);
            $processB->setTimeout(30);

            /*
             * 11. INICIAR AMBOS PROCESOS
             */
            $processA->start();
            $processB->start();

            /*
             * 12. ESPERAR A LOS TRABAJADORES
             */
            $deadline = microtime(true) + 15;

            while (
                ! (
                    is_file($readyA)
                    && is_file($readyB)
                )
            ) {
                if (
                    microtime(true) > $deadline
                    || ! $processA->isRunning()
                    || ! $processB->isRunning()
                ) {
                    $this->fail(
                        'Los procesos no llegaron a la barrera. '
                        . 'A: ' . $processA->getErrorOutput()
                        . ' B: ' . $processB->getErrorOutput()
                    );
                }

                usleep(20000);
            }

            /*
             * 13. LIBERAR LA BARRERA
             *
             * Ambos trabajadores pueden
             * intentar insertar al mismo tiempo.
             */
            $this->assertNotFalse(
                file_put_contents(
                    $gate,
                    'go'
                )
            );

            /*
             * 14. ESPERAR LOS RESULTADOS
             */
            $exitA = $processA->wait();
            $exitB = $processB->wait();

            $this->assertSame(
                0,
                $exitA,
                $processA->getErrorOutput()
            );

            $this->assertSame(
                0,
                $exitB,
                $processB->getErrorOutput()
            );

            /*
             * 15. VERIFICAR RESULTADOS
             *
             * Uno debe insertar correctamente.
             * El otro debe recibir duplicado.
             */
            $results = [
                trim(
                    $processA->getOutput()
                ),
                trim(
                    $processB->getOutput()
                ),
            ];

            sort($results);

            $this->assertSame(
                [
                    'duplicate',
                    'success',
                ],
                $results
            );

            /*
             * 16. VERIFICAR INTEGRIDAD
             *
             * Solo contamos concesiones
             * relacionadas con nuestro recurso.
             *
             * No suponemos que las demás
             * tablas estén vacías.
             */
            $this->assertSame(
                1,
                DB::table('access_grants')
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->count()
            );

            $this->assertSame(
                1,
                DB::table('access_grants')
                    ->where(
                        'user_id',
                        $employee->id
                    )
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->count()
            );

            /*
             * 17. VALIDAR SOLICITUD GANADORA
             */
            $savedRequestId = DB::table(
                'access_grants'
            )
                ->where(
                    'resource_id',
                    $resource->id
                )
                ->value(
                    'access_request_id'
                );

            $this->assertContains(
                (int) $savedRequestId,
                [
                    (int) $firstRequest->id,
                    (int) $secondRequest->id,
                ]
            );

            /*
             * 18. VALIDAR AUSENCIA DE AUDITORÍA
             *
             * Los procesos insertan mediante
             * PDO, sin ejecutar AuditService.
             */
            $this->assertSame(
                0,
                DB::table('audit_logs')
                    ->where(
                        'actor_user_id',
                        $admin->id
                    )
                    ->count()
            );

        } finally {

            /*
             * 19. DETENER PROCESOS
             *
             * Nunca eliminamos los datos
             * mientras un trabajador continúa
             * utilizándolos.
             */
            foreach (
                [$processA, $processB]
                as $process
            ) {
                if (
                    $process instanceof Process
                    && $process->isRunning()
                ) {
                    $process->stop(1);
                }
            }

            /*
             * 20. LIMPIAR ARCHIVOS TEMPORALES
             */
            if (
                $folder !== null
                && is_dir($folder)
            ) {
                foreach (
                    [
                        'start.flag',
                        'worker-a.ready',
                        'worker-b.ready',
                    ] as $name
                ) {
                    $path = $folder
                        . DIRECTORY_SEPARATOR
                        . $name;

                    if (is_file($path)) {
                        @unlink($path);
                    }
                }

                @rmdir($folder);
            }

            /*
             * 21. ELIMINAR CONCESIONES,
             * SOLICITUDES Y RECURSO
             *
             * Solo eliminamos los registros
             * asociados al recurso temporal.
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
             * 22. ELIMINAR IDENTIDADES TEMPORALES
             */
            $userIds = array_values(
                array_filter([
                    $admin?->id,
                    $employee?->id,
                    $approver?->id,
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

    /*
    |--------------------------------------------------------------------------
    | CREAR SOLICITUD APROBADA DE PRUEBA
    |--------------------------------------------------------------------------
    */

    private function makeApprovedRequest(
        User $employee,
        Resource $resource,
        User $approver
    ): AccessRequest {

        $request = AccessRequest::create([
            'requester_user_id' =>
                $employee->id,

            'resource_id' =>
                $resource->id,

            'justification' =>
                'Requiere acceso VPN para sus funciones.',
        ]);

        $request->forceFill([
            'status' => 'approved',

            'decided_by_user_id' =>
                $approver->id,

            'decision_reason' =>
                'Solicitud aprobada para pruebas.',

            'decided_at' =>
                now(),
        ])->save();

        return $request->refresh();
    }
}
