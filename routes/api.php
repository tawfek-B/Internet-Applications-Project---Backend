<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;

Route::get('/user', function () {
    return User::first();
});

Route::post('/login', [AuthController::class, 'login']);

Route::get('/test',function() {
    return response()->json(['orders' => \App\Models\User::find(2)->orders()->with('orderItems')->get(), 'ordersAll' => \App\Models\Order::with('orderItems')->get()]);
    // return response()->json(['products' => \App\Models\Product::all(), 'orders' => \App\Models\Order::all(), 'order_items' => \App\Models\OrderItem::all(), 'users' => \App\Models\User::all()]);
});

Route::get('/products', function() {
    return response()->json(['products' => \App\Models\Product::all()]);
});
Route::get('/products/{id}', function($id) {
    $product = \App\Models\Product::find($id);
    if (!$product) {
        return response()->json(['success' => false, 'message' => 'Product not found'], 404);
    }
    return response()->json(['success' => true, 'product' => $product]);
});

Route::middleware('auth:api')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/orders', function() {
        $orders = auth()->user()->orders()->with('orderItems')->get();
        $orders->transform(function ($order) {
            $order->total_price = $order->orderItems->sum(function ($item) {
                return $item->quantity * $item->product->price;
            });
            $order->total_items = $order->orderItems->sum('quantity');
            return $order;
        });
        return response()->json(['success' => true, 'orders' => $orders]);
    });

    Route::get('/orders/{id}', function($id) {
        $order = auth()->user()->orders()->with('orderItems.product')->find($id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }
        if($order->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access'], 403);
        }
        $order->total_price = $order->orderItems->sum(function ($item) {
            return $item->quantity * $item->product->price;
        });
        $order->total_items = $order->orderItems->sum('quantity');
        return response()->json(['success' => true, 'order' => $order]);
    });

    Route::get('/cart', function() {
        $cart = auth()->user()->cart()->get() ?? [];
        return response()->json(['success' => true, 'cart' => $cart,'totalItems' => auth()->user()->cart()->sum('quantity'), 'totalPrice' => auth()->user()->cart()->join('products', 'cart_items.product_id', '=', 'products.id')->sum(\DB::raw('cart_items.quantity * products.price'))]);
    });
    Route::post('/addToCart/{productId}', [ProductController::class, 'addToCart']);
    Route::post('/checkout', [ProductController::class, 'checkout']);

    Route::put('/orders/{orderId}/status', [\App\Http\Controllers\OrderController::class, 'changeStatus']);

    Route::post('/logout', [AuthController::class, 'logout']);
});