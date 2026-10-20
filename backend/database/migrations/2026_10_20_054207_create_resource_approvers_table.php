<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_approvers', function (Blueprint $table) {
            $table->id();

            // Recurso que tendrá un responsable.
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->restrictOnDelete();

            // Usuario designado como responsable.
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Administrador que autorizó la asignación.
            $table->foreignId('assigned_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // Evita asignar dos veces al mismo responsable
            // sobre el mismo recurso.
            $table->unique([
                'resource_id',
                'user_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_approvers');
    }
};
