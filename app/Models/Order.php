<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer order. Amounts are integers in the currency's minor unit
 * (kobo). Item details are snapshots (order_items), so later catalogue
 * edits never change a placed order.
 *
 * Nothing here is mass assignable: orders are written only by
 * App\Services\Orders\* with server-calculated values.
 */
class Order extends Model
{
    protected $guarded = ['*'];

    protected $attributes = [
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'currency' => 'NGN',
        'subtotal' => 0,
        'shipping_fee' => 0,
        'discount' => 0,
        'stock_issue' => false,
        'shipping_on_delivery' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'items' => 'array',
            'paystack_data' => 'array',
            'amount' => 'integer',
            'subtotal' => 'integer',
            'shipping_fee' => 'integer',
            'discount' => 'integer',
            'stock_issue' => 'boolean',
            'shipping_on_delivery' => 'boolean',
            'paid_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'stock_deducted_at' => 'datetime',
            'confirmation_email_sent_at' => 'datetime',
            'payment_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('id');
    }

    /** @return HasOne<PaymentAttempt, $this> */
    public function latestPaymentAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class)->latestOfMany();
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Paid;
    }

    /** Unpaid, not cancelled: the customer may (re)try paying. */
    public function canBePaid(): bool
    {
        return ! $this->isPaid() && $this->status === OrderStatus::PendingPayment;
    }
}
