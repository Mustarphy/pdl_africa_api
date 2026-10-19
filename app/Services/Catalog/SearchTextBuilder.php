<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use Illuminate\Support\Str;

/**
 * Maintains products.search_text: a lower-cased, accent-free blob of every
 * searchable field so search is one indexed-table scan with no joins.
 */
class SearchTextBuilder
{
    public function build(Product $product): string
    {
        $product->loadMissing(['category', 'collections', 'variants']);

        $parts = [
            $product->name,
            $product->sku,
            $product->garment_type,
            $product->material,
            $product->category?->name,
            ...$product->collections->pluck('name'),
            ...$product->variants->pluck('size'),
            ...$product->variants->pluck('color'),
            ...$product->variants->pluck('sku'),
            $product->short_description,
            $product->description,
        ];

        return Str::limit(self::normalize(implode(' ', array_filter($parts))), 4000, '');
    }

    public function refresh(Product $product): void
    {
        $product->unsetRelation('collections')->unsetRelation('variants')->unsetRelation('category');
        // Base query: does not touch updated_at.
        Product::whereKey($product->getKey())->toBase()->update(['search_text' => $this->build($product)]);
    }

    public function refreshForCollection(ProductCollection $collection): void
    {
        $collection->products()->each(fn (Product $p) => $this->refresh($p));
    }

    public function refreshForCategory(Category $category): void
    {
        $category->products()->each(fn (Product $p) => $this->refresh($p));
    }

    public static function normalize(string $text): string
    {
        $ascii = Str::ascii(mb_strtolower($text));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }
}
