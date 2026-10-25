<?php

namespace App\Enums;

/** One Paystack transaction, as last verified with Paystack. */
enum PaymentAttemptStatus: string
{
    /** Created on Paystack; the customer has not finished paying yet. */
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    /** Paystack reports the customer left without paying. */
    case Abandoned = 'abandoned';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
