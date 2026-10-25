<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Occasion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OccasionService
{
    public function __construct(
        private CatalogImageStore $images,
        private ActivityLogger $activity,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function save(?Occasion $occasion, array $data, User $actor): Occasion
    {
        $occasion ??= new Occasion;
        $isNew = ! $occasion->exists;

        return DB::transaction(function () use ($occasion, $data, $actor, $isNew) {
            if (array_key_exists('slug', $data) && blank($data['slug'])) {
                unset($data['slug']);
            }
            $occasion->fill(collect($data)->except('product_ids')->all());
            $occasion->slug ??= $this->uniqueSlug($occasion->name);
            $changed = array_keys($occasion->getDirty());
            $occasion->save();

            if (array_key_exists('product_ids', $data)) {
                $this->sync($occasion, $data['product_ids']);
                $changed[] = 'products';
            }
            $this->activity->record($actor, $isNew ? 'created' : 'updated', $occasion, $changed);

            return $occasion->refresh();
        });
    }

    /** @param  list<int>  $productIds */
    public function syncProducts(Occasion $occasion, array $productIds, User $actor): Occasion
    {
        DB::transaction(function () use ($occasion, $productIds, $actor) {
            $this->sync($occasion, $productIds);
            $this->activity->record($actor, 'updated', $occasion, ['products']);
        });

        return $occasion;
    }

    public function setStatus(Occasion $occasion, CatalogStatus $status, User $actor): Occasion
    {
        $occasion->status = $status;
        if ($status === CatalogStatus::Published) {
            $occasion->published_at ??= now();
        }
        $occasion->save();
        $this->activity->record($actor, $status->value, $occasion, ['status']);

        return $occasion;
    }

    /** Only never-published occasions can be deleted; products are kept. */
    public function delete(Occasion $occasion, User $actor): void
    {
        if ($occasion->published_at !== null) {
            throw ValidationException::withMessages([
                'occasion' => 'Only never-published occasions can be deleted. Archive it instead.',
            ]);
        }
        $descriptor = $occasion->image;
        DB::transaction(function () use ($occasion, $actor) {
            $this->activity->record($actor, 'deleted', $occasion);
            $occasion->delete();
        });
        $this->images->delete($descriptor);
    }

    public function setImage(Occasion $occasion, UploadedFile $file, ?string $alt, User $actor): Occasion
    {
        $previous = $occasion->image;
        $descriptor = $this->images->store($file, 'occasions');

        try {
            DB::transaction(function () use ($occasion, $descriptor, $alt, $actor) {
                $occasion->forceFill(['image' => $descriptor, 'image_alt' => $alt])->save();
                $this->activity->record($actor, 'updated', $occasion, ['image']);
            });
        } catch (Throwable $e) {
            $this->images->delete($descriptor);
            throw $e;
        }
        $this->images->delete($previous);

        return $occasion;
    }

    public function removeImage(Occasion $occasion, User $actor): Occasion
    {
        $previous = $occasion->image;
        DB::transaction(function () use ($occasion, $actor) {
            $occasion->forceFill(['image' => null])->save();
            $this->activity->record($actor, 'updated', $occasion, ['image']);
        });
        $this->images->delete($previous);

        return $occasion;
    }

    /** @param  list<int>  $productIds */
    private function sync(Occasion $occasion, array $productIds): void
    {
        $occasion->products()->sync(
            collect($productIds)->values()->mapWithKeys(
                fn ($id, $position) => [$id => ['position' => $position]],
            )->all(),
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'occasion';
        $slug = $base;
        for ($i = 2; Occasion::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
