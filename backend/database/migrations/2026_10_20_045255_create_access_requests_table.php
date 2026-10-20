<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();

            // Empleado que solicita el acceso.
            $table->foreignId('requester_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Recurso al que desea acceder.
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->restrictOnDelete();

            // Justificación del empleado.
            $table->text('justification');

            // Estado inicial de la solicitud.
            $table->string('status', 20)
                ->default('pending');

            // Persona que tomó la decisión.
            $table->foreignId('decided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // Motivo de aprobación o rechazo.
            $table->text('decision_reason')
                ->nullable();

            // Fecha de la decisión.
            $table->timestamp('decided_at')
                ->nullable();

            $table->timestamps();

            // Índices para consultas frecuentes.
            $table->index([
                'requester_user_id',
                'status',
            ]);

            $table->index([
                'resource_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
