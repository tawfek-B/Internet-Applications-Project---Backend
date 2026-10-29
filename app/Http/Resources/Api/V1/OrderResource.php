<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('orderItems', fn () => $this->orderItems);

        return [
            'id' => $this->id,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'reservation_id' => $this->reservation_id,
            'user_id' => $this->user_id,
            'items' => OrderItemResource::collection($items),
            'total_items' => $this->whenLoaded('orderItems', fn () => (int) $this->orderItems->sum('quantity')),
            'total_price' => $this->whenLoaded('orderItems', function () {
                return (float) $this->orderItems->sum(
                    fn ($item) => (float) $item->unit_price * (int) $item->quantity,
                );
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
