<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Jobs\SendOrderConfirmationEmail;

class SendOrderEmailListener
{
    public function handle(OrderCreated $event): void
    {
        SendOrderConfirmationEmail::dispatch($event->order);
    }
}