<?php

namespace App\Models;

use App\Enums\VariantAvailability;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sku', 'size', 'color', 'color_hex', 'price', 'stock_quantity',
    'availability', 'is_active', 'display_order',
])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $attributes = [
        'size' => '',
        'color' => '',
        'availability' => 'available',
        'is_active' => true,
        'display_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock_quantity' => 'integer',
            'availability' => VariantAvailability::class,
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Stored as '' (see migration); exposed as null when not applicable. */
    protected function size(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === '' ? null : $value,
            set: fn (?string $value) => trim((string) $value),
        );
    }

    protected function color(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === '' ? null : $value,
            set: fn (?string $value) => trim((string) $value),
        );
    }

    public function isPurchasable(): bool
    {
        return $this->is_active
            && $this->availability !== VariantAvailability::OutOfStock
            && ($this->stock_quantity === null || $this->stock_quantity > 0
                || $this->availability === VariantAvailability::MadeToOrder);
    }
}
