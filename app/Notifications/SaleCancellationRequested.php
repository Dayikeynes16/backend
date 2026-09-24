<?php

namespace App\Notifications;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Un cajero pidió cancelar una venta. Va a los administradores de su sucursal.
 *
 * Es el aviso que más falta hacía: el cajero quedaba esperando una decisión y
 * el administrador no se enteraba hasta entrar por su cuenta a la pantalla de
 * cancelaciones. Por eso se persiste además de emitirse: si el administrador no
 * estaba conectado, tiene que encontrarlo cuando entre.
 */
class SaleCancellationRequested extends Notification
{
    use Queueable;

    public function __construct(
        public Sale $sale,
        public string $requestedByName,
        public ?string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // `database` garantiza que se pueda ver después; `broadcast` avisa ya.
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sale.cancellation.requested',
            'level' => 'action',
            'title' => 'Solicitud de cancelación',
            'body' => "{$this->requestedByName} pide cancelar la venta {$this->sale->folio}.",
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
        return 'sale.cancellation.requested';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Síncrono: BroadcastNotificationCreated es ShouldBroadcast (encolado) y
        // producción no corre colas, así que sin esto el aviso no salía en vivo.
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
