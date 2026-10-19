<?php

namespace App\Services\Catalog;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores catalog images safely:
 * - decodes the upload with GD (only real JPEG/PNG/WebP pass),
 * - re-encodes to WebP at several widths, which strips metadata and any
 *   payload hidden in the original file,
 * - writes files under a generated key (client filenames are never used).
 *
 * A "descriptor" ({key, width, height, widths}) is what gets persisted.
 */
class CatalogImageStore
{
    private const KEY_PATTERN = '#^catalog/(products|collections)/[0-9a-f-]{36}$#';

    /** @var array<int, string> */
    private const DECODERS = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];

    public function disk(): Filesystem
    {
        return Storage::disk(config('catalog.images.disk'));
    }

    /**
     * @param  'products'|'collections'  $folder
     * @return array{key: string, width: int, height: int, widths: list<int>}
     */
    public function store(UploadedFile $file, string $folder): array
    {
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! isset(self::DECODERS[$info[2]])) {
            throw ValidationException::withMessages(['image' => 'The file is not a valid JPEG, PNG or WebP image.']);
        }

        [$originalWidth, $originalHeight] = $info;
        $min = config('catalog.images.min_dimension');
        $max = config('catalog.images.max_dimension');
        if (min($originalWidth, $originalHeight) < $min || max($originalWidth, $originalHeight) > $max) {
            throw ValidationException::withMessages([
                'image' => "Images must be between {$min} and {$max} pixels on each side.",
            ]);
        }

        // Decoding needs width x height x 4 bytes; allow up to 6000x6000.
        $previousLimit = ini_set('memory_limit', '512M');

        try {
            $source = (self::DECODERS[$info[2]])($file->getRealPath());
            if (! $source instanceof GdImage) {
                throw ValidationException::withMessages(['image' => 'The image could not be read.']);
            }
            $source = $this->applyExifOrientation($source, $file, $info[2]);
            imagepalettetotruecolor($source);
            imagealphablending($source, true);
            imagesavealpha($source, true);

            return $this->writeVariants($source, $folder);
        } finally {
            if ($previousLimit !== false) {
                ini_set('memory_limit', $previousLimit);
            }
        }
    }

    /**
     * @return array{key: string, width: int, height: int, widths: list<int>}
     */
    private function writeVariants(GdImage $source, string $folder): array
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $largest = min($width, max(config('catalog.images.widths')));
        $widths = array_values(array_filter(config('catalog.images.widths'), fn (int $w) => $w < $largest));
        $widths[] = $largest;

        $key = "catalog/{$folder}/".Str::uuid();
        $written = [];

        try {
            foreach ($widths as $targetWidth) {
                $scaled = $targetWidth === $width ? $source : imagescale($source, $targetWidth, -1, IMG_BICUBIC);
                if (! $scaled instanceof GdImage) {
                    throw new \RuntimeException('Image resize failed.');
                }
                imagesavealpha($scaled, true);

                ob_start();
                imagewebp($scaled, null, config('catalog.images.webp_quality'));
                $contents = (string) ob_get_clean();

                $path = $this->path($key, $targetWidth);
                if (! $this->disk()->put($path, $contents, ['visibility' => 'public'])) {
                    throw new \RuntimeException('Image could not be stored.');
                }
                $written[] = $path;
            }
        } catch (Throwable $e) {
            $this->disk()->delete($written);
            throw $e;
        }

        return [
            'key' => $key,
            'width' => $largest,
            'height' => (int) round($height * $largest / $width),
            'widths' => $widths,
        ];
    }

    /** Deletes the generated files of a descriptor (only keys we created). */
    public function delete(?array $descriptor): void
    {
        if (! $descriptor || ! is_string($descriptor['key'] ?? null) || ! preg_match(self::KEY_PATTERN, $descriptor['key'])) {
            return;
        }
        $this->disk()->delete(array_map(
            fn ($w) => $this->path($descriptor['key'], (int) $w),
            (array) ($descriptor['widths'] ?? []),
        ));
    }

    /**
     * Public URLs for a descriptor. Storage keys/paths are not exposed.
     *
     * @return array{src: string, srcset: list<array{url: string, width: int}>, width: int, height: int}|null
     */
    public function urls(?array $descriptor): ?array
    {
        if (! $descriptor || ! preg_match(self::KEY_PATTERN, (string) ($descriptor['key'] ?? ''))) {
            return null;
        }
        $srcset = array_map(fn ($w) => [
            'url' => $this->disk()->url($this->path($descriptor['key'], (int) $w)),
            'width' => (int) $w,
        ], $descriptor['widths']);

        return [
            'src' => end($srcset)['url'],
            'srcset' => $srcset,
            'width' => (int) $descriptor['width'],
            'height' => (int) $descriptor['height'],
        ];
    }

    private function path(string $key, int $width): string
    {
        return "{$key}-{$width}.webp";
    }

    /** Camera photos store rotation in EXIF; apply it before re-encoding. */
    private function applyExifOrientation(GdImage $image, UploadedFile $file, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
