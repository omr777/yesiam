<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | FASE 22 - Enlace con Microsoft Entra ID
    |--------------------------------------------------------------------------
    |
    | entra_object_id: identificador permanente (claim "oid") de la
    | cuenta en Entra. No cambia aunque cambie el nombre o el UPN,
    | por eso es la llave de enlace después del primer inicio.
    |
    | entra_linked_at: cuándo se enlazó la identidad.
    |
    */

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('entra_object_id', 36)
                ->nullable()
                ->unique()
                ->after('email');

            $table->timestamp('entra_linked_at')
                ->nullable()
                ->after('entra_object_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['entra_object_id']);
            $table->dropColumn(['entra_object_id', 'entra_linked_at']);
        });
    }
};
