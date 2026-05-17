<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_expense_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Status: pending → ready | failed; consumed cuando se transforma en gasto;
            // expired cuando un job lo limpia (24h sin confirmar).
            $table->string('status', 20)->default('pending');

            // Entradas del usuario (F1: texto + imágenes).
            $table->text('input_text')->nullable();
            $table->json('attachment_paths')->nullable();

            // Telemetría del proveedor (F4 alimentará el budget tracker).
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // Para debugging y métricas futuras (cuánto edita el usuario).
            $table->json('raw_response')->nullable();
            $table->json('parsed_proposal')->nullable();
            $table->text('error_message')->nullable();

            // Cuando se convierte en gasto.
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_expense_drafts');
    }
};
