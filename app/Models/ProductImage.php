<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A processed product image. `storage_key` identifies generated files on the
 * public disk; it is never derived from client input and never exposed.
 */
#[Fillable(['alt_text', 'display_order', 'product_variant_id'])]
#[Hidden(['storage_key', 'primary_product_id'])]
class ProductImage extends Model
{
    protected $attributes = [
        'is_primary' => false,
        'display_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'widths' => 'array',
            'is_primary' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Descriptor understood by CatalogImageStore. */
    public function descriptor(): array
    {
        return [
            'key' => $this->storage_key,
            'width' => $this->width,
            'height' => $this->height,
            'widths' => $this->widths,
        ];
    }
}
