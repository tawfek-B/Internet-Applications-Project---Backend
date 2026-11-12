<?php

use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductCatalogCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['catalog.cache_enabled' => true]);
    Cache::flush();
    ProductCatalogCache::bumpVersion();
});

it('returns an etag for product list and honors if-none-match with 304', function () {
    Product::factory()->count(2)->create();

    $first = $this->getJson('/api/v1/products');

    $first->assertOk()
        ->assertHeader('ETag');

    $etag = (string) $first->headers->get('ETag');

    $this->getJson('/api/v1/products', [
        'If-None-Match' => $etag,
    ])->assertStatus(304)
        ->assertHeader('ETag', $etag);
});

it('returns a new etag after a product is updated', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['name' => 'Before']);

    $first = $this->getJson("/api/v1/products/{$product->id}");
    $etag = (string) $first->headers->get('ETag');

    $this->patchJson("/api/v1/products/{$product->id}", [
        'name' => 'After',
    ], withBearerToken($admin))->assertOk();

    $second = $this->getJson("/api/v1/products/{$product->id}");

    $second->assertOk()
        ->assertJsonPath('product.name', 'After')
        ->assertHeader('ETag');

    expect($second->headers->get('ETag'))->not->toBe($etag);

    $this->getJson("/api/v1/products/{$product->id}", [
        'If-None-Match' => $etag,
    ])->assertOk()
        ->assertJsonPath('product.name', 'After');
});

it('caches product list pages in redis or array store', function () {
    Product::factory()->count(3)->create();

    $this->getJson('/api/v1/products?per_page=2')->assertOk();

    $version = ProductCatalogCache::version();

    expect(Cache::has("catalog:products:v{$version}:page:1:per:2:cat:_all"))->toBeTrue();
});

it('returns a new etag when only the stock changes', function () {
    $product = Product::factory()->create();
    seedInventory($product->id, 10);

    $etag = (string) $this->getJson("/api/v1/products/{$product->id}")->headers->get('ETag');

    seedInventory($product->id, 3);

    $this->getJson("/api/v1/products/{$product->id}", [
        'If-None-Match' => $etag,
    ])->assertOk()
        ->assertJsonPath('product.stock', 3);
});
