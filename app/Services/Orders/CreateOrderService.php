<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\InventoryClientInterface;
use App\Services\Inventory\InventoryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreateOrderService
{
    public function __construct(private readonly InventoryClientInterface $inventory) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    public function create(User $user, array $items): Order
    {
        $normalized = $this->normalizeItems($items);
        $reservationId = (string) Str::uuid();

        try {
            $this->inventory->reserve($reservationId, $normalized);
        } catch (InventoryException $exception) {
            throw $exception;
        }

        try {
            return DB::transaction(function () use ($user, $normalized, $reservationId) {
                $order = Order::query()->create([
                    'user_id' => $user->id,
                    'status' => OrderStatus::Pending,
                    'reservation_id' => $reservationId,
                ]);

                $total = 0.0;

                foreach ($normalized as $line) {
                    /** @var Product $product */
                    $product = Product::query()->lockForUpdate()->findOrFail($line['product_id']);

                    $lineTotal = (float) $product->price * $line['quantity'];
                    $total += $lineTotal;

                    $order->orderItems()->create([
                        'product_id' => $product->id,
                        'quantity' => $line['quantity'],
                        'unit_price' => $product->price,
                    ]);
                }

                Notification::query()->create([
                    'user_id' => $user->id,
                    'title' => 'Order placed',
                    'message' => "Your order #{$order->id} was created successfully. Total: {$total}",
                ]);

                return $order->load('orderItems.product');
            });
        } catch (Throwable $exception) {
            $this->inventory->release($reservationId);

            throw $exception;
        }
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return list<array{product_id: int, quantity: int}>
     */
    private function normalizeItems(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $merged[$productId] = ($merged[$productId] ?? 0) + $quantity;
        }

        if ($merged === []) {
            throw InventoryException::rejected('Order must contain at least one item.');
        }

        $productIds = array_keys($merged);
        $existingCount = Product::query()->whereIn('id', $productIds)->count();

        if ($existingCount !== count($productIds)) {
            throw InventoryException::rejected('One or more products do not exist.');
        }

        $normalized = [];
        foreach ($merged as $productId => $quantity) {
            $normalized[] = [
                'product_id' => (int) $productId,
                'quantity' => (int) $quantity,
            ];
        }

        return $normalized;
    }
}
