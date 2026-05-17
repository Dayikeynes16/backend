<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fase 2: nota de voz por draft (un solo audio). El audio se transcribe
        // con Whisper antes de pasar a GPT-4o; conservamos el path para
        // auditoría y la transcripción para mostrarla al usuario.
        Schema::table('ai_expense_drafts', function (Blueprint $table) {
            $table->string('audio_path')->nullable()->after('attachment_paths');
            $table->text('audio_transcription')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_expense_drafts', function (Blueprint $table) {
            $table->dropColumn(['audio_path', 'audio_transcription']);
        });
    }
};
