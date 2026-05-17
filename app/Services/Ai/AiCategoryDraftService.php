<?php

namespace App\Services\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiCategoryDraft;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta el flujo "Crear categoría con IA":
 * 1. Guarda el audio (si llega) en disco privado.
 * 2. Si hay audio, lo transcribe con Whisper.
 * 3. Combina texto del usuario + transcripción.
 * 4. Construye el contexto del tenant (catálogo completo de categorías).
 * 5. Llama a OpenAI GPT-4o con response_format=json_object.
 * 6. Parsea y normaliza la propuesta.
 * 7. Persiste el draft. El consumo (creación real) ocurre en
 *    ExpenseCategoryController::storeFromAiDraft().
 */
class AiCategoryDraftService
{
    public function __construct(
        private readonly CategoryContextBuilder $contextBuilder,
        private readonly AiCategoryProposalParser $parser,
    ) {}

    public function createDraft(
        Tenant $tenant,
        User $user,
        ?string $inputText,
        ?UploadedFile $audio = null,
    ): AiCategoryDraft {
        if (trim((string) $inputText) === '' && $audio === null) {
            throw new \InvalidArgumentException('Se requiere al menos un texto o un audio.');
        }

        $draft = AiCategoryDraft::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => AiDraftStatus::Pending->value,
            'input_text' => $inputText,
        ]);

        $disk = $this->disk();
        $directory = "tenants/{$tenant->id}/ai_category_drafts/{$draft->id}";

        $audioPath = null;
        if ($audio !== null) {
            $ext = strtolower((string) $audio->getClientOriginalExtension())
                ?: $this->extensionFromMime((string) $audio->getMimeType());
            $audioPath = $audio->storeAs($directory, 'audio-'.(string) Str::uuid().'.'.$ext, [
                'disk' => $disk,
                'visibility' => 'private',
            ]) ?: null;
            $draft->update(['audio_path' => $audioPath]);
        }

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

            $context = $this->contextBuilder->build($tenant);
            $payload = $this->buildOpenAiPayload($context, $combinedText);
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
            Log::warning('AiCategoryDraftService falló', [
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
     * también al consumirlo (el audio no se conserva en el catálogo final).
     */
    public function deleteDraftFiles(AiCategoryDraft $draft): void
    {
        if ($draft->audio_path) {
            Storage::disk($this->disk())->delete($draft->audio_path);
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
     * @return array<string, mixed>
     */
    private function buildOpenAiPayload(array $context, ?string $inputText): array
    {
        $userContent = [
            "CONTEXTO DEL TENANT:\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            "MENSAJE DEL USUARIO:\n<<<\n".((string) $inputText)."\n>>>",
        ];

        return [
            'model' => config('ai.expenses.model'),
            'temperature' => config('ai.expenses.temperature'),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => implode("\n\n", $userContent)],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Eres un asistente que ayuda a un administrador de empresa de una carnicería en México a crear y organizar categorías de gastos en su catálogo.

Recibes un CONTEXTO DEL TENANT con todas las categorías existentes (con descripción, aliases, includes, excludes y sus subcategorías) y un MENSAJE DEL USUARIO describiendo qué tipo de categoría quiere crear.

JERARQUÍA DE PREFERENCIA (importantísima, en este orden):
1. usar_existente — si una categoría existente ya cubre razonablemente la intención del usuario, REUTILÍZALA.
2. crear_subcategoria — si lo que el usuario describe es un TIPO o VARIANTE de algo que ya existe (p.ej. "Gasolina" cuando ya existe "Transporte"), proponlo como subcategoría dentro de ese parent.
3. crear_categoria — sólo cuando ninguna categoría existente lo abarca razonablemente.
Si tienes duda real entre dos opciones, usa "necesita_aclaracion" en lugar de adivinar.

Devuelves SIEMPRE un objeto JSON (sin envoltorio, sin markdown):

A) crear_categoria — ninguna existente cubre esto:

{
  "accion_sugerida": "crear_categoria",
  "categoria_similar_existente": null,
  "nombre_categoria": string,                          // 120 chars máx
  "descripcion": string,                               // 500 chars máx
  "aliases": [string],                                 // máx 10, sinónimos para evitar duplicados futuros
  "incluye": [string],                                 // máx 15, qué SÍ pertenece
  "no_incluye": [string],                              // máx 15, qué NO debe entrar
  "subcategorias_sugeridas": [                         // máx 8
    { "nombre": string, "descripcion": string, "aliases": [string], "incluye": [string], "no_incluye": [string] }
  ],
  "confianza": "alta"|"media"|"baja",
  "preguntas_faltantes": []
}

B) usar_existente — ya existe una categoría que cubre la intención:

{
  "accion_sugerida": "usar_existente",
  "categoria_similar_existente": { "id": <id real>, "nombre": string, "razon": string },
  "mejoras_sugeridas": {                                // opcional
    "descripcion": string|null,
    "aliases_a_agregar": [string],
    "includes_a_agregar": [string],
    "excludes_a_agregar": [string]
  },
  "subcategorias_sugeridas": [                          // nuevas subcategorías DENTRO de la existente, no dupliques las que ya tiene
    { "nombre": string, "descripcion": string, "aliases": [string], "incluye": [string], "no_incluye": [string] }
  ],
  "confianza": "alta"|"media"|"baja",
  "preguntas_faltantes": []
}

C) crear_subcategoria — lo descrito es un tipo/variante de una categoría existente:

{
  "accion_sugerida": "crear_subcategoria",
  "categoria_padre": { "id": <id real del catálogo>, "nombre": string, "razon": string },  // por qué encaja como sub-tipo
  "subcategoria_propuesta": {                          // una sola subcategoría
    "nombre": string, "descripcion": string, "aliases": [string], "incluye": [string], "no_incluye": [string]
  },
  "subcategoria_similar_existente": null | { "id": <id real, de una subcategoría QUE PERTENECE al parent propuesto>, "nombre": string, "razon": string },
  "confianza": "alta"|"media"|"baja",
  "preguntas_faltantes": []
}

D) necesita_aclaracion — la intención no es clara o falta info clave:

{
  "accion_sugerida": "necesita_aclaracion",
  "preguntas_faltantes": [string],                      // máx 5 preguntas específicas
  "confianza": "baja"
}

Reglas clave:
- Antes de proponer crear_categoria, pregúntate: ¿es esto un tipo/variante de alguna categoría existente? Si sí, usa crear_subcategoria.
- Antes de proponer crear_subcategoria, revisa las subcategorías EXISTENTES del parent. Si ya hay una que cubre lo mismo, márcala en subcategoria_similar_existente con su id real.
- No inventes ids — sólo los del CONTEXTO DEL TENANT.
- "no_incluye"/"excludes" debe contener exclusiones que el usuario pide explícitamente o que confunden con la categoría — no exclusiones genéricas.
- No expliques tu razonamiento fuera del JSON.
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
