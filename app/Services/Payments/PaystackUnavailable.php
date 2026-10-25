<?php

namespace App\Services\Payments;

use RuntimeException;

/** Paystack could not be reached, rejected the call, or is not configured. */
class PaystackUnavailable extends RuntimeException {}
