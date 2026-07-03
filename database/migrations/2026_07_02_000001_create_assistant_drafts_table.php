<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla general de borradores originados por el asistente conversacional.
 *
 * Un `type` discrimina el dominio ('expense', y a futuro purchase/provider/...).
 * La IA sólo prepara la fila (status=ready); NUNCA crea el registro final. La
 * confirmación es una 2ª petición HTTP desde un botón de la UI, jamás una tool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_drafts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // dueño del borrador

            // Enlace a la conversación que lo originó.
            $table->foreignId('session_id')->nullable()->constrained('ai_assistant_sessions')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_assistant_messages')->nullOnDelete();

            // Discriminador de dominio: 'expense' (extensible).
            $table->string('type', 40);

            // pending → ready | failed ; consumed | cancelled | expired
            $table->string('status', 20)->default('pending');

            $table->json('payload')->nullable();          // propuesta estructurada (editable)
            $table->json('original_input')->nullable();    // {text, transcription} del usuario
            $table->json('attachment_paths')->nullable();  // [{path, original_name, mime_type, size_bytes}]

            // Telemetría de la extracción con IA.
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();

            // Resultado de la operación confirmada (morph al registro real).
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            // TTL: se limpia (archivos + estado) por el job de expiración.
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_drafts');
    }
};
