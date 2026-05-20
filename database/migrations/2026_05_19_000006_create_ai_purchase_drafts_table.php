<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_purchase_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Status: pending → ready | failed; consumed cuando se transforma
            // en compra; expired cuando un job lo limpia (24h sin confirmar).
            // Reutiliza el enum AiDraftStatus ya existente.
            $table->string('status', 20)->default('pending');

            // Entradas del usuario (texto + imágenes/PDF + audio).
            $table->text('input_text')->nullable();
            $table->json('attachment_paths')->nullable();
            $table->string('audio_path', 500)->nullable();
            $table->text('audio_transcription')->nullable();

            // Telemetría OpenAI.
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->json('raw_response')->nullable();
            $table->json('parsed_proposal')->nullable();
            $table->text('error_message')->nullable();

            // Vínculo cuando se consume.
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_purchase_drafts');
    }
};
