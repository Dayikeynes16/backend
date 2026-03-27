<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleUnlocked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $saleId,
        public int $branchId,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("sucursal.{$this->branchId}");
    }

    public function broadcastWith(): array
    {
        return ['sale_id' => $this->saleId];
    }
}
