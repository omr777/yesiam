<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('action', 100);

            $table->string('entity_type', 100);

            $table->unsignedBigInteger('entity_id')->nullable();

            $table->string('outcome', 20);

            $table->json('metadata')->nullable();

            $table->ipAddress('ip_address')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
