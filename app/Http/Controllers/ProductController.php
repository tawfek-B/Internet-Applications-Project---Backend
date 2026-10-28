<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    //
    public function addToCart(Request $request, $productId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $product = Product::findOrFail($productId);

        $cartItem = auth()->user()->cart()->where('product_id', $productId)->first();

        if ($cartItem) {
            $cartItem->quantity += $request->input('quantity');
            $cartItem->save();
        } else {
            auth()->user()->cart()->create([
                'product_id' => $productId,
                'quantity' => $request->input('quantity'),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Product added to cart successfully', 'cart' => auth()->user()->cart()->get(), 'totalItems' => auth()->user()->cart()->sum('quantity'), 'totalPrice' => auth()->user()->cart()->join('products', 'cart_items.product_id', '=', 'products.id')->sum(\DB::raw('cart_items.quantity * products.price'))]);
    }

    public function checkout()
    {
        $user = auth()->user();
        $cartItems = $user->cart()->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Cart is empty'], 400);
        }

        $order = Order::create(['user_id' => $user->id]);

        foreach ($cartItems as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ]);
        }

        $user->cart()->delete();

        return response()->json(['success' => true, 'message' => 'Order placed successfully', 'order' => $order->load('orderItems')]);
    }
}