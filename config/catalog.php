<?php

return [

    // All catalog prices are stored in this currency's minor unit (kobo).
    // Mixing currencies is not supported.
    'currency' => 'NGN',

    'images' => [
        // Public disk: files are served from APP_URL/storage (local) or a
        // CDN/object storage URL (production). See README.
        'disk' => env('CATALOG_IMAGE_DISK', 'public'),
        'max_upload_kb' => 10240,
        'min_dimension' => 600,
        'max_dimension' => 6000,
        // Generated WebP widths (never larger than the source).
        'widths' => [480, 960, 1600],
        'webp_quality' => 82,
        'max_per_product' => 12,
    ],

    'pagination' => [
        'default' => 24,
        'max' => 48,
    ],

];
