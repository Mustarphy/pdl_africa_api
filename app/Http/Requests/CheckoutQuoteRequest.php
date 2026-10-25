<?php

namespace App\Http\Requests;

/** Cart (and optional destination) for a price and delivery quote. */
class CheckoutQuoteRequest extends CheckoutRequest
{
    public bool $quoteOnly = true;
}
