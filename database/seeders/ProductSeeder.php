<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\Inventory\InventoryClientInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        $total = 20000;
        $chunkSize = 1000;
        $now = now()->toDateTimeString();

        for ($i = 0; $i < $total; $i += $chunkSize) {
            $products = Product::factory($chunkSize)
                ->make()
                ->map(fn ($product) => [
                    ...$product->getAttributes(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            Product::insert($products);
        }

        $inventoryClient = app(InventoryClientInterface::class);

        Product::select(['id', 'stock'])->chunkById($chunkSize, function ($products) use ($inventoryClient) {
            foreach ($products as $product) {
                $inventoryClient->setQuantity($product->id, $product->stock ?? 20);
            }
        });
    }
}
