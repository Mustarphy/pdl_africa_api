<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Only the React storefront/admin origins listed in FRONTEND_URLS may call
| the API from a browser. Wildcards are deliberately not used.
|
| Credentials are enabled for Sanctum SPA (cookie) authentication of the
| admin area. This is safe only because origins are an explicit allow-list;
| never combine `supports_credentials` with a wildcard origin.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('FRONTEND_URLS', '')),
    ))),

    'allowed_origins_patterns' => [],

    // Headers sent by the frontend's Axios clients (src/lib/api/).
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    // Let browsers cache preflight responses for 10 minutes.
    'max_age' => 600,

    'supports_credentials' => true,

];
