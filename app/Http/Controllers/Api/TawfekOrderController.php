<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateTawfekOrderStatusRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\TawfekApiFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TawfekOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('orderItems.product')
            ->where('user_id', $this->authenticatedUserId($request))
            ->latest()
            ->get()
            ->map(fn (Order $order) => TawfekApiFormatter::orderSummary($order))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'orders' => $orders,
        ]);
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $order = $this->findOrderForAuthenticatedUser($request, $orderId);

        if ($order === null) {
            return $this->notOwnerResponse();
        }

        $order->load('orderItems.product');

        return response()->json([
            'success' => true,
            'order' => TawfekApiFormatter::orderSummary($order),
        ]);
    }

    public function updateStatus(UpdateTawfekOrderStatusRequest $request, int $orderId): JsonResponse
    {
        $order = Order::query()->whereKey($orderId)->firstOrFail();

        $order->update([
            'status' => $request->input('status'),
        ]);

        $status = $order->fresh()->status->value;

        Notification::query()->create([
            'user_id' => $order->user_id,
            'title' => 'Order status updated',
            'message' => "Order #{$order->id} status changed to {$status}.",
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
        ]);
    }

    private function authenticatedUserId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return (int) $user->id;
    }

    private function findOrderForAuthenticatedUser(Request $request, int $orderId): ?Order
    {
        return Order::query()
            ->whereKey($orderId)
            ->where('user_id', $this->authenticatedUserId($request))
            ->first();
    }

    private function notOwnerResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This action is unauthorized.',
        ], 403);
    }
}
