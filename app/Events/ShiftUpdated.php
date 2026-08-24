<?php

namespace App\Events;

use App\Models\CashRegisterShift;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El turno de caja cambió: se abrió, se cerró, o entró o salió un retiro.
 *
 * Como `SaleUpdated`, es una señal y no un transporte de datos. Va al canal de
 * la sucursal porque es el único que existe, pero **no lleva cifras**: quien lo
 * recibe pide su propio turno por HTTP, y ese endpoint sólo devuelve el del
 * usuario autenticado. Así el aviso puede viajar por un canal compartido sin
 * que el dinero de un cajero se asome al panel de otro.
 *
 * Lo que cambia el esperado en caja minuto a minuto son los cobros, y ésos ya
 * emiten `SaleUpdated`: el panel de turno escucha los dos y no hacía falta
 * inventar un evento por cobro.
 */
class ShiftUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'opened'|'closed'|'withdrawal'  $reason
     */
    public function __construct(
        public CashRegisterShift $shift,
        public string $reason,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("sucursal.{$this->shift->branch_id}");
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'shift_id' => $this->shift->id,
            'user_id' => $this->shift->user_id,
            'reason' => $this->reason,
        ];
    }
}
