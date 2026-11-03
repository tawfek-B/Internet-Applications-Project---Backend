<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddToCartRequest;
use App\Models\Product;
use App\Services\Cart\CartTotalsService;
use App\Services\Inventory\InventoryClientInterface;
use App\Services\Inventory\InventoryException;
use App\Support\ContractResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartTotalsService $cartTotals,
        private readonly InventoryClientInterface $inventory,
    ) {}

    public function add(AddToCartRequest $request, Product $product): JsonResponse
    {
        $user = $request->user();
        $quantity = (int) $request->input('quantity');

        try {
            $availableStock = $this->inventory->getQuantity($product->id);
        } catch (InventoryException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        $cartItem = $user->cart()->where('product_id', $product->id)->first();
        $existingQuantity = $cartItem ? (int) $cartItem->quantity : 0;
        $requestedTotal = $existingQuantity + $quantity;

        if ($requestedTotal > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => "Cannot add to cart: requested quantity ({$requestedTotal}) exceeds available stock ({$availableStock}).",
            ], 422);
        }

        if ($cartItem) {
            $cartItem->update([
                'quantity' => $cartItem->quantity + $quantity,
            ]);
        } else {
            $user->cart()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        $cart = $user->cart()->get();
        $totals = $this->cartTotals->totalsFor($user);

        return response()->json([
            'success' => true,
            'message' => 'Product added to cart successfully',
            'cart' => ContractResponse::cartCollection($cart),
            'totalItems' => $totals['totalItems'],
            'totalPrice' => $totals['totalPrice'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $cart = $user->cart()->get();
        $totals = $this->cartTotals->totalsFor($user);

        return response()->json([
            'success' => true,
            'cart' => ContractResponse::cartCollection($cart),
            'totalItems' => (string) $totals['totalItems'],
            'totalPrice' => (string) $totals['totalPrice'],
        ]);
    }
}
