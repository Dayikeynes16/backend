<?php

namespace App\Services\Agenda;

use App\Enums\AgendaRecurrence;
use App\Models\AgendaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AgendaCalendarService
{
    /**
     * Expande los ítems con fecha (y sus recurrencias) en ocurrencias dentro
     * del rango [from, to]. NO materializa filas: trabaja en memoria.
     *
     * @return array<int, array{item: AgendaItem, starts_at: Carbon}>
     */
    public function expand(Builder $query, Carbon $from, Carbon $to): array
    {
        $items = (clone $query)->whereNotNull('starts_at')->get();
        $occurrences = [];

        foreach ($items as $item) {
            foreach ($this->occurrencesFor($item, $from, $to) as $date) {
                $occurrences[] = ['item' => $item, 'starts_at' => $date];
            }
        }

        usort($occurrences, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $occurrences;
    }

    /**
     * @return array<int, Carbon>
     */
    private function occurrencesFor(AgendaItem $item, Carbon $from, Carbon $to): array
    {
        $base = $item->starts_at->copy();
        $recurrence = $item->recurrence ?? AgendaRecurrence::None;

        if ($recurrence === AgendaRecurrence::None) {
            return ($base->betweenIncluded($from, $to)) ? [$base] : [];
        }

        $until = $item->recurrence_until?->copy()->endOfDay();
        $cursor = $base->copy();
        $dates = [];
        $guard = 0;

        // Avanza hasta entrar al rango.
        while ($cursor->lt($from) && $guard++ < 1000) {
            if ($until && $cursor->gt($until)) {
                return [];
            }
            $cursor = $recurrence->advance($cursor);
        }

        // Recolecta dentro del rango.
        while ($cursor->lte($to) && $guard++ < 1000) {
            if ($until && $cursor->gt($until)) {
                break;
            }
            $dates[] = $cursor->copy();
            $cursor = $recurrence->advance($cursor);
        }

        return $dates;
    }
}
