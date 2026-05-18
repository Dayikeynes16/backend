<?php

namespace App\Services\Ai\Assistant;

use App\Services\Ai\ElevenLabsClient;

/**
 * Sintetiza un mensaje del asistente con ElevenLabs y devuelve los bytes MP3.
 * Es el único punto que llama a la API de TTS; el controller le pasa solo el
 * texto ya validado y autorizado.
 */
final class AssistantSpeechSynthesizer
{
    public function synthesize(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Texto vacío.');
        }

        $max = (int) config('ai.elevenlabs.max_chars', 1200);
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        $client = ElevenLabsClient::fromConfig();

        return $client->synthesize(
            text: $text,
            voiceId: (string) config('ai.elevenlabs.voice_id'),
            model: (string) config('ai.elevenlabs.model', 'eleven_turbo_v2_5'),
            outputFormat: (string) config('ai.elevenlabs.output_format', 'mp3_44100_128'),
        );
    }
}
