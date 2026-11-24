<?php
// app/Events/OrderStatusChanged.php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('orders.' . $this->order->id),
            new PrivateChannel('admin.orders'),
        ];
        \Log::info('Broadcasting on channels', [
            'channels' => array_map(fn ($c) => $c->name, $channels),
            'order_id' => $this->order->id
        ]);
        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'status' => $this->order->status->value ?? $this->order->status,
            'user_id' => $this->order->user_id,
            'updated_at' => $this->order->updated_at?->toISOString(),
        ];
    }
}