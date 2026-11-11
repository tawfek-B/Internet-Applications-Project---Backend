<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Catalog\ProductCatalogCache;

class ProductObserver
{
    public function saved(Product $product): void
    {
        ProductCatalogCache::bumpVersion();
    }

    public function deleted(Product $product): void
    {
        ProductCatalogCache::bumpVersion();
    }
}
