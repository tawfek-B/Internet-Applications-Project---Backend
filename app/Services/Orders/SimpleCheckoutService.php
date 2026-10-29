<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SimpleCheckoutService
{
    /**
     * @param  Collection<int, CartItem>  $cartItems
     */
    public function checkout(User $user, Collection $cartItems): Order
    {
        if ($cartItems->isEmpty()) {
            throw new RuntimeException('Cart is empty');
        }

        return DB::transaction(function () use ($user, $cartItems) {
            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'reservation_id' => null,
            ]);

            $total = 0.0;

            foreach ($cartItems as $item) {
                /** @var Product $product */
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);

                if ($product->stock < $item->quantity) {
                    throw new RuntimeException(
                        "Insufficient stock for product {$product->id}: requested {$item->quantity}, available {$product->stock}",
                    );
                }

                $product->decrement('stock', $item->quantity);

                $lineTotal = (float) $product->price * (int) $item->quantity;
                $total += $lineTotal;

                $order->orderItems()->create([
                    'product_id' => $product->id,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => $product->price,
                ]);
            }

            Notification::query()->create([
                'user_id' => $user->id,
                'title' => 'Order placed',
                'message' => "Your order #{$order->id} was created successfully. Total: {$total}",
            ]);

            return $order->load('orderItems');
        });
    }
}
