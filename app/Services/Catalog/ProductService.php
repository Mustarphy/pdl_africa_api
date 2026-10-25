<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductService
{
    public function __construct(
        private CatalogImageStore $images,
        private SearchTextBuilder $searchText,
        private PublicationRules $rules,
        private ActivityLogger $activity,
    ) {}

    /**
     * Creates or updates a product with its collections and variants as one
     * transaction: either everything is saved or nothing is.
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function save(?Product $product, array $data, User $actor): Product
    {
        $product ??= new Product;
        $isNew = ! $product->exists;

        return DB::transaction(function () use ($product, $data, $actor, $isNew) {
            $attributes = collect($data)->except(['collection_ids', 'occasion_ids', 'variants'])->all();
            if (array_key_exists('slug', $attributes) && blank($attributes['slug'])) {
                unset($attributes['slug']);
            }
            $product->fill($attributes);
            $product->slug ??= $this->uniqueSlug($product->name);
            $product->currency = config('catalog.currency');
            $changed = array_keys($product->getDirty());
            $product->save();

            if (array_key_exists('collection_ids', $data)) {
                $product->collections()->sync(
                    collect($data['collection_ids'])->values()->mapWithKeys(
                        fn ($id, $position) => [$id => ['position' => $position]],
                    )->all(),
                );
                $changed[] = 'collections';
            }

            if (array_key_exists('occasion_ids', $data)) {
                $product->occasions()->sync(collect($data['occasion_ids'])->values()->all());
                $changed[] = 'occasions';
            }

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants']);
                $changed[] = 'variants';
            }

            $product->unsetRelations();
            if ($product->isPublished() && $issues = $this->rules->productIssues($product)) {
                throw ValidationException::withMessages(['publication' => $issues]);
            }

            $this->searchText->refresh($product);
            $this->activity->record($actor, $isNew ? 'created' : 'updated', $product, $changed);

            return $product->refresh();
        });
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncVariants(Product $product, array $rows): void
    {
        $keepIds = collect($rows)->pluck('id')->filter()->all();
        // Removed variants go first so their SKUs/size-colour pairs can be reused.
        $product->variants()->whereNotIn('id', $keepIds)->delete();

        foreach (array_values($rows) as $position => $row) {
            $values = collect($row)->except('id')->all() + ['display_order' => $position];
            if (! empty($row['id'])) {
                $product->variants()->whereKey($row['id'])->firstOrFail()->update($values);
            } else {
                $product->variants()->create($values);
            }
        }
    }

    public function setStatus(Product $product, CatalogStatus $status, User $actor): Product
    {
        if ($status === CatalogStatus::Published && $issues = $this->rules->productIssues($product)) {
            throw ValidationException::withMessages(['publication' => $issues]);
        }

        $product->status = $status;
        if ($status === CatalogStatus::Published) {
            $product->published_at ??= now();
        }
        $product->save();
        $this->activity->record($actor, $status->value, $product, ['status']);

        return $product;
    }

    /** Hard delete is only allowed for drafts that were never public. */
    public function delete(Product $product, User $actor): void
    {
        if ($product->status !== CatalogStatus::Draft || $product->published_at !== null) {
            throw ValidationException::withMessages([
                'product' => 'Only drafts that were never published can be deleted. Archive this product instead.',
            ]);
        }

        $descriptors = $product->images()->get()->map->descriptor()->all();
        DB::transaction(function () use ($product, $actor) {
            $this->activity->record($actor, 'deleted', $product);
            $product->delete();
        });
        // Files are removed only once the rows are gone.
        foreach ($descriptors as $descriptor) {
            $this->images->delete($descriptor);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string|null>  $altTexts
     * @return list<ProductImage>
     */
    public function addImages(Product $product, array $files, array $altTexts, User $actor): array
    {
        $existing = $product->images()->count();
        if ($existing + count($files) > config('catalog.images.max_per_product')) {
            throw ValidationException::withMessages([
                'images' => 'A product can have at most '.config('catalog.images.max_per_product').' images.',
            ]);
        }

        // Process files before the transaction; roll files back if the
        // database work fails, so no orphaned files or rows remain.
        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = $this->images->store($file, 'products');
            }

            return DB::transaction(function () use ($product, $stored, $altTexts, $actor) {
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                $order = (int) $product->images()->max('display_order');
                $created = [];
                foreach ($stored as $index => $descriptor) {
                    $image = new ProductImage([
                        'alt_text' => $altTexts[$index] ?? null,
                        'display_order' => ++$order,
                    ]);
                    $image->forceFill([
                        'storage_key' => $descriptor['key'],
                        'width' => $descriptor['width'],
                        'height' => $descriptor['height'],
                        'widths' => $descriptor['widths'],
                        'is_primary' => ! $hasPrimary && $index === 0,
                    ]);
                    $product->images()->save($image);
                    $created[] = $image;
                }
                $this->activity->record($actor, 'updated', $product, ['images']);

                return $created;
            });
        } catch (Throwable $e) {
            foreach ($stored as $descriptor) {
                $this->images->delete($descriptor);
            }
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    public function updateImage(ProductImage $image, array $data, User $actor): ProductImage
    {
        return DB::transaction(function () use ($image, $data, $actor) {
            $image->fill(collect($data)->only(['alt_text', 'product_variant_id'])->all());
            if (($data['is_primary'] ?? false) === true && ! $image->is_primary) {
                ProductImage::where('product_id', $image->product_id)->where('is_primary', true)->update(['is_primary' => false]);
                $image->is_primary = true;
            }
            $image->save();
            $this->activity->record($actor, 'updated', $image->product, ['images']);

            return $image;
        });
    }

    /** @param  list<int>  $orderedIds */
    public function reorderImages(Product $product, array $orderedIds, User $actor): void
    {
        $current = $product->images()->pluck('id')->sort()->values()->all();
        $requested = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($current !== $requested) {
            throw ValidationException::withMessages(['image_ids' => 'Provide every image of this product exactly once.']);
        }

        DB::transaction(function () use ($product, $orderedIds, $actor) {
            foreach (array_values($orderedIds) as $position => $id) {
                ProductImage::whereKey($id)->where('product_id', $product->id)->update(['display_order' => $position + 1]);
            }
            $this->activity->record($actor, 'updated', $product, ['images']);
        });
    }

    public function deleteImage(ProductImage $image, User $actor): void
    {
        $product = $image->product;
        $descriptor = $image->descriptor();

        DB::transaction(function () use ($image, $product, $actor) {
            $wasPrimary = $image->is_primary;
            $image->delete();
            if ($wasPrimary) {
                $next = $product->images()->first();
                $next?->forceFill(['is_primary' => true])->save();
            }
            $product->unsetRelations();
            if ($product->isPublished() && $issues = $this->rules->productIssues($product)) {
                throw ValidationException::withMessages(['publication' => $issues]);
            }
            $this->activity->record($actor, 'updated', $product, ['images']);
        });

        $this->images->delete($descriptor);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        for ($i = 2; Product::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
