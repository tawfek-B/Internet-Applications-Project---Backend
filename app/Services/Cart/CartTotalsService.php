<?php

namespace App\Services\Cart;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartTotalsService
{
    /**
     * @return array{totalItems: int, totalPrice: float}
     */
    public function totalsFor(User $user): array
    {
        $totalItems = (int) $user->cart()->sum('quantity');

        $totalPrice = (float) $user->cart()
            ->join('products', 'cart_items.product_id', '=', 'products.id')
            ->sum(DB::raw('cart_items.quantity * products.price'));

        return [
            'totalItems' => $totalItems,
            'totalPrice' => $totalPrice,
        ];
    }
}
