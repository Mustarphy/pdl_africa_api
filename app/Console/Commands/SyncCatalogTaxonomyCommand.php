<?php

namespace App\Console\Commands;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\ProductCollection;
use App\Services\Catalog\ActivityLogger;
use App\Services\Catalog\SearchTextBuilder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Brings the catalogue in line with PDL Africa's official structure:
 * collections and categories Agbada, Suits, Kaftans and Pants (Senator is
 * part of Kaftans), plus the suggested occasions as unpublished drafts.
 *
 * Safe by design: idempotent, never deletes anything and never touches
 * product IDs, SKUs, prices, variants or images. A retired Senator record is
 * renamed to Kaftans when no Kaftans record exists yet (keeping its products,
 * images and published state); otherwise its products move to Kaftans and the
 * Senator record is archived.
 */
class SyncCatalogTaxonomyCommand extends Command
{
    /** @var array<string, string> slug => name, in display order */
    public const OFFICIAL = [
        'agbada' => 'Agbada',
        'suits' => 'Suits',
        'kaftans' => 'Kaftans',
        'pants' => 'Pants',
    ];

    /** Retired slugs/names and the official slug that replaces them. */
    private const RETIRED = ['senator' => 'kaftans', 'senator-wear' => 'kaftans'];

    /** @var array<string, string> slug => name */
    public const SUGGESTED_OCCASIONS = [
        'weddings' => 'Weddings',
        'traditional-ceremonies' => 'Traditional Ceremonies',
        'formal-events' => 'Formal Events',
        'business-corporate' => 'Business & Corporate',
        'celebrations' => 'Celebrations & Special Occasions',
    ];

    protected $signature = 'catalog:sync-taxonomy
        {--dry-run : Show what would change without writing anything}
        {--skip-occasions : Do not create the suggested draft occasions}';

    protected $description = 'Apply the official collections/categories (Senator -> Kaftans) and create draft occasions';

    private bool $dryRun = false;

    /** @var array<string, list<string>> slugs a dry run has planned to create, per model */
    private array $planned = [];

    public function handle(ActivityLogger $activity, SearchTextBuilder $searchText): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $run = function () use ($activity, $searchText) {
            $this->retire(ProductCollection::class, 'collection', $activity, $searchText);
            $this->retire(Category::class, 'category', $activity, $searchText);
            $this->ensureOfficial(ProductCollection::class, 'collection', CatalogStatus::Draft, $activity);
            $this->ensureOfficial(Category::class, 'category', CatalogStatus::Published, $activity);
            if (! $this->option('skip-occasions')) {
                $this->ensureOccasions($activity);
            }
        };
        $this->dryRun ? $run() : DB::transaction($run);

        $this->newLine();
        $this->info($this->dryRun ? 'Dry run complete; nothing was written.' : 'Catalogue taxonomy is up to date. Nothing was deleted.');

        return self::SUCCESS;
    }

    /** @param  class-string<ProductCollection|Category>  $model */
    private function retire(string $model, string $label, ActivityLogger $activity, SearchTextBuilder $searchText): void
    {
        $retired = $model::query()
            ->where(function ($q) {
                $q->whereIn('slug', array_keys(self::RETIRED))
                    ->orWhereIn(DB::raw('LOWER(name)'), ['senator', 'senator wear']);
            })
            ->where('status', '!=', CatalogStatus::Archived)
            ->get();

        foreach ($retired as $record) {
            $targetSlug = self::RETIRED[$record->slug] ?? 'kaftans';
            $target = $model::where('slug', $targetSlug)->first();

            if (! $target) {
                $this->line("  {$label} \"{$record->name}\": rename to \"".self::OFFICIAL[$targetSlug]."\" ({$targetSlug}), keeping its products and images");
                $this->planned[$model][] = $targetSlug;
                if (! $this->dryRun) {
                    $record->forceFill(['name' => self::OFFICIAL[$targetSlug], 'slug' => $targetSlug])->save();
                    $activity->record(null, 'updated', $record, ['name', 'slug']);
                    $this->refreshSearch($record, $searchText);
                }

                continue;
            }

            $count = $record->products()->count();
            $this->line("  {$label} \"{$record->name}\": move {$count} product(s) to \"{$target->name}\", then archive it");
            if ($this->dryRun) {
                continue;
            }
            if ($record instanceof ProductCollection && $target instanceof ProductCollection) {
                $position = (int) DB::table('collection_product')->where('collection_id', $target->id)->max('position');
                foreach ($record->products()->pluck('products.id') as $productId) {
                    DB::table('collection_product')->insertOrIgnore([
                        'collection_id' => $target->id,
                        'product_id' => $productId,
                        'position' => ++$position,
                    ]);
                }
                $record->products()->detach();
            } else {
                $record->products()->update(['category_id' => $target->id]);
            }
            $record->forceFill(['status' => CatalogStatus::Archived])->save();
            $activity->record(null, 'archived', $record, ['status']);
            $this->refreshSearch($target, $searchText);
        }
    }

    /** @param  class-string<ProductCollection|Category>  $model */
    private function ensureOfficial(string $model, string $label, CatalogStatus $status, ActivityLogger $activity): void
    {
        $order = 0;
        foreach (self::OFFICIAL as $slug => $name) {
            $order++;
            if (in_array($slug, $this->planned[$model] ?? [], true) || $model::where('slug', $slug)->exists()) {
                $this->line("  {$label} {$slug}: exists");

                continue;
            }
            $this->info("  {$label} {$slug}: create \"{$name}\" ({$status->value})");
            if (! $this->dryRun) {
                $record = new $model(['name' => $name, 'slug' => $slug, 'display_order' => $order]);
                $record->forceFill(['status' => $status])->save();
                $activity->record(null, 'created', $record, ['name', 'slug']);
            }
        }
    }

    private function ensureOccasions(ActivityLogger $activity): void
    {
        $order = 0;
        foreach (self::SUGGESTED_OCCASIONS as $slug => $name) {
            $order++;
            if (Occasion::where('slug', $slug)->exists()) {
                $this->line("  occasion {$slug}: exists");

                continue;
            }
            $this->info("  occasion {$slug}: create \"{$name}\" (draft, no products assigned)");
            if (! $this->dryRun) {
                $occasion = Occasion::create(['name' => $name, 'slug' => $slug, 'display_order' => $order]);
                $activity->record(null, 'created', $occasion, ['name', 'slug']);
            }
        }
    }

    private function refreshSearch(Model $record, SearchTextBuilder $searchText): void
    {
        if ($record instanceof ProductCollection) {
            $searchText->refreshForCollection($record);
        } elseif ($record instanceof Category) {
            $searchText->refreshForCategory($record);
        }
    }
}
