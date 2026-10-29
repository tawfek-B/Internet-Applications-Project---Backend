<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
