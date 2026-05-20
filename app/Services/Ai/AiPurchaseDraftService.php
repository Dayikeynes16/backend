<?php

namespace App\Services\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiPurchaseDraft;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta "Capturar compra con IA":
 *  1. Guarda los archivos del usuario (imagen/PDF) en disco privado.
 *  2. Guarda y transcribe el audio con Whisper si llega.
 *  3. Arma el contexto del tenant + prompt multimodal.
 *  4. Llama a OpenAI GPT-4o.
 *  5. Parsea/valida la respuesta.
 *  6. Persiste el draft listo para que el frontend lo consuma.
 *
 * NUNCA persiste la compra. Sólo deja preparado un borrador que el usuario
 * confirma en `HandlesPurchases@store` con `ai_draft_id`.
 *
 * Espejo de `AiExpenseDraftService` — comparte cliente OpenAI y patrones.
 */
class AiPurchaseDraftService
{
    public function __construct(
        private readonly PurchaseContextBuilder $contextBuilder,
        private readonly AiPurchaseProposalParser $parser,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function createDraft(
        Tenant $tenant,
        User $user,
        ?string $inputText,
        array $files,
        ?UploadedFile $audio = null,
    ): AiPurchaseDraft {
        if (trim((string) $inputText) === '' && $files === [] && $audio === null) {
            throw new \InvalidArgumentException('Se requiere al menos texto, imagen o audio.');
        }

        $draft = AiPurchaseDraft::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'status' => AiDraftStatus::Pending->value,
            'input_text' => $inputText,
            'attachment_paths' => null,
        ]);

        $disk = $this->disk();
        $directory = "tenants/{$tenant->id}/ai_purchase_drafts/{$draft->id}";
        $storedPaths = [];

        foreach ($files as $file) {
            $ext = strtolower((string) $file->getClientOriginalExtension()) ?: 'bin';
            $stored = $file->storeAs($directory, (string) Str::uuid().'.'.$ext, [
                'disk' => $disk,
                'visibility' => 'private',
            ]);
            if (! $stored) {
                continue;
            }
            $storedPaths[] = [
                'path' => $stored,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ];
        }

        $audioPath = null;
        if ($audio !== null) {
            $ext = strtolower((string) $audio->getClientOriginalExtension())
                ?: $this->extensionFromMime((string) $audio->getMimeType());
            $audioPath = $audio->storeAs($directory, 'audio-'.(string) Str::uuid().'.'.$ext, [
                'disk' => $disk,
                'visibility' => 'private',
            ]) ?: null;
        }

        $draft->update([
            'attachment_paths' => $storedPaths,
            'audio_path' => $audioPath,
        ]);

        try {
            $client = OpenAiClient::fromConfig();
            $started = microtime(true);

            $transcription = null;
            if ($audioPath !== null) {
                $transcription = $client->transcribeAudio(
                    audioBytes: (string) Storage::disk($disk)->get($audioPath),
                    filename: basename($audioPath),
                    mimeType: (string) ($audio->getMimeType() ?: 'audio/webm'),
                    model: (string) config('ai.expenses.transcription_model', 'whisper-1'),
                    language: (string) config('ai.expenses.transcription_language', 'es'),
                );
                $draft->update(['audio_transcription' => $transcription]);
            }

            $combinedText = $this->combineInputText($inputText, $transcription);
            $context = $this->contextBuilder->build($tenant, $user);
            $payload = $this->buildOpenAiPayload($context, $combinedText, $storedPaths);
            $response = $client->chatCompletions($payload);

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);

            $proposalRaw = $this->extractJsonFromResponse($response);
            $proposal = $this->parser->parse($proposalRaw, $tenant);
            $proposal['audio_transcription'] = $transcription;

            $draft->update([
                'status' => AiDraftStatus::Ready->value,
                'ai_provider' => 'openai',
                'ai_model' => $response['model'] ?? config('ai.expenses.model'),
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? null,
                'completion_tokens' => $response['usage']['completion_tokens'] ?? null,
                'latency_ms' => $elapsedMs,
                'raw_response' => $response,
                'parsed_proposal' => $proposal,
            ]);
        } catch (Throwable $e) {
            Log::warning('AiPurchaseDraftService falló', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            $draft->update([
                'status' => AiDraftStatus::Failed->value,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }

        return $draft->fresh();
    }

    /**
     * Borra los archivos físicos del draft. Lo usa el job de limpieza 24h y
     * el flujo de consumo (cuando los archivos ya pasaron a purchases/).
     */
    public function deleteDraftFiles(AiPurchaseDraft $draft): void
    {
        $disk = $this->disk();
        foreach ($draft->attachment_paths ?? [] as $entry) {
            if (isset($entry['path'])) {
                Storage::disk($disk)->delete($entry['path']);
            }
        }
        if ($draft->audio_path) {
            Storage::disk($disk)->delete($draft->audio_path);
        }
    }

    public function disk(): string
    {
        return (string) config('expenses.disk', 'local');
    }

    private function combineInputText(?string $userText, ?string $transcription): ?string
    {
        $userText = trim((string) $userText);
        $transcription = trim((string) $transcription);

        if ($userText !== '' && $transcription !== '') {
            return $userText."\n\n[Nota de voz transcrita]\n".$transcription;
        }
        if ($transcription !== '') {
            return '[Nota de voz transcrita]'."\n".$transcription;
        }

        return $userText !== '' ? $userText : null;
    }

    private function extensionFromMime(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mp4') || str_contains($mime, 'm4a') => 'm4a',
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            default => 'bin',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $storedPaths
     * @return array<string, mixed>
     */
    private function buildOpenAiPayload(array $context, ?string $inputText, array $storedPaths): array
    {
        $userContent = [
            [
                'type' => 'text',
                'text' => "CONTEXTO DEL TENANT:\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ],
        ];

        if (trim((string) $inputText) !== '') {
            $userContent[] = [
                'type' => 'text',
                'text' => "TEXTO DEL USUARIO:\n<<<\n".$inputText."\n>>>",
            ];
        }

        $disk = $this->disk();
        foreach ($storedPaths as $entry) {
            if (! isset($entry['path'], $entry['mime_type'])) {
                continue;
            }
            if (! str_starts_with((string) $entry['mime_type'], 'image/')) {
                // PDFs se almacenan pero no se mandan al modelo (mismo
                // criterio que gastos F1). OCR llegará en fase posterior.
                continue;
            }
            $bytes = Storage::disk($disk)->get($entry['path']);
            $b64 = base64_encode((string) $bytes);
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$entry['mime_type'].';base64,'.$b64,
                    'detail' => 'high',
                ],
            ];
        }

        return [
            'model' => config('ai.expenses.model'),
            'temperature' => config('ai.expenses.temperature'),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Eres un asistente que extrae los datos de una COMPRA a proveedor a partir del texto, audio (ya transcrito) y/o imágenes (facturas, notas) que aporta un usuario de una carnicería en México.

Recibes un CONTEXTO DEL TENANT con la lista real de proveedores activos (con id) y productos activos (con id y costo actual de referencia), las sucursales visibles para este usuario, los métodos de pago disponibles y reglas.

Devuelves SIEMPRE un objeto JSON (sin envoltorio, sin markdown) con esta forma exacta:

{
  "proveedor": null | {
    "id": integer|null,                     // SOLO ids existentes en proveedores
    "nombre": string|null
  },
  "invoice_number": string|null,            // folio que aparece en la factura del proveedor
  "purchased_at": "YYYY-MM-DD"|null,        // fecha del comprobante
  "branch_id": integer|null,                // sólo si lo puedes inferir y para admin-empresa
  "lineas": [
    {
      "product_id": integer|null,           // SOLO ids de productos del catálogo
      "concepto": string,                   // texto libre obligatorio, p.ej. "Pulpa de res"
      "quantity": number,                   // positivo con hasta 3 decimales
      "unit": "kg"|"g"|"l"|"ml"|"pieza"|"caja"|"bulto"|"cabeza",
      "unit_price": number,                 // pesos por unidad
      "notas": string|null
    }
  ],
  "total": number|null,                     // total declarado en la factura (puede no cuadrar con suma de líneas)
  "notas": string|null,
  "confianza": "alta"|"media"|"baja",
  "confianza_por_campo": {
    "proveedor": "alta"|"media"|"baja",
    "total": "alta"|"media"|"baja",
    "lineas": "alta"|"media"|"baja",
    "fecha": "alta"|"media"|"baja"
  },
  "alertas": [string],                      // mensajes para el revisor humano (p.ej. "El total no cuadra con la suma de líneas")
  "sugerencia_nuevo_proveedor": null | {
    "nombre_propuesto": string,
    "tipo_sugerido": "ganadero"|"mayorista_carne"|"insumos"|"servicios"|"otro"|null,
    "razon": string
  }
}

Cumple las reglas listadas en CONTEXTO DEL TENANT.reglas. No expliques tu razonamiento fuera del JSON.
TXT;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function extractJsonFromResponse(array $response): array
    {
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('OpenAI no devolvió contenido en choices[0].message.content.');
        }

        $candidate = trim($content);
        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $candidate) ?? $candidate;
        }

        try {
            return json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('La IA devolvió JSON inválido: '.$e->getMessage());
        }
    }
}
