<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Wrapper mínimo sobre la HTTP API de ElevenLabs. Sólo expone síntesis TTS
 * — no se necesita el resto del SDK. La key se lee de config (.env), nunca
 * se acepta como parámetro del frontend.
 *
 * Endpoint: POST /v1/text-to-speech/{voice_id}
 * Devuelve audio binario (MP3 por defecto) que el caller reenvía al navegador.
 */
class ElevenLabsClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $key = (string) config('ai.elevenlabs.api_key');
        if ($key === '') {
            throw new RuntimeException('ELEVENLABS_API_KEY no está configurado.');
        }

        return new self(
            apiKey: $key,
            baseUrl: rtrim((string) config('ai.elevenlabs.base_url'), '/'),
            timeoutSeconds: (int) config('ai.elevenlabs.timeout', 30),
        );
    }

    /**
     * Sintetiza el texto con la voz indicada y devuelve los bytes MP3.
     */
    public function synthesize(string $text, string $voiceId, string $model, string $outputFormat = 'mp3_44100_128'): string
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'accept' => 'audio/mpeg',
        ])
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->asJson()
            ->post('/text-to-speech/'.$voiceId.'?output_format='.$outputFormat, [
                'text' => $text,
                'model_id' => $model,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'ElevenLabs respondió con error HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
            );
        }

        return $response->body();
    }
}
