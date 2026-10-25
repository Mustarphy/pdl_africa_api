<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Services\Account\AccountLinks;
use App\Services\Payments\PaystackUnavailable;
use App\Services\PaystackService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paystack payments for orders.
 *
 * An order is marked paid only after the Laravel API asks Paystack about a
 * transaction (verify endpoint) and the answer matches the attempt exactly:
 * status `success`, same reference, amount and currency. The browser's
 * return to the callback page and webhook payloads are only triggers for
 * that check. Applying a result is idempotent and runs under row locks, so
 * repeated callbacks or webhooks never double-confirm, double-deduct stock
 * or resend the confirmation email.
 */
class PaymentProcessor
{
    /** A started transaction is reused for retries within this window. */
    private const REUSE_MINUTES = 30;

    /** Minimum gap between automatic Paystack checks for one order. */
    private const CHECK_INTERVAL_SECONDS = 10;

    public function __construct(
        private readonly PaystackService $paystack,
        private readonly AccountLinks $links,
        private readonly StockKeeper $stock,
        private readonly OrderMailer $mailer,
    ) {}

    /**
     * The Paystack checkout URL for an unpaid order: an open attempt is
     * reused (two open transactions could both be paid), otherwise a new
     * attempt with a fresh reference is started.
     *
     * @throws PaystackUnavailable
     */
    public function checkoutUrl(Order $order): string
    {
        $open = PaymentAttempt::where('order_id', $order->id)
            ->where('status', PaymentAttemptStatus::Pending)
            ->whereNotNull('authorization_url')
            ->where('created_at', '>=', now()->subMinutes(self::REUSE_MINUTES))
            ->latest('id')
            ->first();
        if ($open) {
            return $open->authorization_url;
        }

        $attempt = new PaymentAttempt;
        $attempt->forceFill([
            'order_id' => $order->id,
            // Unique per attempt: Paystack rejects a reused reference.
            'reference' => $order->reference.'-'.Str::upper(Str::random(6)),
            'amount' => $order->amount,
            'currency' => $order->currency,
        ])->save();

        $returnUrl = $this->links->frontend('/checkout/complete?'.http_build_query(['order' => $order->reference]));
        try {
            $data = $this->paystack->initialize([
                'email' => $order->customer_email,
                'amount' => $order->amount,
                'currency' => $order->currency,
                'reference' => $attempt->reference,
                'callback_url' => $returnUrl,
                'metadata' => [
                    'order_reference' => $order->reference,
                    // Where Paystack sends a customer who cancels.
                    'cancel_action' => $returnUrl,
                    'custom_fields' => [
                        ['display_name' => 'Order', 'variable_name' => 'order_reference', 'value' => $order->reference],
                    ],
                ],
            ]);
        } catch (PaystackUnavailable $e) {
            $attempt->forceFill(['status' => PaymentAttemptStatus::Failed, 'gateway_response' => 'Could not start the payment'])->save();
            throw $e;
        }

        $attempt->forceFill(['authorization_url' => $data['authorization_url']])->save();

        return $data['authorization_url'];
    }

    /**
     * Asks Paystack about the order's unfinished attempts (throttled), e.g.
     * when the customer returns from Paystack or reopens the order page.
     */
    public function reconcile(Order $order, bool $force = false): Order
    {
        if ($order->isPaid() || $order->status === OrderStatus::Cancelled) {
            return $order;
        }
        if (! $force && $order->payment_checked_at?->gt(now()->subSeconds(self::CHECK_INTERVAL_SECONDS))) {
            return $order;
        }
        $order->forceFill(['payment_checked_at' => now()])->save();

        $attempts = PaymentAttempt::where('order_id', $order->id)
            ->whereIn('status', [PaymentAttemptStatus::Pending, PaymentAttemptStatus::Abandoned])
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')->limit(3)->get();
        foreach ($attempts as $attempt) {
            try {
                $this->verify($attempt);
            } catch (PaystackUnavailable $e) {
                report($e);
                break;
            }
            if ($order->refresh()->isPaid()) {
                break;
            }
        }

        return $order->refresh();
    }

    /** @throws PaystackUnavailable */
    public function verify(PaymentAttempt $attempt): void
    {
        $data = $this->paystack->verify($attempt->reference);
        if ($data !== null) {
            $this->apply($attempt, $data);
        }
    }

    /**
     * Applies Paystack's verified transaction data to the attempt and order.
     *
     * @param  array<string, mixed>  $data  `data` from GET /transaction/verify
     */
    public function apply(PaymentAttempt $attempt, array $data): void
    {
        $order = DB::transaction(function () use ($attempt, $data) {
            $attempt = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($attempt->order_id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === PaymentAttemptStatus::Success) {
                return null; // Already applied.
            }

            $providerStatus = (string) ($data['status'] ?? '');
            $matches = ($data['reference'] ?? null) === $attempt->reference
                && (int) ($data['amount'] ?? -1) === $attempt->amount
                && strtoupper((string) ($data['currency'] ?? '')) === $attempt->currency;

            $attempt->forceFill([
                'provider_status' => mb_substr($providerStatus, 0, 30),
                'gateway_response' => mb_substr((string) ($data['gateway_response'] ?? ''), 0, 255) ?: null,
                'channel' => isset($data['channel']) ? mb_substr((string) $data['channel'], 0, 30) : null,
                'provider_transaction_id' => isset($data['id']) ? (int) $data['id'] : null,
                'verified_at' => now(),
            ]);

            if ($providerStatus === 'success' && ! $matches) {
                // Never accept a payment for a different amount or currency.
                Log::warning('Paystack payment does not match its order', [
                    'reference' => $attempt->reference,
                    'expected_amount' => $attempt->amount,
                    'paid_amount' => (int) ($data['amount'] ?? 0),
                    'currency' => (string) ($data['currency'] ?? ''),
                ]);
                $attempt->forceFill(['status' => PaymentAttemptStatus::Failed, 'gateway_response' => 'Amount or currency did not match the order'])->save();

                return null;
            }

            $attemptStatus = match ($providerStatus) {
                'success' => PaymentAttemptStatus::Success,
                'failed', 'reversed' => PaymentAttemptStatus::Failed,
                // Paystack also says "abandoned" for a checkout the customer
                // has not finished yet, so only trust it once it is old.
                'abandoned' => $attempt->created_at->lt(now()->subMinutes(self::REUSE_MINUTES))
                    ? PaymentAttemptStatus::Abandoned : PaymentAttemptStatus::Pending,
                default => PaymentAttemptStatus::Pending, // ongoing, pending, processing, queued
            };
            $attempt->forceFill(['status' => $attemptStatus]);
            if ($attemptStatus === PaymentAttemptStatus::Success) {
                $attempt->paid_at = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
            }
            $attempt->save();

            if ($attemptStatus === PaymentAttemptStatus::Success) {
                // A second successful attempt on a paid order is recorded
                // (admin sees "duplicate payment") but changes nothing else.
                if ($order->isPaid()) {
                    return null;
                }

                return $this->markPaid($order, $attempt);
            }
            if ($attemptStatus === PaymentAttemptStatus::Failed && ! $order->isPaid()
                && (int) PaymentAttempt::where('order_id', $order->id)->max('id') === $attempt->id) {
                $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
            }

            return null;
        });

        if ($order) {
            $this->mailer->confirmation($order);
        }
    }

    /** @return Order|null the order when it should get a confirmation email */
    private function markPaid(Order $order, PaymentAttempt $attempt): ?Order
    {
        $wasCancelled = $order->status === OrderStatus::Cancelled;
        $order->forceFill([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => $attempt->paid_at,
            // A payment that lands after a cancellation stays cancelled for
            // the team to refund; otherwise the order is confirmed.
            'status' => $wasCancelled ? OrderStatus::Cancelled : OrderStatus::Confirmed,
        ])->save();

        if (! $wasCancelled) {
            $history = new OrderStatusHistory;
            $history->forceFill([
                'order_id' => $order->id,
                'from_status' => OrderStatus::PendingPayment,
                'to_status' => OrderStatus::Confirmed,
                'actor_type' => 'system',
                'note' => 'Payment '.$attempt->reference.' verified with Paystack.',
            ])->save();
            $this->stock->deduct($order);
        }

        return $wasCancelled ? null : $order;
    }
}
