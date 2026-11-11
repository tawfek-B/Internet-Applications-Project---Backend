<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\LargeDatasetSeeder;

function smallLargeDatasetSeeder(): LargeDatasetSeeder
{
    $seeder = new LargeDatasetSeeder;
    $seeder->userCount = 5;
    $seeder->productCount = 30;
    $seeder->orderCount = 12;
    $seeder->maxItemsPerOrder = 3;
    $seeder->chunkSize = 7;

    return $seeder;
}

it('creates the configured number of users, products and orders', function () {
    smallLargeDatasetSeeder()->run();

    expect(User::query()->count())->toBe(5)
        ->and(Product::query()->count())->toBe(30)
        ->and(Order::query()->count())->toBe(12);
});

it('gives every order between one and the maximum number of items', function () {
    smallLargeDatasetSeeder()->run();

    $itemCounts = OrderItem::query()
        ->selectRaw('order_id, COUNT(*) as item_count')
        ->groupBy('order_id')
        ->pluck('item_count');

    expect($itemCounts)->toHaveCount(12);

    $itemCounts->each(fn ($count) => expect((int) $count)->toBeBetween(1, 3));
});

it('stores each order item at its product price', function () {
    smallLargeDatasetSeeder()->run();

    $item = OrderItem::query()->with('product')->firstOrFail();

    expect((float) $item->unit_price)->toBe((float) $item->product->price);
});

it('adds rows on top of existing data without touching it', function () {
    $existingProduct = Product::factory()->create(['name' => 'Keep me']);

    smallLargeDatasetSeeder()->run();

    expect(Product::query()->count())->toBe(31)
        ->and($existingProduct->fresh()->name)->toBe('Keep me');
});
