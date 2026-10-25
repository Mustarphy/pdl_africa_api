<?php

/*
| Delivery zones and fees. PDL Africa supplies the values through the
| environment, per zone:
|   - a number: delivery fee in whole naira (NGN), charged online;
|   - `collect`: the customer pays the rider/courier on delivery, so nothing
|     is charged online for delivery (the order says so everywhere);
|   - blank: zone switched off. Destinations that match no enabled zone
|     cannot be checked out online (customers are pointed to contact us).
|
| Matching: the first enabled zone whose `countries` contains the country
| and (when `states` is set) the state, compared case-insensitively.
*/

$fee = fn (string $key) => is_numeric(env($key)) ? (int) round(((float) env($key)) * 100) : null;
$collect = fn (string $key) => strtolower(trim((string) env($key))) === 'collect';

return [
    'zones' => [
        'lagos' => [
            'label' => 'Lagos delivery',
            'countries' => ['NG'],
            'states' => ['Lagos'],
            'fee' => $fee('SHIPPING_FEE_LAGOS'),
            'collect_on_delivery' => $collect('SHIPPING_FEE_LAGOS'),
            'estimate' => env('SHIPPING_ESTIMATE_LAGOS'),
        ],
        'nigeria' => [
            'label' => 'Nationwide delivery (Nigeria)',
            'countries' => ['NG'],
            'states' => null,
            'fee' => $fee('SHIPPING_FEE_NIGERIA'),
            'collect_on_delivery' => $collect('SHIPPING_FEE_NIGERIA'),
            'estimate' => env('SHIPPING_ESTIMATE_NIGERIA'),
        ],
        'international' => [
            'label' => 'International delivery',
            'countries' => ['*'],
            'states' => null,
            'fee' => $fee('SHIPPING_FEE_INTERNATIONAL'),
            'collect_on_delivery' => $collect('SHIPPING_FEE_INTERNATIONAL'),
            'estimate' => env('SHIPPING_ESTIMATE_INTERNATIONAL'),
        ],
    ],
];
