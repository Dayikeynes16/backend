<?php

namespace App\Services\Ai\Assistant;

use App\Services\Ai\OpenAiClient;
use Illuminate\Http\UploadedFile;

/**
 * Transcripción de notas de voz del asistente con Whisper.
 *
 * No persiste el audio: el flujo del asistente es "dictado al input" —
 * el blob se envía, Whisper devuelve el texto, el frontend lo coloca en
 * el cuadro de entrada para que el usuario lo revise antes de enviar.
 */
final class AssistantTranscriber
{
    public function transcribe(UploadedFile $audio): string
    {
        $client = OpenAiClient::fromConfig();

        return $client->transcribeAudio(
            audioBytes: (string) file_get_contents($audio->getRealPath()),
            filename: $audio->getClientOriginalName() ?: 'audio.webm',
            mimeType: $audio->getMimeType() ?: 'audio/webm',
            model: (string) config('ai.expenses.transcription_model', 'whisper-1'),
            language: (string) config('ai.expenses.transcription_language', 'es'),
        );
    }
}
