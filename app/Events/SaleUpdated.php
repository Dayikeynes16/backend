<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una venta existente cambió: se cobró, se editaron sus items, se canceló, se
 * reabrió, se le asignó un cliente, o un cobro global FIFO la tocó.
 *
 * **Es una señal, no un transporte de datos.** Avisa de que algo cambió; los
 * datos definitivos se leen por HTTP. Antes cargaba cuatro relaciones y
 * serializaba la venta entera para que los dos únicos consumidores —la mesa de
 * trabajo de la web y la del hub— tiraran el payload y pidieran lo mismo otra
 * vez. Es el evento de mayor tráfico del sistema, así que ese trabajo se
 * multiplicaba: un cobro global FIFO que salda diez ventas lo emitía diez veces.
 *
 * Los identificadores que van dentro son los justos para que un consumidor
 * pueda decidir si el cambio le interesa sin tener que pedir nada.
 */
class SaleUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Sale $sale) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("sucursal.{$this->sale->branch_id}");
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'sale_id' => $this->sale->id,
            'folio' => $this->sale->folio,
            'status' => $this->sale->status->value,
        ];
    }
}
