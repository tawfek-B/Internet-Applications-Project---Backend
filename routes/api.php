<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

use App\Events\ReverbDiagnostic;

use App\Models\Product;
use Illuminate\Support\Facades\Broadcast;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('products', [ProductController::class, 'index']);
Route::get('products/{product}', [ProductController::class, 'show']);

Broadcast::routes(['middleware' => ['auth:api']]);

Route::middleware('auth:api')->group(function (): void {
    Route::get('user', [AuthController::class, 'me']);

    Route::post('addToCart/{product}', [CartController::class, 'add']);
    Route::get('cart', [CartController::class, 'index']);
    Route::post('checkout', [CheckoutController::class, 'store']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{orderId}', [OrderController::class, 'show'])->whereNumber('orderId');
    Route::put('orders/{orderId}/status', [OrderController::class, 'updateStatus'])
        ->whereNumber('orderId')
        ->middleware('admin');
});

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);

    Route::get('/instance', function () {
        return response(gethostname() . PHP_EOL);
    });

    Route::middleware('auth:api')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('user', [AuthController::class, 'me']);

        Route::post('addToCart/{product}', [CartController::class, 'add']);
        Route::get('cart', [CartController::class, 'index']);
        Route::post('checkout', [CheckoutController::class, 'store']);
        Route::post('checkout2', [CheckoutController::class, 'storeSimple']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{orderId}', [OrderController::class, 'show'])
            ->whereNumber('orderId');
        Route::put('orders/{orderId}/status', [OrderController::class, 'updateStatus'])
            ->whereNumber('orderId')
            ->middleware('admin');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

        Route::post('products', [ProductController::class, 'store'])->middleware('admin');
        Route::put('products/{product}', function ($productId) {
            $product = Product::findOrFail($productId);
            $product->update(request()->only(['name', 'description', 'price', 'stock', 'category', 'image_url']));
        })->middleware('admin');
        Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('admin');
    });
});


Route::get('/reverb-test', function () {
    Log::info('REVERB TEST: About to dispatch');

    try {
        ReverbDiagnostic::dispatch();

        Log::info('REVERB TEST: Dispatch completed');

        return response()->json([
            'success' => true,
            'message' => 'Diagnostic event dispatched',
        ]);
    } catch (\Throwable $e) {
        Log::error('REVERB TEST FAILED', [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ]);

        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

Route::get('/instance', fn () => response()->json([
    'instance' => env('APP_INSTANCE', gethostname()),
]));