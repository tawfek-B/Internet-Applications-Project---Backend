<?php

namespace App\Services\Cache;

use App\Models\Product;
use App\Services\Inventory\InventoryClientInterface;
use App\Services\Inventory\InventoryException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class ProductCacheService
{
    private const LIST_TTL = 60;
    private const SHOW_TTL = 30;
    private const VERSION_KEY = 'products:version';

    public function __construct(
        private readonly InventoryClientInterface $inventory,
    ) {}

    public function getVersion(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, $this->getVersion() + 1);
    }

    public function rememberList(string $category, int $page, int $perPage, callable $callback): array
    {
        $key = $this->listKey($category, $page, $perPage);

        return Cache::remember($key, self::LIST_TTL, $callback);
    }

    public function rememberProduct(int $productId, callable $callback): array
    {
        $key = $this->showKey($productId);

        return Cache::remember($key, self::SHOW_TTL, $callback);
    }

    public function forgetProduct(int $productId): void
    {
        Cache::forget($this->showKey($productId));
        $this->bumpVersion();
    }

    public function forgetAll(): void
    {
        $this->bumpVersion();
    }

    private function listKey(string $category, int $page, int $perPage): string
    {
        $version = $this->getVersion();
        $cat = $category === '' ? 'all' : $category;
        return "products:list:v{$version}:{$cat}:{$page}:{$perPage}";
    }

    private function showKey(int $productId): string
    {
        $version = $this->getVersion();
        return "products:show:v{$version}:{$productId}";
    }
}