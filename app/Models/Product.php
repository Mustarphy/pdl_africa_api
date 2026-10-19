<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Status, published_at and search_text are managed by the catalog services,
 * never mass-assigned from requests.
 */
#[Fillable([
    'name', 'slug', 'sku', 'short_description', 'description', 'category_id',
    'garment_type', 'material', 'care_instructions', 'price', 'is_featured',
    'display_order', 'seo_title', 'seo_description', 'legacy_key',
])]
#[Hidden(['search_text'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'NGN',
        'is_featured' => false,
        'display_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'price' => 'integer',
            'is_featured' => 'boolean',
            'display_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<ProductCollection, $this> */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(ProductCollection::class, 'collection_product', 'product_id', 'collection_id')
            ->withPivot('position');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('display_order')->orderBy('id');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('display_order')->orderBy('id');
    }

    /** @return HasOne<ProductImage, $this> */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
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

    /** Lowest active price across the product and its variants (minor units). */
    public function minPrice(): ?int
    {
        $prices = $this->variants->where('is_active', true)->pluck('price')->filter(fn ($p) => $p !== null);
        if ($this->price !== null) {
            $prices->push($this->price);
        }

        return $prices->isEmpty() ? null : (int) $prices->min();
    }

    public function maxPrice(): ?int
    {
        $prices = $this->variants->where('is_active', true)
            ->map(fn (ProductVariant $v) => $v->price ?? $this->price)
            ->filter(fn ($p) => $p !== null);
        if ($prices->isEmpty() && $this->price !== null) {
            return $this->price;
        }

        return $prices->isEmpty() ? null : (int) $prices->max();
    }
}
