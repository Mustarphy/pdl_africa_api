<?php

namespace App\Http\Resources\Admin;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the admin orders table. @mixin Order */
class AdminOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items_count' => (int) ($this->lines_quantity ?? collect($this->items ?? [])->sum('quantity')),
            'total' => $this->amount,
            'currency' => $this->currency,
            'payment_status' => $this->payment_status->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'stock_issue' => $this->stock_issue,
        ];
    }
}
