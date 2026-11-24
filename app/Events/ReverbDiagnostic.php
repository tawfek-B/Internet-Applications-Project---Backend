<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class ReverbDiagnostic implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function broadcastOn(): array
    {
        return [new Channel('diagnostics')];
    }

    public function broadcastAs(): string
    {
        return 'diagnostic.message';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => 'Reverb is receiving broadcasts!',
            'timestamp' => now()->toISOString(),
        ];
    }
}