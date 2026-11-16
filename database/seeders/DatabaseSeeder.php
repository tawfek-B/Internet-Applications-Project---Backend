<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryClientInterface;
use App\Services\Inventory\InventoryException;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        User::factory()->create([
            'name' => 'Customer User',
            'email' => 'customer@example.com',
        ]);

        /** @var InventoryClientInterface $inventory */
        $inventory = app(InventoryClientInterface::class);

        Product::factory(1000)->create();
        
    }
}
