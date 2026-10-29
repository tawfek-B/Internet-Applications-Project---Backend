<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Http\Requests\Api\V1\UpdateOrderStatusRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Notification;
use App\Models\Order;
use App\Services\Orders\CreateOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly CreateOrderService $createOrderService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $query = Order::query()->with('orderItems.product')->latest();

        if (! $request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }

        $orders = $query->paginate(
            perPage: min(max((int) $request->input('per_page', 15), 1), 100),
        );

        return ApiResponse::success([
            'orders' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load('orderItems.product');

        return ApiResponse::success(['order' => OrderResource::make($order)]);
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

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorize('updateStatus', $order);

        $order->update([
            'status' => $request->input('status'),
        ]);

        $status = $order->fresh()->status->value;

        Notification::query()->create([
            'user_id' => $order->user_id,
            'title' => 'Order status updated',
            'message' => "Order #{$order->id} status changed to {$status}.",
        ]);

        return ApiResponse::success(
            ['order' => OrderResource::make($order->fresh('orderItems.product'))],
            'Order status updated',
        );
    }
}
