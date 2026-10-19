<?php

namespace App\Http\Resources\Catalog;

use App\Models\ProductImage;
use App\Services\Catalog\CatalogImageStore;

/** Shapes image descriptors into public URLs (no storage keys or paths). */
class ImagePresenter
{
    public static function product(ProductImage $image): ?array
    {
        $urls = app(CatalogImageStore::class)->urls($image->descriptor());

        return $urls ? ['id' => $image->id, 'alt' => (string) $image->alt_text] + $urls : null;
    }

    public static function descriptor(?array $descriptor, ?string $alt): ?array
    {
        $urls = app(CatalogImageStore::class)->urls($descriptor);

        return $urls ? ['alt' => (string) $alt] + $urls : null;
    }
}
