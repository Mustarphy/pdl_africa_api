<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductIndexRequest;
use App\Http\Resources\Catalog\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request, ProductSearch $search): AnonymousResourceCollection
    {
        $filters = $request->filters();
        $products = $search->paginate($filters, $request->perPage());

        $resource = ProductResource::collection($products);
        if ($request->boolean('facets')) {
            $resource->additional(['facets' => $search->facets($filters), 'price_range' => $search->priceRange($filters)]);
        }

        return $resource;
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::published()
            ->where('slug', $slug)
            ->with([
                'category' => fn ($q) => $q->published(),
                'collections' => fn ($q) => $q->published()->orderBy('display_order'),
                'variants' => fn ($q) => $q->where('is_active', true),
                'images',
            ])
            ->firstOrFail();

        return (new ProductResource($product))->detailed()->response();
    }
}
