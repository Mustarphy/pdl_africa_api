<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Stock is taken when a payment is confirmed (not reserved at checkout, so
 * abandoned payments never hold stock and no expiry job is needed).
 * Decrements are conditional (`stock >= qty`) so two concurrent payments can
 * never push stock below zero; a paid order that finds its variant sold out
 * is flagged for the team instead. Runs once per order (stock_deducted_at).
 */
class StockKeeper
{
    /** Call inside the transaction that marks the order paid. */
    public function deduct(Order $order): void
    {
        if ($order->stock_deducted_at !== null) {
            return;
        }

        $short = false;
        foreach ($order->lines()->whereNotNull('product_variant_id')->get() as $line) {
            $variant = ProductVariant::whereKey($line->product_variant_id)->lockForUpdate()->first();
            if (! $variant || $variant->stock_quantity === null || $variant->availability->value === 'made_to_order') {
                continue;
            }
            $taken = ProductVariant::whereKey($variant->id)
                ->where('stock_quantity', '>=', $line->quantity)
                ->update(['stock_quantity' => DB::raw('stock_quantity - '.(int) $line->quantity)]);
            $short = $short || $taken === 0;
        }

        $order->forceFill(['stock_deducted_at' => now(), 'stock_issue' => $short])->save();
    }
}
