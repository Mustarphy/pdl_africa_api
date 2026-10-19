<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Catalog\SearchTextBuilder;
use Illuminate\Support\Str;

trait BuildsCatalog
{
    /** Image row with a valid (fake) descriptor; no files needed for reads. */
    protected function attachImage(Product $product, bool $primary = true, string $alt = 'Model wearing the garment'): ProductImage
    {
        $image = new ProductImage(['alt_text' => $alt, 'display_order' => 1]);
        $image->forceFill([
            'storage_key' => 'catalog/products/'.Str::uuid(),
            'width' => 960,
            'height' => 1200,
            'widths' => [480, 960],
            'is_primary' => $primary,
        ]);
        $product->images()->save($image);

        return $image;
    }

    /**
     * A product that satisfies every publication rule.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $variants
     */
    protected function publishedProduct(array $attributes = [], ?ProductCollection $collection = null, array $variants = []): Product
    {
        $collection ??= ProductCollection::factory()->published()->create();
        $product = Product::factory()->published()->create($attributes + [
            'category_id' => Category::factory(),
        ]);
        $product->collections()->attach($collection);
        foreach ($variants as $variant) {
            ProductVariant::factory()->for($product)->create($variant);
        }
        $this->attachImage($product);
        app(SearchTextBuilder::class)->refresh($product);

        return $product->refresh();
    }
}
