<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PerformanceSeedBulkCommand extends Command
{
    protected $signature = 'performance:seed-bulk
        {--products=5000 : Number of products to ensure exist}
        {--orders=100000 : Number of orders to insert}
        {--chunk=1000 : Rows per insert batch}';

    protected $description = 'Seed a large catalog and order history for performance testing (uses write DB connection).';

    public function handle(): int
    {
        $productTarget = max((int) $this->option('products'), 1);
        $orderTarget = max((int) $this->option('orders'), 0);
        $chunk = max((int) $this->option('chunk'), 100);

        $this->info("Ensuring at least {$productTarget} products...");
        $this->seedProducts($productTarget);

        if ($orderTarget === 0) {
            $this->info('Done (products only).');

            return self::SUCCESS;
        }

        $userId = User::query()->value('id');

        if ($userId === null) {
            $this->error('No users found. Run db:seed first.');

            return self::FAILURE;
        }

        $productIds = Product::query()->orderBy('id')->pluck('id')->all();

        if ($productIds === []) {
            $this->error('No products available.');

            return self::FAILURE;
        }

        $this->info("Inserting {$orderTarget} orders for user_id={$userId}...");
        $bar = $this->output->createProgressBar($orderTarget);
        $bar->start();

        $created = 0;

        while ($created < $orderTarget) {
            $batchSize = min($chunk, $orderTarget - $created);
            $orderRows = [];
            $now = now();

            for ($i = 0; $i < $batchSize; $i++) {
                $orderRows[] = [
                    'user_id' => $userId,
                    'status' => 'pending',
                    'reservation_id' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::connection()->transaction(function () use ($orderRows, $productIds, $bar, $batchSize): void {
                $maxIdBefore = (int) DB::table('orders')->max('id');

                DB::table('orders')->insert($orderRows);

                $firstId = $maxIdBefore + 1;
                $itemRows = [];

                for ($offset = 0; $offset < $batchSize; $offset++) {
                    $orderId = $firstId + $offset;
                    $productId = $productIds[array_rand($productIds)];

                    $itemRows[] = [
                        'order_id' => $orderId,
                        'product_id' => $productId,
                        'quantity' => random_int(1, 3),
                        'unit_price' => 10.00,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::table('order_items')->insert($itemRows);
                $bar->advance($batchSize);
            });

            $created += $batchSize;
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Bulk performance data ready.');
        $this->line('Run: php artisan performance:explain-orders');
        $this->line('Run: k6 run scripts/load/k6-products.js');

        return self::SUCCESS;
    }

    private function seedProducts(int $target): void
    {
        $existing = Product::query()->count();

        if ($existing >= $target) {
            $this->line("Products already at {$existing}.");

            return;
        }

        $missing = $target - $existing;
        $bar = $this->output->createProgressBar($missing);
        $bar->start();

        $chunk = 500;
        $created = 0;

        while ($created < $missing) {
            $batch = min($chunk, $missing - $created);
            $rows = [];
            $now = now();

            for ($i = 0; $i < $batch; $i++) {
                $rows[] = [
                    'name' => 'Bulk Product '.($existing + $created + $i + 1),
                    'description' => 'Generated for performance testing',
                    'price' => random_int(5, 500) + (random_int(0, 99) / 100),
                    'stock' => random_int(0, 50),
                    'category' => 'bulk',
                    'image_url' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('products')->insert($rows);
            $created += $batch;
            $bar->advance($batch);
        }

        $bar->finish();
        $this->newLine();
    }
}
