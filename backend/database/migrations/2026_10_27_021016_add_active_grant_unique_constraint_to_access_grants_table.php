<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Si el acceso está activo, guardar
         * automáticamente el ID del recurso.
         *
         * Si está revocado, guardar NULL.
         */
        Schema::table('access_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('active_resource_id')
                ->nullable()
                ->storedAs(
                    "CASE WHEN `status` = 'active' THEN `resource_id` ELSE NULL END"
                );
        });

        /*
         * Impedir que un usuario tenga dos
         * concesiones activas del mismo recurso.
         */
        Schema::table('access_grants', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'active_resource_id'],
                'access_grants_unique_active_user_resource'
            );
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropUnique(
                'access_grants_unique_active_user_resource'
            );
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropColumn('active_resource_id');
        });
    }
};
