<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_assistant_sessions')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // role: user | assistant | tool
            $table->string('role', 20);

            $table->text('content')->nullable();

            // For role='tool' (and the assistant message that requested it).
            $table->string('tool_name', 80)->nullable();
            $table->json('tool_params')->nullable();
            $table->json('tool_result')->nullable();
            $table->string('tool_status', 20)->nullable(); // success | denied | error

            // Telemetry — only on assistant messages (the model output).
            $table->string('ai_model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['session_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_messages');
    }
};
