<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Services\Orders\PaymentProcessor;
use App\Services\Payments\PaystackUnavailable;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Paystack webhooks (POST /api/v1/payments/paystack/webhook).
 *
 * Authenticity: HMAC-SHA512 of the raw body with the secret key, compared
 * in constant time with the x-paystack-signature header. Even then the
 * payload is only a trigger: the transaction is re-verified with Paystack's
 * API before anything changes. Duplicate and out-of-order deliveries are
 * harmless (PaymentProcessor::apply is idempotent; events are de-duplicated).
 */
class PaystackWebhookController extends Controller
{
    /** Events after which a transaction's status is worth re-checking. */
    private const HANDLED = ['charge.success'];

    public function __invoke(Request $request, PaystackService $paystack, PaymentProcessor $payments): Response
    {
        $secret = $paystack->secretKey();
        $signature = (string) $request->header('x-paystack-signature');
        if (! $secret || $signature === ''
            || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            return response('Invalid signature', 401);
        }

        $payload = json_decode($request->getContent(), true);
        $event = is_array($payload) ? (string) ($payload['event'] ?? '') : '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = isset($data['reference']) ? mb_substr((string) $data['reference'], 0, 80) : null;
        $transactionId = isset($data['id']) ? (int) $data['id'] : null;

        $key = ['provider' => 'paystack', 'event' => mb_substr($event, 0, 60), 'provider_transaction_id' => $transactionId];
        $record = PaymentEvent::where($key)->first() ?? (new PaymentEvent)->forceFill($key);
        if ($record->exists && $record->outcome !== 'error') {
            return response()->noContent(); // Already handled: acknowledge again.
        }
        $record->forceFill(['reference' => $reference]);

        if (! in_array($event, self::HANDLED, true) || ! $reference) {
            $record->forceFill(['outcome' => 'ignored'])->save();

            return response()->noContent();
        }

        $attempt = PaymentAttempt::where('reference', $reference)->first();
        if (! $attempt) {
            // e.g. a payment made before this order system (reference = order).
            $known = Order::where('reference', $reference)->exists();
            $record->forceFill(['outcome' => $known ? 'legacy_order' : 'unknown_reference'])->save();

            return response()->noContent();
        }

        try {
            $payments->verify($attempt);
        } catch (PaystackUnavailable $e) {
            report($e);
            // Not acknowledged: Paystack will deliver the event again.
            $record->forceFill(['outcome' => 'error'])->save();

            return response('Verification unavailable', 503);
        }

        $record->forceFill(['outcome' => 'applied'])->save();

        return response()->noContent();
    }
}
