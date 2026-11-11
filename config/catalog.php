<?php

return [

    'cache_enabled' => env('CATALOG_CACHE_ENABLED', true),

    'cache_ttl_seconds' => (int) env('CATALOG_CACHE_TTL', 300),

];
