<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackService
{
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->baseUrl   = rtrim(config('services.paystack.url', 'https://api.paystack.co'), '/');
    }

    /**
     * Initialize a Paystack transaction and return the authorization URL.
     *
     * @param  array{email: string, amount: int, currency: string, reference: string, callback_url: string, metadata: array<string, mixed>}  $payload
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initialize(array $payload): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->post("{$this->baseUrl}/transaction/initialize", $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Paystack. Please try again.', 0, $e);
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'Paystack initialization failed.';
            throw new RuntimeException($message);
        }

        return $response->json('data');
    }

    /**
     * Verify a completed transaction by its reference.
     *
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->get("{$this->baseUrl}/transaction/verify/{$reference}");
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Paystack. Please try again.', 0, $e);
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'Paystack verification failed.';
            throw new RuntimeException($message);
        }

        return $response->json('data');
    }
}
