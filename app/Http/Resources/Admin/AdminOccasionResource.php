<?php

namespace App\Http\Resources\Admin;

use App\Models\Occasion;
use App\Models\Product;
use App\Services\Catalog\CatalogImageStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Occasion */
class AdminOccasionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $store = app(CatalogImageStore::class);
        $urls = $store->urls($this->image);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'display_order' => $this->display_order,
            'image' => $urls ? ['thumbnail' => $urls['srcset'][0]['url'], 'src' => $urls['src']] : null,
            'image_alt' => $this->image_alt,
            'product_count' => $this->whenCounted('products'),
            'products' => $this->whenLoaded('products', fn () => $this->products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'slug' => $product->slug,
                'status' => $product->status->value,
            ])->values()),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
