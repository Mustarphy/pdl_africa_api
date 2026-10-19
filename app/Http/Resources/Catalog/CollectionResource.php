<?php

namespace App\Http\Resources\Catalog;

use App\Models\ProductCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductCollection */
class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'cover_image' => ImagePresenter::descriptor($this->cover_image, $this->cover_image_alt),
            'banner_image' => ImagePresenter::descriptor($this->banner_image, $this->banner_image_alt),
            'product_count' => $this->whenHas('published_products_count'),
            'seo' => ['title' => $this->seo_title, 'description' => $this->seo_description],
            'legacy_key' => $this->legacy_key,
        ];
    }
}
