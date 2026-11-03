<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateTawfekOrderStatusRequest;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\CreateOrderService;
use App\Support\ApiResponse;
use App\Support\ContractResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly CreateOrderService $createOrderService) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('orderItems.product')
            ->where('user_id', $this->authenticatedUserId($request))
            ->latest()
            ->get()
            ->map(fn (Order $order) => ContractResponse::orderSummary($order))
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
            'order' => ContractResponse::orderSummary($order),
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $order = $this->createOrderService->create(
            $request->user(),
            $request->input('items'),
        );

        return ApiResponse::success(
            ['order' => OrderResource::make($order)],
            'Order created',
            201,
        );
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
