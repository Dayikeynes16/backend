<?php

namespace App\Services\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiExpenseDraft;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta el flujo "Registrar gasto con IA":
 * 1. Guarda los archivos subidos por el usuario en disco privado.
 * 2. Construye el contexto del tenant + arma el prompt multimodal.
 * 3. Llama a OpenAI GPT-4o.
 * 4. Parsea/valida la respuesta.
 * 5. Persiste el draft con la propuesta lista para que el frontend la consuma.
 *
 * NUNCA persiste el gasto. Sólo deja preparado un borrador que el usuario debe
 * confirmar desde el form (GastoController@store recibe ai_draft_id).
 */
class AiExpenseDraftService
{
    public function __construct(
        private readonly ExpenseContextBuilder $contextBuilder,
        private readonly AiExpenseProposalParser $parser,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function createDraft(
        Tenant $tenant,
        User $user,
        ?string $inputText,
        array $images,
        ?UploadedFile $audio = null,
    ): AiExpenseDraft {
        if (trim((string) $inputText) === '' && $images === [] && $audio === null) {
            throw new \InvalidArgumentException('Se requiere al menos un texto, una imagen o un audio.');
        }

        $draft = AiExpenseDraft::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'status' => AiDraftStatus::Pending->value,
            'input_text' => $inputText,
            'attachment_paths' => null,
        ]);

        $disk = $this->disk();
        $directory = "tenants/{$tenant->id}/ai_drafts/{$draft->id}";
        $storedPaths = [];

        foreach ($images as $image) {
            $ext = strtolower((string) $image->getClientOriginalExtension()) ?: 'bin';
            $stored = $image->storeAs($directory, (string) Str::uuid().'.'.$ext, [
                'disk' => $disk,
                'visibility' => 'private',
            ]);
            if (! $stored) {
                continue;
            }
            $storedPaths[] = [
                'path' => $stored,
                'original_name' => $image->getClientOriginalName(),
                'mime_type' => $image->getMimeType(),
                'size_bytes' => $image->getSize(),
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

            // Si hay audio, transcribimos primero y combinamos con el texto del
            // usuario. Whisper es barato (~$0.006/min) y rápido (1–3s típico).
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
            // Exponemos la transcripción en la propuesta para que el frontend
            // la muestre (informativo) — no afecta validación.
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
            Log::warning('AiExpenseDraftService falló', [
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

    /**
     * Fallback cuando el navegador no provee extensión en el upload (típico de
     * MediaRecorder: el blob se sube como blob sin nombre).
     */
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
     * Elimina los archivos físicos del draft. Lo usa el job de limpieza 24h y
     * también al consumirlo (cuando los archivos ya pasaron a expenses/).
     */
    public function deleteDraftFiles(AiExpenseDraft $draft): void
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

    /**
     * @return string disk name (ej. 'local').
     */
    public function disk(): string
    {
        return (string) config('expenses.disk', 'local');
    }

    /**
     * Arma el payload para POST /chat/completions con un solo turno multimodal.
     *
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
                // F1 sólo manda imágenes a la API; PDFs se procesarán en fase
                // posterior con OCR previo. Por ahora se ignoran a nivel modelo
                // pero quedan guardadas en el draft para que el usuario las vea.
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
Eres un asistente que extrae los datos de un gasto operativo a partir del texto y/o las imágenes (tickets, recibos, facturas) que aporta un usuario de una carnicería en México.

Recibes un CONTEXTO DEL TENANT con el catálogo real de categorías y subcategorías de gastos, los métodos de pago disponibles, las sucursales y reglas.

Devuelves SIEMPRE un objeto JSON (sin envoltorio, sin markdown) con esta forma exacta:

{
  "concepto": string|null,                       // texto breve, p.ej. "Recibo de luz CFE"
  "monto": number|null,                          // pesos mexicanos con decimales
  "fecha": "YYYY-MM-DD"|null,                    // fecha del gasto si la puedes leer
  "expense_subcategory_id": integer|null,        // SOLO ids existentes en el catálogo
  "categoria_nombre": string|null,               // nombre de la categoría elegida (informativo)
  "subcategoria_nombre": string|null,            // nombre de la subcategoría elegida (informativo)
  "metodo_pago": "cash"|"card"|"transfer"|null,
  "branch_id": integer|null,                     // sólo si lo puedes inferir claramente
  "descripcion": string|null,                    // detalle libre, opcional
  "confianza": "alta"|"media"|"baja",
  "confianza_por_campo": {                       // mismo enum por cada campo relevante
    "monto": "alta"|"media"|"baja",
    "concepto": "alta"|"media"|"baja",
    "subcategoria": "alta"|"media"|"baja",
    "fecha": "alta"|"media"|"baja",
    "metodo_pago": "alta"|"media"|"baja"
  },
  "campos_faltantes": [string],                  // p.ej. ["sucursal"] si no la pudiste inferir
  "alertas": [string],                           // mensajes para el revisor humano
  "sugerencia_nueva_categoria": null | {
    "tipo": "categoria"|"subcategoria",
    "nombre_propuesto": string,
    "descripcion_propuesta": string,
    "categoria_padre_id": integer|null,          // sólo si tipo="subcategoria"
    "razon": string
  }
}

Cumple las reglas listadas en CONTEXTO DEL TENANT.reglas. No expliques tu razonamiento fuera del JSON.
TXT;
    }

    /**
     * Saca el JSON de la respuesta de chat.completions. Tolera bloques de markdown
     * si el modelo decide envolver pese a tener response_format=json_object.
     *
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
