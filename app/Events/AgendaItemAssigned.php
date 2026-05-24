<?php

namespace App\Events;

use App\Models\AgendaItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AgendaItemAssigned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public AgendaItem $item, public int $notifyUserId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("agenda.user.{$this->notifyUserId}");
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->item->id,
            'title' => $this->item->title,
            'type' => $this->item->type->value,
        ];
    }
}
