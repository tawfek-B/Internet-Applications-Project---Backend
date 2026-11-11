<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

trait ForcesPrimaryRead
{
    protected function forcePrimaryConnection(): void
    {
        DB::connection()->useWriteConnectionWhenReading();
    }
}