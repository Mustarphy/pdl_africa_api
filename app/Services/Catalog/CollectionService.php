<?php

namespace App\Services\Catalog;

use App\Enums\CatalogStatus;
use App\Models\ProductCollection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CollectionService
{
    public function __construct(
        private CatalogImageStore $images,
        private SearchTextBuilder $searchText,
        private PublicationRules $rules,
        private ActivityLogger $activity,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function save(?ProductCollection $collection, array $data, User $actor): ProductCollection
    {
        $collection ??= new ProductCollection;
        $isNew = ! $collection->exists;

        return DB::transaction(function () use ($collection, $data, $actor, $isNew) {
            if (array_key_exists('slug', $data) && blank($data['slug'])) {
                unset($data['slug']);
            }
            $collection->fill($data);
            $collection->slug ??= $this->uniqueSlug($collection->name);
            $changed = array_keys($collection->getDirty());
            $collection->save();

            if (! $isNew && in_array('name', $changed, true)) {
                $this->searchText->refreshForCollection($collection);
            }
            $this->activity->record($actor, $isNew ? 'created' : 'updated', $collection, $changed);

            return $collection->refresh();
        });
    }

    public function setStatus(ProductCollection $collection, CatalogStatus $status, User $actor): ProductCollection
    {
        if ($status === CatalogStatus::Published && $issues = $this->rules->collectionIssues($collection)) {
            throw ValidationException::withMessages(['publication' => $issues]);
        }
        $collection->status = $status;
        if ($status === CatalogStatus::Published) {
            $collection->published_at ??= now();
        }
        $collection->save();
        $this->activity->record($actor, $status->value, $collection, ['status']);

        return $collection;
    }

    public function delete(ProductCollection $collection, User $actor): void
    {
        if ($collection->published_at !== null || $collection->products()->exists()) {
            throw ValidationException::withMessages([
                'collection' => 'Only never-published collections without products can be deleted. Archive it instead.',
            ]);
        }
        $descriptors = [$collection->cover_image, $collection->banner_image];
        DB::transaction(function () use ($collection, $actor) {
            $this->activity->record($actor, 'deleted', $collection);
            $collection->delete();
        });
        foreach ($descriptors as $descriptor) {
            $this->images->delete($descriptor);
        }
    }

    /** @param  'cover'|'banner'  $slot */
    public function setImage(ProductCollection $collection, string $slot, UploadedFile $file, ?string $alt, User $actor): ProductCollection
    {
        $column = "{$slot}_image";
        $previous = $collection->{$column};
        $descriptor = $this->images->store($file, 'collections');

        try {
            DB::transaction(function () use ($collection, $column, $descriptor, $alt, $slot, $actor) {
                $collection->forceFill([$column => $descriptor, "{$slot}_image_alt" => $alt])->save();
                $this->activity->record($actor, 'updated', $collection, [$column]);
            });
        } catch (Throwable $e) {
            $this->images->delete($descriptor);
            throw $e;
        }
        // The replaced files are no longer referenced once the row is saved.
        $this->images->delete($previous);

        return $collection;
    }

    /** @param  'cover'|'banner'  $slot */
    public function removeImage(ProductCollection $collection, string $slot, User $actor): ProductCollection
    {
        $column = "{$slot}_image";
        $previous = $collection->{$column};

        DB::transaction(function () use ($collection, $column, $actor) {
            $collection->forceFill([$column => null])->save();
            if ($collection->isPublished() && $issues = $this->rules->collectionIssues($collection)) {
                throw ValidationException::withMessages(['publication' => $issues]);
            }
            $this->activity->record($actor, 'updated', $collection, [$column]);
        });
        $this->images->delete($previous);

        return $collection;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        for ($i = 2; ProductCollection::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
