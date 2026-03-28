<?php

namespace App\Events;

use App\Http\Resources\SaleResource;
use App\Models\Sale;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewExternalSale implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Sale $sale) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("sucursal.{$this->sale->branch_id}");
    }

    public function broadcastWith(): array
    {
        $this->sale->load('items');

        return [
            'sale' => SaleResource::make($this->sale)->toArray(request()),
        ];
    }
}
