<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductResource;
use App\Services\Catalog\ProductSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Instant suggestions for the storefront search panel. */
class SearchSuggestionController extends Controller
{
    public function __invoke(Request $request, ProductSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        $q = $validated['q'];
        $limit = (int) ($validated['limit'] ?? 6);

        $products = $search->suggestProducts($q, $limit);

        return response()->json([
            'data' => [
                'query' => implode(' ', ProductSearch::tokens($q)),
                'collections' => $search->suggestCollections($q, 3)->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->values(),
                'products' => ProductResource::collection($products),
                'total' => $search->filtered(['q' => $q])->count(),
            ],
        ]);
    }
}
