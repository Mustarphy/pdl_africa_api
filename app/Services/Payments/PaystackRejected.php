<?php

namespace App\Services\Payments;

/** Paystack refused the request itself (e.g. an email address it does not accept). */
class PaystackRejected extends PaystackUnavailable {}
