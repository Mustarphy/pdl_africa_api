<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CollectionResource;
use App\Models\ProductCollection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CollectionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $collections = ProductCollection::published()
            ->withCount(['products as published_products_count' => fn ($q) => $q->published()])
            ->orderBy('display_order')->orderBy('name')
            ->get();

        return CollectionResource::collection($collections);
    }

    public function show(string $slug): CollectionResource
    {
        $collection = ProductCollection::published()
            ->where('slug', $slug)
            ->withCount(['products as published_products_count' => fn ($q) => $q->published()])
            ->firstOrFail();

        return new CollectionResource($collection);
    }
}
