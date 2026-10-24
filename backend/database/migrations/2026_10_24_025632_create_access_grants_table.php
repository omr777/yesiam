<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();

            /*
             * Solicitud aprobada que origina
             * esta concesión de acceso.
             */
            $table->foreignId('access_request_id')
                ->unique()
                ->constrained('access_requests')
                ->restrictOnDelete();

            /*
             * Usuario que recibe el permiso.
             */
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             * Recurso al que se concede acceso.
             */
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->restrictOnDelete();

            /*
             * Estado de la concesión interna.
             */
            $table->string('status', 20)
                ->default('active');

            /*
             * Administrador que ejecutó
             * la concesión.
             */
            $table->foreignId('granted_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('granted_at');

            /*
             * Información de una posible
             * revocación futura.
             */
            $table->foreignId('revoked_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('revoked_at')
                ->nullable();

            $table->text('revocation_reason')
                ->nullable();

            $table->timestamps();

            /*
             * Facilita consultar las concesiones
             * activas de un usuario y recurso.
             */
            $table->index(
                ['user_id', 'resource_id', 'status'],
                'access_grants_user_resource_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
