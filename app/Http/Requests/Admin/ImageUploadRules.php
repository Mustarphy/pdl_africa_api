<?php

namespace App\Http\Requests\Admin;

/**
 * Upload rules shared by product and collection images. These are a first
 * gate; CatalogImageStore additionally decodes and re-encodes every file.
 */
final class ImageUploadRules
{
    /** @return list<string> */
    public static function file(): array
    {
        $min = config('catalog.images.min_dimension');
        $max = config('catalog.images.max_dimension');

        return [
            'required',
            'file',
            'image', // excludes SVG
            'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.config('catalog.images.max_upload_kb'),
            "dimensions:min_width={$min},min_height={$min},max_width={$max},max_height={$max}",
        ];
    }
}
