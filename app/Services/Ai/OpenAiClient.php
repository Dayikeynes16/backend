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
     * Hace una llamada de function-calling: devuelve el `message` crudo del
     * `choices[0]` (que puede contener `tool_calls`) junto con el `usage` y
     * el modelo efectivo. El orquestador se encarga de iterar si la IA pide
     * más herramientas.
     *
     * @param  array<string, mixed>  $payload  ya debe traer model + messages + tools (+ tool_choice)
     * @return array{message: array<string, mixed>, usage: array<string, mixed>, model: string|null}
     */
    public function chatWithTools(array $payload): array
    {
        $response = $this->chatCompletions($payload);

        $message = $response['choices'][0]['message'] ?? null;
        if (! is_array($message)) {
            throw new RuntimeException('OpenAI no devolvió choices[0].message en chatWithTools.');
        }

        return [
            'message' => $message,
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'model' => is_string($response['model'] ?? null) ? $response['model'] : null,
        ];
    }

    /**
     * Transcribe un audio con Whisper. Devuelve el texto transcrito.
     * El endpoint /audio/transcriptions usa multipart (no JSON), por eso no
     * reutiliza el helper http().
     */
    /**
     * Sintetiza voz con la API de TTS de OpenAI y devuelve los bytes de audio.
     * `instructions` solo aplica a los modelos gpt-4o-*-tts (tono, idioma).
     */
    public function synthesizeSpeech(
        string $text,
        string $model = 'gpt-4o-mini-tts',
        string $voice = 'nova',
        string $responseFormat = 'mp3',
        ?string $instructions = null,
    ): string {
        $payload = [
            'model' => $model,
            'voice' => $voice,
            'input' => $text,
            'response_format' => $responseFormat,
        ];
        if ($instructions !== null && $instructions !== '') {
            $payload['instructions'] = $instructions;
        }

        $response = Http::withToken($this->apiKey)
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->post('/audio/speech', $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI TTS respondió con error HTTP '.$response->status().': '.$response->body(),
            );
        }

        return $response->body();
    }

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
