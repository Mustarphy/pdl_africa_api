<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The type of garment (e.g. Agbada, Senator), used for browsing/filters. */
#[Fillable(['name', 'slug', 'description', 'status', 'display_order'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'display_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'display_order' => 'integer',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CatalogStatus::Published);
    }
}
