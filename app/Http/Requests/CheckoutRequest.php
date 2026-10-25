<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout input. Only ids and quantities are accepted for the cart; any
 * prices, totals or statuses a client sends are ignored (not validated, so
 * never read).
 */
class CheckoutRequest extends FormRequest
{
    /** Quote requests only need the cart (and optionally a destination). */
    public bool $quoteOnly = false;

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : null,
            'country_code' => is_string($this->input('country_code')) ? strtoupper(trim($this->input('country_code'))) : null,
        ], fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        $cart = [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.variant_id' => ['present', 'nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ];
        if ($this->quoteOnly) {
            return $cart + [
                'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
                'state' => ['nullable', 'string', 'max:100'],
            ];
        }

        return $cart + [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9\s\-().]{6,24}$/'],
            'address_line1' => ['required', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'delivery_notes' => ['nullable', 'string', 'max:500'],
            // The total the customer saw; a different server total means
            // prices or delivery changed and must be confirmed again.
            'expected_total' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a phone number with digits only, e.g. +234 801 234 5678.',
            'country_code.regex' => 'Choose a country.',
        ];
    }

    /** @return list<array{product_id: int, variant_id: ?int, quantity: int}> */
    public function cartItems(): array
    {
        return array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : null,
            'quantity' => (int) $item['quantity'],
        ], array_values($this->validated('items')));
    }
}
