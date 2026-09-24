<?php

namespace App\Console\Commands;

use App\Models\AgendaItem;
use App\Services\Agenda\AgendaReminderNotifier;
use Illuminate\Console\Command;

/**
 * Cada minuto: avisa (isla) los recordatorios de agenda ya vencidos que nadie
 * ha visto ni se han avisado. `reminder_notified_at` garantiza un solo aviso
 * por vencimiento; posponer o editar la hora lo limpia para volver a avisar.
 */
class DispatchAgendaRemindersCommand extends Command
{
    protected $signature = 'agenda:dispatch-reminders';

    protected $description = 'Avisa (isla) los recordatorios de agenda que ya vencieron';

    public function handle(AgendaReminderNotifier $notifier): int
    {
        // Sin `tenant` enlazado TenantScope no filtra (como CheckDevicesCommand).
        // No usar withoutGlobalScopes(): quitaría también SoftDeletes.
        AgendaItem::query()
            ->active()
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->whereNull('reminder_seen_at')
            ->whereNull('reminder_notified_at')
            // Por id y no con each(): remind() saca al ítem de este filtro, y la
            // paginación por offset de each() se saltaría ítems tras el primer lote.
            ->lazyById(200)
            ->each(fn (AgendaItem $item) => $notifier->remind($item));

        return self::SUCCESS;
    }
}
