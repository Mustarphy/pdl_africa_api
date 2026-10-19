<?php

namespace App\Http\Resources\Admin;

use App\Models\ProductCollection;
use App\Services\Catalog\CatalogImageStore;
use App\Services\Catalog\PublicationRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductCollection */
class AdminCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $store = app(CatalogImageStore::class);
        $image = fn (?array $descriptor) => ($urls = $store->urls($descriptor))
            ? ['thumbnail' => $urls['srcset'][0]['url'], 'src' => $urls['src']]
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'status' => $this->status->value,
            'display_order' => $this->display_order,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'legacy_key' => $this->legacy_key,
            'cover_image' => $image($this->cover_image),
            'cover_image_alt' => $this->cover_image_alt,
            'banner_image' => $image($this->banner_image),
            'banner_image_alt' => $this->banner_image_alt,
            'product_count' => $this->whenCounted('products'),
            'publication_issues' => app(PublicationRules::class)->collectionIssues($this->resource),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
