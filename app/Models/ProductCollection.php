<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\ProductCollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A fashion range (e.g. "Agbada"). Named ProductCollection to avoid clashing
 * with Laravel's Collection class; the table is `collections`.
 */
#[Fillable([
    'name', 'slug', 'short_description', 'description', 'cover_image_alt',
    'banner_image_alt', 'display_order', 'seo_title', 'seo_description',
    'legacy_key',
])]
class ProductCollection extends Model
{
    /** @use HasFactory<ProductCollectionFactory> */
    use HasFactory;

    protected $table = 'collections';

    protected $attributes = [
        'status' => 'draft',
        'display_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'cover_image' => 'array',
            'banner_image' => 'array',
            'display_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_product', 'collection_id', 'product_id')
            ->withPivot('position');
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CatalogStatus::Published);
    }

    public function isPublished(): bool
    {
        return $this->status === CatalogStatus::Published;
    }
}
