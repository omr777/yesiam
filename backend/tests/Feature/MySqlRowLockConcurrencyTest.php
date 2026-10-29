<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MySqlRowLockConcurrencyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | PRUEBA DE CONCURRENCIA REAL
    |--------------------------------------------------------------------------
    |
    | IMPORTANTE:
    |
    | No utilizamos RefreshDatabase porque
    | necesitamos dos conexiones independientes
    | que puedan observar el mismo registro
    | confirmado en MySQL.
    |
    | Esta prueba crea un departamento temporal,
    | verifica el bloqueo y elimina el registro
    | al finalizar.
    |
    | Nunca debe ejecutarse sobre la base
    | de desarrollo o producción.
    |
    */

    public function test_second_connection_waits_for_row_lock(): void
    {
        /*
         * 1. IDENTIFICAR CONFIGURACIÓN
         */
        $defaultConnectionName = config(
            'database.default'
        );

        $connectionConfig = config(
            "database.connections.{$defaultConnectionName}"
        );

        /*
         * 2. EXIGIR MYSQL
         *
         * No queremos realizar esta prueba
         * accidentalmente sobre SQLite.
         */
        $this->assertSame(
            'mysql',
            $connectionConfig['driver'] ?? null,
            'Esta prueba requiere MySQL.'
        );

        $primary = DB::connection(
            $defaultConnectionName
        );

        /*
         * 3. VERIFICAR BASE REAL
         *
         * La comprobación se realiza contra
         * el servidor, no solamente contra
         * las variables del archivo .env.
         */
        $primaryDatabase = $primary
            ->selectOne(
                'SELECT DATABASE() AS database_name'
            )
            ->database_name;

        $this->assertSame(
            'yesiam_test',
            $primaryDatabase,
            'SEGURIDAD: esta prueba solo puede utilizar yesiam_test.'
        );

        /*
         * 4. CREAR SEGUNDA CONEXIÓN
         *
         * Copiamos los parámetros de conexión
         * para abrir otra sesión independiente
         * contra la misma base.
         */
        $secondaryName = 'yesiam_concurrency_secondary';

        config([
            "database.connections.{$secondaryName}" =>
                $connectionConfig,
        ]);

        DB::purge($secondaryName);

        $secondary = DB::connection(
            $secondaryName
        );

        /*
         * 5. COMPROBAR SEGUNDA BASE
         */
        $secondaryDatabase = $secondary
            ->selectOne(
                'SELECT DATABASE() AS database_name'
            )
            ->database_name;

        $this->assertSame(
            'yesiam_test',
            $secondaryDatabase
        );

        /*
         * 6. COMPROBAR SESIONES DIFERENTES
         *
         * CONNECTION_ID() identifica
         * una conexión en el servidor MySQL.
         */
        $primarySession = $primary
            ->selectOne(
                'SELECT CONNECTION_ID() AS session_id'
            )
            ->session_id;

        $secondarySession = $secondary
            ->selectOne(
                'SELECT CONNECTION_ID() AS session_id'
            )
            ->session_id;

        $this->assertNotEquals(
            $primarySession,
            $secondarySession,
            'Las conexiones deben ser independientes.'
        );

        /*
         * 7. IDENTIFICADOR ÚNICO
         *
         * Evitamos colisiones con departamentos
         * existentes y otras ejecuciones.
         */
        $code = 'C18-' . Str::upper(
            Str::random(12)
        );

        $departmentId = null;

        try {
            /*
             * 8. CREAR REGISTRO TEMPORAL
             *
             * Esta inserción queda confirmada
             * antes de iniciar las dos
             * transacciones de bloqueo.
             */
            $departmentId = $primary
                ->table('departments')
                ->insertGetId([
                    'name' =>
                        'Concurrencia ' . $code,
                    'code' => $code,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            /*
             * 9. LIMITAR TIEMPO DE ESPERA
             *
             * La conexión B esperará como máximo
             * aproximadamente un segundo
             * por un bloqueo InnoDB.
             *
             * Es una configuración de sesión;
             * no cambiamos la configuración
             * global de MySQL.
             */
            $secondary->statement(
                'SET SESSION innodb_lock_wait_timeout = 1'
            );

            /*
             * 10. INICIAR TRANSACCIÓN A
             */
            $primary->beginTransaction();

            /*
             * 11. CONEXIÓN A BLOQUEA EL REGISTRO
             */
            $lockedDepartment = $primary
                ->table('departments')
                ->where('id', $departmentId)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull(
                $lockedDepartment
            );

            /*
             * 12. INICIAR TRANSACCIÓN B
             */
            $secondary->beginTransaction();

            /*
             * 13. CONEXIÓN B INTENTA
             * ADQUIRIR EL MISMO BLOQUEO
             *
             * Como A mantiene su transacción
             * abierta, B no debería obtener
             * inmediatamente el registro.
             */
            $lockTimedOut = false;

            try {
                $secondary
                    ->table('departments')
                    ->where('id', $departmentId)
                    ->lockForUpdate()
                    ->first();

            } catch (QueryException $exception) {
                /*
                 * MySQL 1205:
                 * Lock wait timeout exceeded.
                 *
                 * Un error diferente no se
                 * considera resultado válido.
                 */
                $mysqlErrorCode = (int) (
                    $exception->errorInfo[1] ?? 0
                );

                $this->assertSame(
                    1205,
                    $mysqlErrorCode,
                    'Se esperaba un timeout de bloqueo InnoDB.'
                );

                $lockTimedOut = true;
            }

            $this->assertTrue(
                $lockTimedOut,
                'La conexión B obtuvo el bloqueo antes de que A lo liberara.'
            );

            /*
             * 14. TERMINAR TRANSACCIÓN B
             *
             * Limpiamos el intento fallido.
             */
            $secondary->rollBack();

            /*
             * 15. CONEXIÓN A LIBERA EL BLOQUEO
             */
            $primary->commit();

            /*
             * 16. CONEXIÓN B INTENTA OTRA VEZ
             *
             * Ahora el registro debería
             * encontrarse disponible.
             */
            $secondary->beginTransaction();

            $availableDepartment = $secondary
                ->table('departments')
                ->where('id', $departmentId)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull(
                $availableDepartment
            );

            $this->assertSame(
                (int) $departmentId,
                (int) $availableDepartment->id
            );

            $secondary->commit();

        } finally {

            /*
             * 17. LIMPIAR TRANSACCIONES ABIERTAS
             *
             * Esto se ejecuta también si
             * una aserción o una consulta falla.
             */
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }

            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }

            /*
             * 18. ELIMINAR DATOS TEMPORALES
             *
             * Usamos el ID exacto creado
             * durante esta prueba.
             */
            if ($departmentId !== null) {
                $primary
                    ->table('departments')
                    ->where('id', $departmentId)
                    ->delete();
            }

            /*
             * 19. CERRAR SEGUNDA CONEXIÓN
             */
            DB::disconnect($secondaryName);
        }
    }
}
