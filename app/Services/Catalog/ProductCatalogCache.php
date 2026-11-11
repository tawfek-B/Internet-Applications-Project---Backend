<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Cache;

class ProductCatalogCache
{
    private const string VERSION_KEY = 'catalog:products:version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bumpVersion(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 2);

            return;
        }

        Cache::increment(self::VERSION_KEY);
    }

    /**
     * @return array{paginator: LengthAwarePaginator, etag: string}|null
     */
    public static function rememberProductPage(Request $request): ?array
    {
        if (! self::cachingEnabled()) {
            return null;
        }

        $page = max((int) $request->input('page', 1), 1);
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $category = $request->filled('category')
            ? $request->string('category')->toString()
            : '_all';

        $key = sprintf(
            'catalog:products:v%d:page:%d:per:%d:cat:%s',
            self::version(),
            $page,
            $perPage,
            $category,
        );

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['items'], $cached['etag'])) {
            return [
                'paginator' => self::paginatorFromAttributes($cached),
                'etag' => (string) $cached['etag'],
            ];
        }

        $query = Product::query()->select([
            'id',
            'name',
            'description',
            'price',
            'stock',
            'category',
            'image_url',
            'created_at',
            'updated_at',
        ])->latest('id');

        if ($request->filled('category')) {
            $query->where('category', $category);
        }

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $etag = self::hashMaterial([
            'list',
            $page,
            $perPage,
            $category,
            $paginator->total(),
            $paginator->currentPage(),
            self::fingerprintProducts($paginator->items()),
        ]);

        $payload = [
            'items' => array_map(
                fn (Product $product): array => $product->getAttributes(),
                $paginator->items(),
            ),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'etag' => $etag,
        ];

        Cache::put($key, $payload, self::ttlSeconds());

        return [
            'paginator' => $paginator,
            'etag' => $etag,
        ];
    }

    /**
     * @return array{product: Product, etag: string}|null
     */
    public static function rememberProduct(Product $product): ?array
    {
        if (! self::cachingEnabled()) {
            return null;
        }

        $key = sprintf('catalog:products:v%d:show:%d', self::version(), $product->id);

        /** @var array<string, mixed>|null $cached */
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['attributes'], $cached['etag'])) {
            return [
                'product' => self::productFromAttributes($cached['attributes']),
                'etag' => (string) $cached['etag'],
            ];
        }

        $fresh = Product::query()
            ->select([
                'id',
                'name',
                'description',
                'price',
                'stock',
                'category',
                'image_url',
                'created_at',
                'updated_at',
            ])
            ->whereKey($product->id)
            ->firstOrFail();

        $etag = self::hashMaterial([
            'show',
            $fresh->id,
            $fresh->updated_at?->timestamp ?? 0,
        ]);

        Cache::put($key, [
            'attributes' => $fresh->getAttributes(),
            'etag' => $etag,
        ], self::ttlSeconds());

        return [
            'product' => $fresh,
            'etag' => $etag,
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private static function paginatorFromAttributes(array $cached): LengthAwarePaginator
    {
        /** @var list<array<string, mixed>> $items */
        $items = is_array($cached['items']) ? $cached['items'] : [];

        return new Paginator(
            Product::hydrate($items),
            (int) ($cached['total'] ?? 0),
            max((int) ($cached['per_page'] ?? 15), 1),
            max((int) ($cached['current_page'] ?? 1), 1),
        );
    }

    private static function productFromAttributes(mixed $attributes): Product
    {
        /** @var array<string, mixed> $row */
        $row = is_array($attributes) ? $attributes : [];

        return (new Product)->newFromBuilder($row);
    }

    public static function cachingEnabled(): bool
    {
        return (bool) config('catalog.cache_enabled', true);
    }

    public static function ttlSeconds(): int
    {
        return max((int) config('catalog.cache_ttl_seconds', 300), 60);
    }

    /**
     * @param  list<mixed>  $material
     */
    public static function hashMaterial(array $material): string
    {
        return '"'.hash('xxh3', json_encode($material, JSON_THROW_ON_ERROR)).'"';
    }

    /**
     * @param  iterable<int, Product>  $products
     */
    private static function fingerprintProducts(iterable $products): string
    {
        $parts = [];

        foreach ($products as $product) {
            $parts[] = $product->id.'@'.($product->updated_at?->timestamp ?? 0);
        }

        return implode(',', $parts);
    }
}
