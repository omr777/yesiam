<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GrantConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /*
    |--------------------------------------------------------------------------
    | PROTECCIÓN DE LA BASE DE DATOS
    |--------------------------------------------------------------------------
    |
    | DatabaseMigrations ejecuta migrate:fresh.
    |
    | Por seguridad, bloqueamos la ejecución
    | antes de modificar cualquier base distinta
    | de yesiam_test.
    |
    */

    protected function beforeRefreshingDatabase(): void
    {
        if (
            config('database.default') !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'yesiam_test'
        ) {
            throw new RuntimeException(
                'Prueba detenida: debes utilizar MySQL y la base yesiam_test.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA: DOS CONCESIONES SIMULTÁNEAS
    |--------------------------------------------------------------------------
    */

    public function test_two_simultaneous_inserts_cannot_create_duplicate_active_access(): void
    {
        /*
         * 1. Comprobar que existe el índice
         * de seguridad creado en la Fase 15.1.
         */

        $this->assertTrue(
            Schema::hasIndex(
                'access_grants',
                'access_grants_unique_active_user_resource'
            ),
            'Falta el índice UNIQUE de concesiones activas.'
        );

        /*
         * 2. Crear identidades de prueba.
         *
         * No necesitamos roles porque
         * vamos a probar directamente MySQL,
         * sin pasar por la API.
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
         * 3. Crear el recurso compartido.
         */

        $resource = Resource::create([
            'name' => 'VPN simultánea',
            'code' => 'VPN-RACE',
            'description' => 'Recurso para pruebas concurrentes',
            'is_active' => true,
        ]);

        /*
         * 4. Crear dos solicitudes aprobadas
         * para el mismo usuario y recurso.
         *
         * Son solicitudes distintas,
         * por lo que no violan el UNIQUE
         * de access_request_id.
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
         * 5. Crear una carpeta temporal
         * para sincronizar ambos procesos.
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
         * 6. Código que ejecutará cada
         * proceso PHP independiente.
         *
         * Cada trabajador:
         *
         * - Abre su propia conexión PDO.
         * - Espera a que ambos estén listos.
         * - Inicia una transacción.
         * - Intenta crear la concesión.
         * - Confirma o revierte la transacción.
         *
         * Los trabajadores no cargan Laravel.
         * Así comprobamos la garantía
         * directamente en MySQL.
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
     * Evitar esperas excesivas ante bloqueos.
     */
    $pdo->exec(
        'SET SESSION innodb_lock_wait_timeout = 10'
    );

    /*
     * Avisar que la conexión está preparada.
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
     * Esperar hasta que el proceso principal
     * permita comenzar las inserciones.
     */
    $deadline = microtime(true) + 15;

    while (! is_file(getenv('YESIAM_GATE'))) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException(
                'Se agotó el tiempo de espera.'
            );
        }

        usleep(20000);
    }

    /*
     * Iniciar la transacción independiente.
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
     * Confirmar la concesión si MySQL
     * aceptó la inserción.
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
     * Duplicate entry for unique key.
     */
    if (
        (int) ($exception->errorInfo[1] ?? 0)
        === 1062
    ) {
        echo 'duplicate';
        exit(0);
    }

    /*
     * Otros errores no deben tratarse
     * como un duplicado válido.
     */
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
         * 7. Obtener la configuración
         * de la conexión MySQL de pruebas.
         */

        $database = config(
            'database.connections.mysql'
        );

        /*
         * Compartir únicamente la conexión
         * a yesiam_test y los IDs necesarios
         * entre ambos procesos.
         */

        $commonEnvironment = [
            'YESIAM_HOST' =>
                (string) ($database['host'] ?? '127.0.0.1'),

            'YESIAM_PORT' =>
                (string) ($database['port'] ?? '3306'),

            'YESIAM_DATABASE' =>
                'yesiam_test',

            'YESIAM_CHARSET' =>
                (string) ($database['charset'] ?? 'utf8mb4'),

            'YESIAM_USERNAME' =>
                (string) ($database['username'] ?? ''),

            'YESIAM_PASSWORD' =>
                (string) ($database['password'] ?? ''),

            'YESIAM_GATE' =>
                $gate,

            'YESIAM_EMPLOYEE_ID' =>
                (string) $employee->id,

            'YESIAM_RESOURCE_ID' =>
                (string) $resource->id,

            'YESIAM_ADMIN_ID' =>
                (string) $admin->id,
        ];

        $processA = null;
        $processB = null;

        try {

            /*
             * 8. Preparar el proceso A.
             */

            $processA = new Process(
                [PHP_BINARY, '-r', $workerCode],
                base_path(),
                $commonEnvironment + [
                    'YESIAM_READY' => $readyA,
                    'YESIAM_REQUEST_ID' =>
                        (string) $firstRequest->id,
                ]
            );

            /*
             * 9. Preparar el proceso B.
             */

            $processB = new Process(
                [PHP_BINARY, '-r', $workerCode],
                base_path(),
                $commonEnvironment + [
                    'YESIAM_READY' => $readyB,
                    'YESIAM_REQUEST_ID' =>
                        (string) $secondRequest->id,
                ]
            );

            $processA->setTimeout(30);
            $processB->setTimeout(30);

            /*
             * 10. Iniciar los dos procesos.
             *
             * start() no espera a que terminen,
             * por eso ambos pueden ejecutarse
             * concurrentemente.
             */

            $processA->start();
            $processB->start();

            /*
             * 11. Esperar a que ambos trabajadores
             * hayan establecido conexión.
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
             * 12. Liberar ambos procesos.
             *
             * Los trabajadores intentarán
             * realizar sus inserciones.
             */

            $this->assertNotFalse(
                file_put_contents($gate, 'go')
            );

            /*
             * 13. Esperar los resultados.
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
             * 14. Analizar las respuestas.
             *
             * Una debe tener éxito.
             * La otra debe ser rechazada
             * por el índice UNIQUE.
             */

            $results = [
                trim($processA->getOutput()),
                trim($processB->getOutput()),
            ];

            sort($results);

            $this->assertSame(
                ['duplicate', 'success'],
                $results
            );

            /*
             * 15. Verificar el estado real
             * de la base de datos.
             */

            $this->assertDatabaseCount(
                'access_grants',
                1
            );

            $this->assertSame(
                1,
                DB::table('access_grants')
                    ->where('user_id', $employee->id)
                    ->where('resource_id', $resource->id)
                    ->where('status', 'active')
                    ->count()
            );

            /*
             * La concesión que ganó debe
             * pertenecer a una de las
             * dos solicitudes aprobadas.
             */

            $savedRequestId = DB::table(
                'access_grants'
            )
                ->where('user_id', $employee->id)
                ->value('access_request_id');

            $this->assertContains(
                (int) $savedRequestId,
                [
                    (int) $firstRequest->id,
                    (int) $secondRequest->id,
                ]
            );

            /*
             * Los trabajadores insertaron
             * directamente en MySQL.
             *
             * No existe auditoría porque
             * no ejecutaron el AuditService.
             */

            $this->assertDatabaseCount(
                'audit_logs',
                0
            );

        } finally {

            /*
             * 16. Limpieza garantizada.
             *
             * Incluso si falla una aserción,
             * detenemos los trabajadores
             * y eliminamos señales temporales.
             */

            if (! is_file($gate)) {
                @file_put_contents($gate, 'go');
            }

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

            foreach (
                [$gate, $readyA, $readyB]
                as $file
            ) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            @rmdir($folder);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR UNA SOLICITUD APROBADA
    |--------------------------------------------------------------------------
    */

    private function makeApprovedRequest(
        User $employee,
        Resource $resource,
        User $approver
    ): AccessRequest {

        $request = AccessRequest::create([
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'justification' =>
                'Requiere acceso VPN para sus funciones.',
        ]);

        $request->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $approver->id,
            'decision_reason' =>
                'Solicitud aprobada para pruebas.',
            'decided_at' => now(),
        ])->save();

        return $request->refresh();
    }
}
