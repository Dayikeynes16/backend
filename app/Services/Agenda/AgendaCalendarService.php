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

        /*
         * Una ocurrencia ya completada no se proyecta hacia el futuro.
         *
         * La recurrencia se materializa al completar: `AgendaController@complete`
         * marca esta fila y clona la siguiente ocurrencia como fila nueva y viva.
         * Cada fila representa, por tanto, UNA ocurrencia concreta. Expandir
         * también las completadas ponía la misma tarea tachada en todos los días
         * del calendario —pasados y futuros— y hacía que el mes entero se viera
         * idéntico, con lo hecho una vez dándose por hecho para siempre.
         *
         * La fila viva sí se expande, así que lo pendiente se sigue viendo en los
         * días que vienen. El pasado muestra lo que de verdad ocurrió.
         */
        if ($item->completed_at !== null) {
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
