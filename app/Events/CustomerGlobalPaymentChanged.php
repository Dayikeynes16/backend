<?php

namespace App\Events;

use App\Models\CustomerPayment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un cobro global FIFO cambió el saldo de varias ventas de un cliente de una
 * sola vez — al aplicarse (`applied`) o al cancelarse (`reverted`).
 *
 * Sustituye a la ráfaga de `SaleUpdated` que se emitía antes, uno por venta
 * tocada: en la web cada uno disparaba su propia recarga completa de la mesa de
 * trabajo, así que un cobro que saldaba diez ventas provocaba diez recargas
 * seguidas.
 *
 * Además de pesar menos, dice algo que la ráfaga no podía decir: que esas N
 * ventas son un mismo movimiento. Con eso una pantalla puede anunciar "se
 * aplicaron $X a 10 ventas" en lugar de parpadear diez veces sin explicación.
 */
class CustomerGlobalPaymentChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, int>  $saleIds
     * @param  'applied'|'reverted'  $action
     */
    public function __construct(
        public CustomerPayment $payment,
        public array $saleIds,
        public string $action = 'applied',
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("sucursal.{$this->payment->branch_id}");
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'customer_payment_id' => $this->payment->id,
            'folio' => $this->payment->folio,
            'customer_id' => $this->payment->customer_id,
            'action' => $this->action,
            'amount_applied' => (float) $this->payment->amount_applied,
            'sales_affected_count' => count($this->saleIds),
            'sale_ids' => $this->saleIds,
        ];
    }
}
