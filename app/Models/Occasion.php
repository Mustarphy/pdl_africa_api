<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\OccasionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An occasion customers dress for (e.g. "Weddings"). Products are assigned
 * explicitly by an administrator; nothing is inferred from the category.
 */
#[Fillable(['name', 'slug', 'description', 'image_alt', 'display_order'])]
class Occasion extends Model
{
    /** @use HasFactory<OccasionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'display_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'image' => 'array',
            'display_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('position');
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
