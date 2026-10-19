<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductCollection;

/**
 * The single definition of "ready to publish". The admin UI shows these
 * messages verbatim (from the API), so frontend and backend never disagree.
 */
class PublicationRules
{
    /** @return list<string> */
    public function productIssues(Product $product): array
    {
        $product->loadMissing(['collections', 'variants', 'primaryImage']);
        $issues = [];

        if ($product->category_id === null) {
            $issues[] = 'Choose a category.';
        }
        if ($product->collections->isEmpty()) {
            $issues[] = 'Add the product to at least one collection.';
        }
        if ($product->primaryImage === null) {
            $issues[] = 'Upload at least one image and choose a primary image.';
        }
        if (blank($product->short_description) && blank($product->description)) {
            $issues[] = 'Add a short or full description.';
        }

        $activeVariants = $product->variants->where('is_active', true);
        if ($product->variants->isNotEmpty() && $activeVariants->isEmpty()) {
            $issues[] = 'At least one variant must be active.';
        }
        $everyActiveVariantPriced = $activeVariants->isNotEmpty()
            && $activeVariants->every(fn ($v) => $v->price !== null);
        if ($product->price === null && ! $everyActiveVariantPriced) {
            $issues[] = 'Set a product price, or a price on every active variant.';
        }

        return $issues;
    }

    /** @return list<string> */
    public function collectionIssues(ProductCollection $collection): array
    {
        $issues = [];
        if (! $collection->banner_image && ! $collection->cover_image) {
            $issues[] = 'Upload a banner or cover image.';
        }

        return $issues;
    }
}
