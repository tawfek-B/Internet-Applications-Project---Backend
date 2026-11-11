<?php

namespace Database\Seeders;

use App\Services\Catalog\ProductCatalogCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fills the database with a large volume of fake data for performance testing
 * (EXPLAIN analysis and k6 load tests).
 *
 * Rows are written with chunked bulk inserts instead of model factories, because
 * creating hundreds of thousands of Eloquent models one by one takes far too long.
 *
 * Usage: php artisan db:seed --class=LargeDatasetSeeder
 */
class LargeDatasetSeeder extends Seeder
{
    public int $userCount = 1_000;

    public int $productCount = 200_000;

    public int $orderCount = 100_000;

    public int $maxItemsPerOrder = 4;

    public int $chunkSize = 1_000;

    /**
     * @var list<string>
     */
    private const array CATEGORIES = ['electronics', 'books', 'home', 'general', 'sports', 'toys', 'beauty', 'garden'];

    /**
     * @var list<string>
     */
    private const array ADJECTIVES = ['Smart', 'Classic', 'Compact', 'Premium', 'Eco', 'Portable', 'Deluxe', 'Mini'];

    /**
     * @var list<string>
     */
    private const array NOUNS = ['Lamp', 'Chair', 'Phone', 'Novel', 'Kettle', 'Backpack', 'Watch', 'Speaker', 'Mug', 'Ball'];

    /**
     * @var list<string>
     */
    private const array ORDER_STATUSES = ['pending', 'delivering', 'completed', 'cancelled'];

    public function run(): void
    {
        DB::disableQueryLog();

        $userIds = $this->seedUsers();
        $productPrices = $this->seedProducts();
        $this->seedOrdersWithItems($userIds, $productPrices);

        ProductCatalogCache::bumpVersion();
    }

    /**
     * @return list<int>
     */
    private function seedUsers(): array
    {
        $lastExistingId = (int) DB::table('users')->max('id');
        $password = Hash::make('password');
        $now = now();
        $batchTag = Str::lower(Str::random(6));

        $rows = [];

        for ($index = 1; $index <= $this->userCount; $index++) {
            $rows[] = [
                'name' => "Load Test User {$index}",
                'email' => "loadtest-{$batchTag}-{$index}@example.com",
                'email_verified_at' => $now,
                'password' => $password,
                'is_admin' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $rows = $this->flushWhenFull('users', $rows);
        }

        $this->flush('users', $rows);

        return DB::table('users')->where('id', '>', $lastExistingId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return array<int, float> Product prices keyed by product id.
     */
    private function seedProducts(): array
    {
        $lastExistingId = (int) DB::table('products')->max('id');
        $rows = [];

        for ($index = 1; $index <= $this->productCount; $index++) {
            $createdAt = $this->randomPastDate();
            $name = self::ADJECTIVES[array_rand(self::ADJECTIVES)].' '.self::NOUNS[array_rand(self::NOUNS)]." #{$index}";

            $rows[] = [
                'name' => $name,
                'description' => "Generated product {$index} for performance testing.",
                'price' => random_int(100, 50_000) / 100,
                'category' => self::CATEGORIES[array_rand(self::CATEGORIES)],
                'stock' => random_int(0, 500),
                'image_url' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $rows = $this->flushWhenFull('products', $rows);
        }

        $this->flush('products', $rows);

        return DB::table('products')
            ->where('id', '>', $lastExistingId)
            ->pluck('price', 'id')
            ->map(fn ($price): float => (float) $price)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<int, float>  $productPrices
     */
    private function seedOrdersWithItems(array $userIds, array $productPrices): void
    {
        if ($userIds === [] || $productPrices === []) {
            return;
        }

        $productIds = array_keys($productPrices);
        $remaining = $this->orderCount;

        while ($remaining > 0) {
            $batchSize = min($this->chunkSize, $remaining);
            $lastExistingOrderId = (int) DB::table('orders')->max('id');

            $orderRows = [];

            for ($index = 0; $index < $batchSize; $index++) {
                $createdAt = $this->randomPastDate();

                $orderRows[] = [
                    'user_id' => $userIds[array_rand($userIds)],
                    'status' => self::ORDER_STATUSES[array_rand(self::ORDER_STATUSES)],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            DB::table('orders')->insert($orderRows);

            $newOrderIds = DB::table('orders')->where('id', '>', $lastExistingOrderId)->pluck('id');
            $itemRows = [];

            foreach ($newOrderIds as $orderId) {
                $itemCount = random_int(1, $this->maxItemsPerOrder);

                for ($item = 0; $item < $itemCount; $item++) {
                    $productId = $productIds[array_rand($productIds)];

                    $itemRows[] = [
                        'order_id' => $orderId,
                        'product_id' => $productId,
                        'quantity' => random_int(1, 5),
                        'unit_price' => $productPrices[$productId],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $itemRows = $this->flushWhenFull('order_items', $itemRows);
                }
            }

            $this->flush('order_items', $itemRows);

            $remaining -= $batchSize;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function flushWhenFull(string $table, array $rows): array
    {
        if (count($rows) < $this->chunkSize) {
            return $rows;
        }

        $this->flush($table, $rows);

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(string $table, array $rows): void
    {
        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
    }

    private function randomPastDate(): Carbon
    {
        return now()->subMinutes(random_int(0, 60 * 24 * 365));
    }
}
