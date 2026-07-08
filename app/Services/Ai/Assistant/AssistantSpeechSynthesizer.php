<?php

namespace App\Services\Ai\Assistant;

use App\Services\Ai\OpenAiClient;

/**
 * Sintetiza un mensaje del asistente con la API de TTS de OpenAI y devuelve
 * los bytes MP3. Es el único punto que llama a la API de voz; el controller le
 * pasa solo el texto ya validado y autorizado. (ElevenLabs quedó descartado
 * por calidad de voz; su cliente sigue en el repo por si se retoma.)
 */
final class AssistantSpeechSynthesizer
{
    public function synthesize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Texto vacío.');
        }

        $max = (int) config('ai.assistant.tts_max_chars', 1200);
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        $model = (string) config('ai.assistant.tts_model', 'gpt-4o-mini-tts');

        return OpenAiClient::fromConfig()->synthesizeSpeech(
            text: $text,
            model: $model,
            voice: (string) config('ai.assistant.tts_voice', 'nova'),
            // instructions solo existe en los modelos gpt-4o-*-tts.
            instructions: str_starts_with($model, 'gpt-4o')
                ? (string) config('ai.assistant.tts_instructions')
                : null,
        );
    }
}
