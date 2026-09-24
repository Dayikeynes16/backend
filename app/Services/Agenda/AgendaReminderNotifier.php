<?php

namespace App\Services\Agenda;

use App\Models\AgendaItem;
use App\Models\User;
use App\Notifications\AgendaItemAssignedNotice;
use App\Notifications\AgendaReminderDue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La agenda como fuente de avisos: a quién le toca un recordatorio, cómo se
 * envía y cómo se apaga cuando el ítem se atiende.
 *
 * Vive aparte (como SaleCancellationNotifier) porque lo usan el comando por
 * minuto y varias acciones de AgendaController; repetir la regla del
 * destinatario en cada sitio es la forma de que uno se desvíe.
 *
 * Nada de esto puede tumbar una acción ya guardada: el broadcast es síncrono y
 * puede lanzar con Reverb caído, así que cada envío se registra y se sigue.
 */
class AgendaReminderNotifier
{
    /**
     * Quien puede actuar sobre el recordatorio: el asignado o, si no hay, quien
     * lo creó. Son los únicos a los que AgendaItemPolicy deja completarlo o
     * posponerlo; a cualquier otro sus botones le responderían 403.
     */
    public function recipient(AgendaItem $item): ?User
    {
        $userId = $item->assigned_to_user_id ?? $item->user_id;

        if (! $userId) {
            return null;
        }

        // Siempre del mismo tenant que el ítem: un id cruzado no debe filtrar
        // un aviso a otra empresa.
        return User::query()
            ->where('tenant_id', $item->tenant_id)
            ->find($userId);
    }

    /**
     * Avisa un recordatorio vencido. Primero marca y después envía: se avisa
     * como mucho una vez; si el envío falla no se reintenta (con Reverb caído,
     * reintentar cada minuto duplicaría filas). La fila se guarda antes que el
     * broadcast, así que el aviso queda en la bandeja igualmente.
     */
    public function remind(AgendaItem $item): void
    {
        $item->forceFill(['reminder_notified_at' => now()])->saveQuietly();

        $this->guard(function () use ($item) {
            $this->recipient($item)?->notify(new AgendaReminderDue($item));
        }, 'reminder', $item);
    }

    /** Avisa al asignado, salvo que sea quien hace la asignación. */
    public function assigned(AgendaItem $item, User $by): void
    {
        $this->guard(function () use ($item, $by) {
            if (! $item->assigned_to_user_id || $item->assigned_to_user_id === $by->id) {
                return;
            }

            User::query()
                ->where('tenant_id', $item->tenant_id)
                ->find($item->assigned_to_user_id)
                ?->notify(new AgendaItemAssignedNotice($item, $by->name));
        }, 'assigned', $item);
    }

    /**
     * Marca leídos los avisos de recordatorio pendientes de este ítem: atenderlo
     * en la pantalla Agenda apaga también la isla.
     *
     * @return int cuántos avisos se cerraron
     */
    public function close(AgendaItem $item): int
    {
        return DatabaseNotification::query()
            ->where('type', AgendaReminderDue::class)
            ->whereNull('read_at')
            // `data` es text en PostgreSQL: sin el cast a jsonb, `data->item_id` no compila.
            ->whereRaw("(data::jsonb->>'item_id')::bigint = ?", [$item->id])
            ->update(['read_at' => now()]);
    }

    /** Deja el recordatorio listo para volver a avisarse en su nuevo vencimiento. */
    public function rearm(AgendaItem $item): void
    {
        $item->forceFill([
            'reminder_notified_at' => null,
            'reminder_seen_at' => null,
        ])->saveQuietly();
    }

    /**
     * Marca como ya avisados los recordatorios de hace más de 24 h, para que al
     * desplegar la isla no anuncie de golpe recordatorios de hace días. Lo
     * llama la migración que crea `reminder_notified_at`.
     *
     * @return int cuántos ítems se marcaron
     */
    public static function backfillOld(): int
    {
        // Query builder, no Eloquent: en una migración no hay tenant enlazado
        // y debe alcanzar también a los ítems borrados.
        return DB::table('agenda_items')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<', now()->subDay())
            ->whereNull('reminder_notified_at')
            ->update(['reminder_notified_at' => now()]);
    }

    private function guard(callable $fn, string $step, AgendaItem $item): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning("Aviso de agenda ({$step}) falló", [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
