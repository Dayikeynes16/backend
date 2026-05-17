<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F3: drafts del flujo "Crear categoría con IA". Espejo de
        // ai_expense_drafts pero apunta a expense_categories al confirmar.
        Schema::create('ai_category_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // pending → ready | failed; consumed cuando se aplica al catálogo;
            // expired tras 24h sin consumir (job F4).
            $table->string('status', 20)->default('pending');

            // Entradas del usuario.
            $table->text('input_text')->nullable();
            $table->string('audio_path')->nullable();
            $table->text('audio_transcription')->nullable();

            // Telemetría del proveedor.
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // Respuesta cruda + propuesta normalizada para el frontend.
            $table->json('raw_response')->nullable();
            $table->json('parsed_proposal')->nullable();
            $table->text('error_message')->nullable();

            // Al confirmar: la categoría que se creó o se actualizó.
            $table->foreignId('expense_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_category_drafts');
    }
};
