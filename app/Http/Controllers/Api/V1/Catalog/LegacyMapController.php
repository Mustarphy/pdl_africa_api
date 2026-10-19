<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCollection;
use Illuminate\Http\JsonResponse;

/**
 * Which legacy (frontend-bundled) catalog entries have been replaced by a
 * PUBLISHED database record. The storefront hides a legacy entry as soon as
 * it appears here and redirects its old URL to the new slug. Drafts never
 * appear, so a legacy item stays visible until its replacement is live.
 */
class LegacyMapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $map = fn ($model) => $model::published()
            ->where(fn ($q) => $q->whereNotNull('legacy_key'))
            ->orderBy('id')
            ->get(['legacy_key', 'slug'])
            ->map(fn ($r) => ['legacy_key' => $r->legacy_key, 'slug' => $r->slug])
            ->values();

        return response()->json(['data' => [
            'products' => $map(Product::class),
            'collections' => $map(ProductCollection::class),
        ]]);
    }
}
