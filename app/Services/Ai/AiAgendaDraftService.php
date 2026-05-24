<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Orquesta "Dictar a la agenda con IA":
 *  1. Transcribe el audio con Whisper si llega.
 *  2. Combina texto del usuario + transcripción.
 *  3. Arma un prompt en español con el esquema/enums de un ítem de agenda y le
 *     da "hoy" (fecha/hora actual en America/Mexico_City) para que resuelva
 *     expresiones relativas ("mañana 2pm" → datetime concreto).
 *  4. Llama a OpenAI (chat.completions, JSON mode).
 *  5. Normaliza la respuesta vía AiAgendaProposalParser.
 *
 * STATELESS por diseño: la agenda no tiene adjuntos, así que NO persiste nada
 * (ni draft ni archivo). Devuelve la propuesta directo al frontend. La
 * "confirmación" es el guardado del usuario en AgendaItemModal.
 *
 * Espejo de `AiPurchaseDraftService`/`AiExpenseDraftService` — comparte cliente
 * OpenAI y patrones, sin la capa de persistencia/archivos.
 */
class AiAgendaDraftService
{
    public function __construct(
        private readonly AiAgendaProposalParser $parser,
    ) {}

    /**
     * @return array{proposal: array<string, mixed>, transcription: string|null}
     */
    public function draft(User $user, ?string $text, ?UploadedFile $audioFile): array
    {
        if (trim((string) $text) === '' && $audioFile === null) {
            throw new \InvalidArgumentException('Se requiere al menos texto o audio.');
        }

        $client = OpenAiClient::fromConfig();

        $transcription = null;
        if ($audioFile !== null) {
            $transcription = $client->transcribeAudio(
                audioBytes: (string) file_get_contents($audioFile->getRealPath()),
                filename: $audioFile->getClientOriginalName() ?: 'nota-de-voz.webm',
                mimeType: (string) ($audioFile->getMimeType() ?: 'audio/webm'),
                model: (string) config('ai.expenses.transcription_model', 'whisper-1'),
                language: (string) config('ai.expenses.transcription_language', 'es'),
            );
        }

        $combinedText = $this->combineInputText($text, $transcription);
        $payload = $this->buildOpenAiPayload($combinedText);
        $response = $client->chatCompletions($payload);

        $proposalRaw = $this->extractJsonFromResponse($response);
        $proposal = $this->parser->parse($proposalRaw);

        return [
            'proposal' => $proposal,
            'transcription' => $transcription,
        ];
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
     * @return array<string, mixed>
     */
    private function buildOpenAiPayload(?string $inputText): array
    {
        $now = Carbon::now('America/Mexico_City');

        $userContent = 'HOY (zona America/Mexico_City): '.$now->toIso8601String()
            .' ('.$now->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm').')'
            ."\n\nTEXTO DEL USUARIO:\n<<<\n".(string) $inputText."\n>>>";

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
Eres un asistente que convierte una nota dictada (texto y/o audio ya transcrito) en un ÍTEM DE AGENDA para un usuario de una carnicería en México. La nota suele ser un recordatorio, tarea, evento o nota breve, p.ej. "recuérdame entregar carne a las 2pm mañana".

Recibes "HOY" con la fecha y hora actual en la zona America/Mexico_City. Úsalo para resolver expresiones relativas ("mañana", "el viernes", "en 2 horas", "a las 2pm") a una fecha/hora CONCRETA. Devuelve las fechas en ISO8601 con la hora local de México.

Devuelves SIEMPRE un único objeto JSON (sin envoltorio, sin markdown) con esta forma exacta:

{
  "type": "task" | "event" | "note",   // task = tarea/recordatorio; event = evento con hora; note = nota libre
  "title": string,                       // título corto y claro (máx 160). Obligatorio.
  "body": string|null,                   // detalle adicional si lo hay
  "scope": "personal" | "branch" | "company",  // por defecto "personal" salvo que sea claramente de la sucursal/empresa
  "starts_at": "ISO8601"|null,           // tarea: vencimiento; evento: inicio; nota: opcional
  "ends_at": "ISO8601"|null,             // sólo eventos con fin explícito
  "remind_at": "ISO8601"|null,           // cuándo recordar; si no se especifica, igual a starts_at para tareas/eventos con hora
  "recurrence": "none" | "daily" | "weekly" | "monthly",  // por defecto "none"
  "priority": "low" | "normal" | "high" | null,  // sólo tareas; null si no aplica
  "confianza": "alta" | "media" | "baja"
}

Reglas:
- NUNCA asignes la tarea a una persona. Aunque la nota mencione un nombre ("dile a Juan…", "que María entregue…"), IGNORA el nombre para asignación. No incluyas ningún campo de asignado. La asignación es manual.
- Si la nota menciona una persona, puedes incluir ese contexto dentro de "title" o "body" como texto, pero nunca como asignación.
- Si no hay hora explícita pero sí día ("mañana"), usa una hora razonable (09:00) o deja la hora que se infiera; si no hay fecha, deja starts_at en null.
- Elige "event" cuando hay una cita/evento con hora; "task" para recordatorios/pendientes; "note" para apuntes sin acción.
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
