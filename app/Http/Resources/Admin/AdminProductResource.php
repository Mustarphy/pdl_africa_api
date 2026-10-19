<?php

namespace App\Http\Resources\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\CatalogImageStore;
use App\Services\Catalog\PublicationRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class AdminProductResource extends JsonResource
{
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $store = app(CatalogImageStore::class);
        $thumb = $this->relationLoaded('primaryImage') && $this->primaryImage
            ? $store->urls($this->primaryImage->descriptor())['srcset'][0]['url'] ?? null
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'status' => $this->status->value,
            'is_featured' => $this->is_featured,
            'display_order' => $this->display_order,
            'price' => $this->price,
            'min_price' => $this->relationLoaded('variants') ? $this->minPrice() : $this->price,
            'max_price' => $this->relationLoaded('variants') ? $this->maxPrice() : $this->price,
            'currency' => $this->currency,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null),
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status->value,
            ])->values()),
            'thumbnail' => $thumb,
            'image_count' => $this->whenCounted('images'),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            $this->mergeWhen($this->detailed, fn () => [
                'short_description' => $this->short_description,
                'description' => $this->description,
                'category_id' => $this->category_id,
                'garment_type' => $this->garment_type,
                'material' => $this->material,
                'care_instructions' => $this->care_instructions,
                'seo_title' => $this->seo_title,
                'seo_description' => $this->seo_description,
                'legacy_key' => $this->legacy_key,
                'collection_ids' => $this->collections->pluck('id')->values(),
                'variants' => $this->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'size' => $v->size,
                    'color' => $v->color,
                    'color_hex' => $v->color_hex,
                    'price' => $v->price,
                    'stock_quantity' => $v->stock_quantity,
                    'availability' => $v->availability->value,
                    'is_active' => $v->is_active,
                ])->values(),
                'images' => $this->images->map(function (ProductImage $image) use ($store) {
                    $urls = $store->urls($image->descriptor());

                    return [
                        'id' => $image->id,
                        'alt_text' => $image->alt_text,
                        'is_primary' => $image->is_primary,
                        'display_order' => $image->display_order,
                        'product_variant_id' => $image->product_variant_id,
                        'thumbnail' => $urls['srcset'][0]['url'] ?? null,
                        'src' => $urls['src'] ?? null,
                        'width' => $image->width,
                        'height' => $image->height,
                    ];
                })->values(),
                'publication_issues' => app(PublicationRules::class)->productIssues($this->resource),
            ]),
        ];
    }
}
