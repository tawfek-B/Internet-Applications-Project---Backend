<?php

namespace App\Services\Cart;

use App\Models\User;

class CartTotalsService
{
    /**
     * @return array{totalItems: int, totalPrice: float}
     */
    public function totalsFor(User $user): array
    {
        $row = $user->cart()
            ->join('products', 'cart_items.product_id', '=', 'products.id')
            ->selectRaw('COALESCE(SUM(cart_items.quantity), 0) as total_items')
            ->selectRaw('COALESCE(SUM(cart_items.quantity * products.price), 0) as total_price')
            ->first();

        return [
            'totalItems' => (int) ($row->total_items ?? 0),
            'totalPrice' => (float) ($row->total_price ?? 0),
        ];
    }
}
