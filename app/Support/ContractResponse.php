<?php

namespace App\Support;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

class ContractResponse
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     description: string,
     *     price: float,
     *     stock: int,
     *     image_url: string|null
     * }
     */
    public static function product(Product $product, ?int $stockFromInventory = null): array
    {
        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'description' => (string) $product->description,
            'price' => (float) $product->price,
            'stock' => $stockFromInventory ?? (int) $product->stock,
            'image_url' => $product->image_url,
        ];
    }

    /**
     * @param  iterable<int, Product>  $products
     * @param  array<int, int>  $stockByProductId
     * @return list<array<string, mixed>>
     */
    public static function productList(iterable $products, array $stockByProductId = []): array
    {
        $items = [];

        foreach ($products as $product) {
            $items[] = self::product(
                $product,
                $stockByProductId[(int) $product->id] ?? null,
            );
        }

        return $items;
    }

    /**
     * @return array{name: string, email: string, is_admin: bool}
     */
    public static function loginUser(User $user): array
    {
        return [
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'is_admin' => (bool) $user->is_admin,
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, is_admin: bool}
     */
    public static function profileUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'is_admin' => (bool) $user->is_admin,
        ];
    }

    /**
     * @return array{id: int, user_id: int, product_id: int, quantity: int}
     */
    public static function cartItem(CartItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'user_id' => (int) $item->user_id,
            'product_id' => (int) $item->product_id,
            'quantity' => (int) $item->quantity,
        ];
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @return list<array{id: int, user_id: int, product_id: int, quantity: int}>
     */
    public static function cartCollection(Collection $items): array
    {
        return $items->map(fn (CartItem $item) => self::cartItem($item))->values()->all();
    }

    /**
     * @return array{id: int, order_id: int, product_id: int, quantity: int}
     */
    public static function checkoutOrderItem(OrderItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'order_id' => (int) $item->order_id,
            'product_id' => (int) $item->product_id,
            'quantity' => (int) $item->quantity,
        ];
    }

    /**
     * @return array{id: int, order_id: int, product_id: int, quantity: int, product: array<string, mixed>}
     */
    public static function orderLineItem(OrderItem $item): array
    {
        $line = [
            'id' => (int) $item->id,
            'order_id' => (int) $item->order_id,
            'product_id' => (int) $item->product_id,
            'quantity' => (int) $item->quantity,
        ];

        if ($item->relationLoaded('product') && $item->product !== null) {
            $line['product'] = self::product($item->product);
        }

        return $line;
    }

    /**
     * @return array{
     *     user_id: int,
     *     id: int,
     *     status: string,
     *     total_price: float,
     *     total_items: int,
     *     order_items: list<array<string, mixed>>
     * }
     */
    public static function orderSummary(Order $order): array
    {
        $order->loadMissing('orderItems.product');

        return [
            'user_id' => (int) $order->user_id,
            'id' => (int) $order->id,
            'status' => $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status,
            'total_price' => self::orderTotalPrice($order),
            'total_items' => self::orderTotalItems($order),
            'order_items' => $order->orderItems
                ->map(fn (OrderItem $item) => self::orderLineItem($item))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{user_id: int, id: int, order_items: list<array<string, mixed>>}
     */
    public static function checkoutOrder(Order $order): array
    {
        $order->loadMissing('orderItems');

        return [
            'user_id' => (int) $order->user_id,
            'id' => (int) $order->id,
            'order_items' => $order->orderItems
                ->map(fn (OrderItem $item) => self::checkoutOrderItem($item))
                ->values()
                ->all(),
        ];
    }

    public static function orderTotalItems(Order $order): int
    {
        if ($order->relationLoaded('orderItems')) {
            return (int) $order->orderItems->sum('quantity');
        }

        return (int) $order->orderItems()->sum('quantity');
    }

    public static function orderTotalPrice(Order $order): float
    {
        if ($order->relationLoaded('orderItems')) {
            return (float) $order->orderItems->sum(
                fn (OrderItem $item) => (float) $item->unit_price * (int) $item->quantity,
            );
        }

        return (float) $order->orderItems()
            ->selectRaw('SUM(quantity * unit_price) as aggregate')
            ->value('aggregate') ?? 0;
    }
}
