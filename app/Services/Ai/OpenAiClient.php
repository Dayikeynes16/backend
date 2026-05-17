<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Wrapper mínimo sobre la API HTTP de OpenAI usando el cliente HTTP de Laravel.
 * Evita una dependencia adicional. Sólo expone lo que el flujo de gastos necesita
 * (chat.completions con visión y JSON mode).
 */
class OpenAiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $key = (string) config('ai.openai.api_key');
        if ($key === '') {
            throw new RuntimeException('OPENAI_API_KEY no está configurado.');
        }

        return new self(
            apiKey: $key,
            baseUrl: rtrim((string) config('ai.openai.base_url'), '/'),
            timeoutSeconds: (int) config('ai.openai.timeout', 60),
        );
    }

    /**
     * Llama a POST /chat/completions y devuelve el JSON parseado.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function chatCompletions(array $payload): array
    {
        $response = $this->http()->post('/chat/completions', $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI respondió con error HTTP '.$response->status().': '.$response->body(),
            );
        }

        return $response->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Permite a tests reemplazar el comportamiento del Http facade vía Http::fake().
     * Expone Response cruda para inspección si fuera necesario.
     */
    public function rawChatCompletions(array $payload): Response
    {
        return $this->http()->post('/chat/completions', $payload);
    }

    /**
     * Transcribe un audio con Whisper. Devuelve el texto transcrito.
     * El endpoint /audio/transcriptions usa multipart (no JSON), por eso no
     * reutiliza el helper http().
     */
    public function transcribeAudio(
        string $audioBytes,
        string $filename,
        string $mimeType,
        string $model = 'whisper-1',
        ?string $language = 'es',
    ): string {
        $params = ['model' => $model];
        if ($language) {
            $params['language'] = $language;
        }

        $response = Http::withToken($this->apiKey)
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->attach('file', $audioBytes, $filename, ['Content-Type' => $mimeType])
            ->post('/audio/transcriptions', $params);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI Whisper respondió con error HTTP '.$response->status().': '.$response->body(),
            );
        }

        $text = $response->json('text');
        if (! is_string($text)) {
            throw new RuntimeException('Whisper no devolvió un campo "text" válido.');
        }

        return trim($text);
    }
}
