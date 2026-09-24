<?php

namespace App\Notifications;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * El administrador resolvió una solicitud de cancelación. Va al cajero que la
 * pidió.
 *
 * La otra mitad del hueco: hasta ahora el cajero tampoco se enteraba del
 * desenlace. Se persiste porque la resolución puede llegar cuando ya cerró
 * sesión o cambió de turno, y necesita encontrarla al volver.
 */
class SaleCancellationResolved extends Notification
{
    use Queueable;

    /**
     * @param  'approved'|'rejected'  $outcome
     */
    public function __construct(
        public Sale $sale,
        public string $outcome,
        public string $resolvedByName,
        public ?string $reason = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $approved = $this->outcome === 'approved';

        return [
            'type' => 'sale.cancellation.'.$this->outcome,
            // Aprobada cambia el dinero del turno: el cajero debe verlo, no
            // sólo enterarse de pasada. Rechazada es informativa.
            'level' => $approved ? 'important' : 'info',
            'title' => $approved ? 'Cancelación aprobada' : 'Cancelación rechazada',
            'body' => $approved
                ? "{$this->resolvedByName} canceló la venta {$this->sale->folio}."
                : "{$this->resolvedByName} rechazó cancelar la venta {$this->sale->folio}.",
            'reason' => $this->reason,
            'sale_id' => $this->sale->id,
            'folio' => $this->sale->folio,
            'branch_id' => $this->sale->branch_id,
            'total' => (float) $this->sale->total,
        ];
    }

    /**
     * El `type` que viaja por el socket. Sin esto Laravel pone el nombre de la
     * clase y el aviso en vivo no coincide con el guardado.
     */
    public function broadcastType(): string
    {
        return 'sale.cancellation.'.$this->outcome;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Síncrono: BroadcastNotificationCreated es ShouldBroadcast (encolado) y
        // producción no corre colas, así que sin esto el aviso no salía en vivo.
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
