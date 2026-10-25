<?php

namespace App\Services;

use App\Services\Payments\PaystackRejected;
use App\Services\Payments\PaystackUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for Paystack's Transactions API (hosted checkout). The secret
 * key stays on the server: it is never returned, logged or put in errors.
 */
class PaystackService
{
    private ?string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->baseUrl = rtrim(config('services.paystack.url') ?: 'https://api.paystack.co', '/');
    }

    public function isConfigured(): bool
    {
        return (bool) $this->secretKey;
    }

    public function secretKey(): ?string
    {
        return $this->secretKey;
    }

    /**
     * Starts a transaction and returns Paystack's data (authorization_url,
     * access_code, reference).
     *
     * @param  array{email: string, amount: int, currency: string, reference: string, callback_url: string, metadata: array<string, mixed>}  $payload
     * @return array{authorization_url: string, access_code: string, reference: string}
     *
     * @throws PaystackUnavailable
     */
    public function initialize(array $payload): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post("{$this->baseUrl}/transaction/initialize", $payload));

        if ($response->clientError()) {
            throw new PaystackRejected('Paystack rejected the payment details ('.($response->json('message') ?: 'HTTP '.$response->status()).').');
        }
        if (! $response->successful() || ! $response->json('data.authorization_url')) {
            throw new PaystackUnavailable('Paystack could not start the payment ('.($response->json('message') ?: 'HTTP '.$response->status()).').');
        }

        return $response->json('data');
    }

    /**
     * Paystack's authoritative record of a transaction, or null when the
     * reference is unknown to Paystack (never started).
     *
     * @return array<string, mixed>|null
     *
     * @throws PaystackUnavailable
     */
    public function verify(string $reference): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get("{$this->baseUrl}/transaction/verify/".rawurlencode($reference)));

        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new PaystackUnavailable('Paystack could not verify the payment (HTTP '.$response->status().').');
        }

        return $response->json('data');
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw new PaystackUnavailable('Paystack is not configured (PAYSTACK_SECRET_KEY).');
        }

        try {
            return $call(Http::withToken($this->secretKey)->acceptJson()->timeout(20)->connectTimeout(8));
        } catch (ConnectionException $e) {
            throw new PaystackUnavailable('Could not reach Paystack.', previous: $e);
        }
    }
}
