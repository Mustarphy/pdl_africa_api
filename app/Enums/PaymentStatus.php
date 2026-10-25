<?php

namespace App\Enums;

/** Payment state of an order as a whole (see PaymentAttemptStatus). */
enum PaymentStatus: string
{
    /** No verified payment yet (including attempts still in progress). */
    case Pending = 'pending';
    case Paid = 'paid';
    /** The latest attempt failed or was abandoned; the order can be retried. */
    case Failed = 'failed';
}
