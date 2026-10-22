<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'reference',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'amount',
        'currency',
        'status',
        'items',
        'paystack_data',
    ];

    protected $casts = [
        'items'         => 'array',
        'paystack_data' => 'array',
        'amount'        => 'integer',
    ];
}
