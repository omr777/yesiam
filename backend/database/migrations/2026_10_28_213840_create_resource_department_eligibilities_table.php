<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | FASE 17 - DEPARTMENT-BASED ACCESS ELIGIBILITY
    |--------------------------------------------------------------------------
    |
    | Esta migración permite restringir recursos
    | a departamentos específicos.
    |
    | Reglas:
    |
    | department_restricted = false:
    |   El recurso no tiene restricción departamental.
    |
    | department_restricted = true:
    |   El recurso solo permite departamentos
    |   registrados en la tabla de elegibilidad.
    |
    | Si está restringido y no tiene departamentos,
    | ningún departamento será elegible.
    |
    */

    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | PASO 1: AGREGAR MODO DE RESTRICCIÓN A RESOURCES
        |--------------------------------------------------------------------------
        |
        | Valor predeterminado: false.
        |
        | Esto preserva el comportamiento de
        | los recursos que ya existen.
        |
        */

        Schema::table('resources', function (Blueprint $table) {
            $table->boolean('department_restricted')
                ->default(false);
        });

        /*
        |--------------------------------------------------------------------------
        | PASO 2: CREAR TABLA DE DEPARTAMENTOS AUTORIZADOS
        |--------------------------------------------------------------------------
        */

        Schema::create(
            'resource_department_eligibilities',
            function (Blueprint $table) {

                $table->id();

                /*
                 * Recurso que estamos configurando.
                 */
                $table->foreignId('resource_id')
                    ->constrained(
                        table: 'resources',
                        indexName: 'rde_resource_fk'
                    )
                    ->restrictOnDelete();

                /*
                 * Departamento elegible.
                 */
                $table->foreignId('department_id')
                    ->constrained(
                        table: 'departments',
                        indexName: 'rde_department_fk'
                    )
                    ->restrictOnDelete();

                /*
                 * Administrador que registró
                 * esta relación.
                 */
                $table->foreignId('assigned_by_user_id')
                    ->constrained(
                        table: 'users',
                        indexName: 'rde_assigned_by_fk'
                    )
                    ->restrictOnDelete();

                $table->timestamps();

                /*
                 * Un departamento solo puede
                 * estar autorizado una vez
                 * para cada recurso.
                 */
                $table->unique(
                    [
                        'resource_id',
                        'department_id',
                    ],
                    'resource_department_eligibility_unique'
                );

                /*
                 * Facilitar búsquedas de recursos
                 * por departamento.
                 */
                $table->index(
                    'department_id',
                    'rde_department_lookup'
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | REVERTIR LA MIGRACIÓN
    |--------------------------------------------------------------------------
    */

    public function down(): void
    {
        /*
         * Primero eliminar la tabla que contiene
         * las relaciones y claves foráneas.
         */
        Schema::dropIfExists(
            'resource_department_eligibilities'
        );

        /*
         * Después eliminar la configuración
         * de restricción del recurso.
         */
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('department_restricted');
        });
    }
};
