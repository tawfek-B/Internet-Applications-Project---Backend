<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\TawfekOrderController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Orders\OrderController;
use App\Http\Controllers\Api\V1\Products\ProductController;
use App\Http\Controllers\Api\V1\Users\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);

    Route::middleware('auth:api')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::post('addToCart/{product}', [CartController::class, 'add']);
        Route::get('cart', [CartController::class, 'index']);
        Route::post('checkout', [CheckoutController::class, 'store']);
        Route::post('checkout2', [CheckoutController::class, 'storeSimple']);

        Route::get('orders', [TawfekOrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{orderId}', [TawfekOrderController::class, 'show'])
            ->whereNumber('orderId');
        Route::put('orders/{orderId}/status', [TawfekOrderController::class, 'updateStatus'])
            ->whereNumber('orderId')
            ->middleware('admin');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

        Route::post('products', [ProductController::class, 'store'])->middleware('admin');
    });
});
