<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit trail of verified Paystack webhook deliveries (no payloads kept). */
class PaymentEvent extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['provider_transaction_id' => 'integer'];
    }
}
