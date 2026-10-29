<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryException;
use App\Services\Orders\CreateOrderService;
use App\Services\Orders\SimpleCheckoutService;
use App\Support\TawfekApiFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CreateOrderService $createOrderService,
        private readonly SimpleCheckoutService $simpleCheckoutService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $cartItems = $user->cart()->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty',
            ], 400);
        }

        $items = $cartItems->map(fn ($item) => [
            'product_id' => (int) $item->product_id,
            'quantity' => (int) $item->quantity,
        ])->values()->all();

        try {
            $order = $this->createOrderService->create($user, $items);
        } catch (InventoryException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        $user->cart()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully',
            'order' => TawfekApiFormatter::checkoutOrder($order),
        ], 201);
    }

    public function storeSimple(Request $request): JsonResponse
    {
        $user = $request->user();
        $cartItems = $user->cart()->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty',
            ], 400);
        }

        try {
            $order = $this->simpleCheckoutService->checkout($user, $cartItems);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $status = str_contains(strtolower($message), 'insufficient') ? 422 : 400;

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        $user->cart()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully',
            'order' => TawfekApiFormatter::checkoutOrder($order),
        ], 201);
    }
}
