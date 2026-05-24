<?php

namespace App\Services\Ai;

use App\Enums\AgendaItemType;
use App\Enums\AgendaPriority;
use App\Enums\AgendaRecurrence;
use App\Enums\AgendaScope;
use Illuminate\Support\Carbon;

/**
 * Normaliza el JSON crudo que devuelve la IA al dictar un ítem de agenda.
 *
 * Es la línea de defensa que garantiza que sólo lleguen al frontend valores
 * válidos:
 *  - `type`/`scope`/`recurrence`/`priority` se acotan ("clamp") a su enum; si
 *    la IA inventa otro valor, cae al default razonable (o null en priority).
 *  - las fechas se parsean en `America/Mexico_City` y se devuelven en ISO8601
 *    (la zona se descarta si el valor es inválido).
 *  - NUNCA incluye un asignado: la asignación a personas es manual en el modal,
 *    nunca por voz (decisión de diseño).
 *
 * El resultado es la "propuesta" que el usuario revisa/edita y confirma; nada
 * se persiste hasta que guarda en `AgendaItemModal`.
 *
 * Espejo de `AiPurchaseProposalParser` — comparte estilo de saneo.
 */
class AiAgendaProposalParser
{
    private const CONFIDENCE_LEVELS = ['alta', 'media', 'baja'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function parse(array $raw): array
    {
        $type = $this->cleanType($raw['type'] ?? null);

        return [
            'type' => $type,
            'title' => $this->cleanString($raw['title'] ?? null, 160),
            'body' => $this->cleanString($raw['body'] ?? null, 5000),
            'scope' => $this->cleanScope($raw['scope'] ?? null),
            'starts_at' => $this->cleanDateTime($raw['starts_at'] ?? null),
            'ends_at' => $this->cleanDateTime($raw['ends_at'] ?? null),
            'remind_at' => $this->cleanDateTime($raw['remind_at'] ?? null),
            'recurrence' => $this->cleanRecurrence($raw['recurrence'] ?? null),
            'priority' => $type === AgendaItemType::Task->value
                ? $this->cleanPriority($raw['priority'] ?? null)
                : null,
            'confianza' => $this->cleanConfidence($raw['confianza'] ?? null) ?? 'baja',
        ];
    }

    private function cleanType(mixed $value): string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';
        $valid = array_map(fn (AgendaItemType $t) => $t->value, AgendaItemType::cases());

        return in_array($candidate, $valid, true) ? $candidate : AgendaItemType::Task->value;
    }

    private function cleanScope(mixed $value): string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';
        $valid = array_map(fn (AgendaScope $s) => $s->value, AgendaScope::cases());

        return in_array($candidate, $valid, true) ? $candidate : AgendaScope::Personal->value;
    }

    private function cleanRecurrence(mixed $value): string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';
        $valid = array_map(fn (AgendaRecurrence $r) => $r->value, AgendaRecurrence::cases());

        return in_array($candidate, $valid, true) ? $candidate : AgendaRecurrence::None->value;
    }

    private function cleanPriority(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $candidate = strtolower(trim($value));
        $valid = array_map(fn (AgendaPriority $p) => $p->value, AgendaPriority::cases());

        return in_array($candidate, $valid, true) ? $candidate : null;
    }

    private function cleanString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    /**
     * Parsea una fecha/hora que la IA resolvió (a partir del "hoy" que le dimos)
     * en la zona del negocio y la devuelve en ISO8601. Devuelve null si el valor
     * no es interpretable.
     */
    private function cleanDateTime(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        try {
            return Carbon::parse($trimmed, 'America/Mexico_City')->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanConfidence(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $level = strtolower(trim($value));

        return in_array($level, self::CONFIDENCE_LEVELS, true) ? $level : null;
    }
}
