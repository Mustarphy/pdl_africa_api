<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\OccasionResource;
use App\Models\Occasion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OccasionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $occasions = Occasion::published()
            ->withCount(['products as published_products_count' => fn ($q) => $q->published()])
            ->orderBy('display_order')->orderBy('name')
            ->get();

        return OccasionResource::collection($occasions);
    }

    public function show(string $slug): OccasionResource
    {
        $occasion = Occasion::published()
            ->where('slug', $slug)
            ->withCount(['products as published_products_count' => fn ($q) => $q->published()])
            ->firstOrFail();

        return new OccasionResource($occasion);
    }
}
