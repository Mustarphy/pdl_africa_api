<?php

namespace App\Enums;

/**
 * Fulfilment lifecycle. Payment has its own status (PaymentStatus); an
 * order only leaves PendingPayment once its payment is verified.
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case ReadyForDispatch = 'ready_for_dispatch';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** Statuses an administrator may move to from this one. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Confirmation only comes from a verified payment.
            self::PendingPayment => [self::Cancelled],
            self::Confirmed => [self::Processing, self::Cancelled],
            self::Processing => [self::ReadyForDispatch, self::Dispatched, self::Cancelled],
            self::ReadyForDispatch => [self::Dispatched, self::Cancelled],
            self::Dispatched => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Being prepared',
            self::ReadyForDispatch => 'Ready for dispatch',
            self::Dispatched => 'Dispatched',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }
}
