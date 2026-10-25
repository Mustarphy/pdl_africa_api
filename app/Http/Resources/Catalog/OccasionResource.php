<?php

namespace App\Http\Resources\Catalog;

use App\Models\Occasion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Occasion */
class OccasionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'image' => ImagePresenter::descriptor($this->image, $this->image_alt),
            'product_count' => $this->whenHas('published_products_count'),
        ];
    }
}
