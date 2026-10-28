<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class OrderController extends Controller
{
    //

    public function changeStatus(Request $request, $orderId)
    {
        abort_if(!auth()->user()->is_admin, 403, 'Unauthorized action');

        $request->validate([
            'status' => 'required|in:pending,delivering,completed,cancelled',
        ]);

        $order = \App\Models\Order::findOrFail($orderId);
        $order->status = $request->input('status');
        $order->save();

        return response()->json(['success' => true, 'message' => 'Order status updated successfully']);
    }
}
