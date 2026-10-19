<?php

namespace App\Http\Resources\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public product representation. Only published data; stock quantities are
 * reduced to an availability flag.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** Include long-form fields (product detail endpoint). */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $currency = $this->currency;
        $money = fn (?int $amount) => $amount === null ? null : ['amount' => $amount, 'currency' => $currency];
        $min = $this->minPrice();
        $max = $this->maxPrice();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'sku' => $this->sku,
            'short_description' => $this->short_description,
            'description' => $this->when($this->detailed, $this->description),
            'material' => $this->when($this->detailed, $this->material),
            'care_instructions' => $this->when($this->detailed, $this->care_instructions),
            'garment_type' => $this->garment_type,
            'price' => $money($this->price ?? $min),
            'price_range' => $min !== null && $max !== null && $min !== $max
                ? ['min' => $money($min), 'max' => $money($max)]
                : null,
            'is_featured' => $this->is_featured,
            'category' => $this->category ? ['slug' => $this->category->slug, 'name' => $this->category->name] : null,
            'collections' => $this->collections->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])->values(),
            'images' => $this->images->map(fn (ProductImage $i) => ImagePresenter::product($i))->filter()->values(),
            'variants' => $this->variants->where('is_active', true)->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'size' => $v->size,
                'color' => $v->color,
                'color_hex' => $v->color_hex,
                'price' => $money($v->price ?? $this->price),
                'availability' => $v->availability->value,
                'is_available' => $v->isPurchasable(),
                'image_id' => $this->when($this->detailed, fn () => $this->images->firstWhere('product_variant_id', $v->id)?->id),
            ])->values(),
            'seo' => $this->when($this->detailed, fn () => [
                'title' => $this->seo_title,
                'description' => $this->seo_description,
            ]),
            'legacy_key' => $this->legacy_key,
        ];
    }
}
