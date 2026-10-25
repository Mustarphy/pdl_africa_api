<?php

namespace App\Services\Orders;

/**
 * Delivery fee for a destination, from config/shipping.php (values supplied
 * by PDL Africa through the environment). Never guesses a price: a
 * destination without an enabled zone cannot be checked out online.
 */
class ShippingCalculator
{
    /**
     * `fee` is charged online; with `pay_on_delivery` it is 0 and the
     * customer pays the rider/courier when the order arrives.
     *
     * @return array{zone: string, label: string, fee: int, pay_on_delivery: bool, estimate: ?string}|null
     */
    public function quote(?string $countryCode, ?string $state): ?array
    {
        if (! $countryCode) {
            return null;
        }
        $country = strtoupper($countryCode);
        $state = mb_strtolower(trim((string) $state));

        foreach (config('shipping.zones') as $key => $zone) {
            if (! self::enabled($zone)) {
                continue;
            }
            $countries = $zone['countries'];
            if (! in_array('*', $countries, true) && ! in_array($country, $countries, true)) {
                continue;
            }
            if ($zone['states'] !== null
                && ! in_array($state, array_map('mb_strtolower', $zone['states']), true)) {
                continue;
            }

            return [
                'zone' => $key,
                'label' => $zone['label'],
                'fee' => ($zone['collect_on_delivery'] ?? false) ? 0 : (int) $zone['fee'],
                'pay_on_delivery' => (bool) ($zone['collect_on_delivery'] ?? false),
                'estimate' => $zone['estimate'] ?: null,
            ];
        }

        return null;
    }

    /** True when at least one zone has a fee or pays on delivery. */
    public function isConfigured(): bool
    {
        return collect(config('shipping.zones'))->contains(fn ($zone) => self::enabled($zone));
    }

    private static function enabled(array $zone): bool
    {
        return ($zone['collect_on_delivery'] ?? false) || $zone['fee'] !== null;
    }
}
