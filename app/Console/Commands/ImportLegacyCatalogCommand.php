<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Services\Catalog\CatalogImageStore;
use App\Services\Catalog\SearchTextBuilder;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies legacy (frontend-bundled) catalog entries into the database as
 * DRAFTS so administrators can complete and publish them one by one.
 *
 * Safe by design:
 * - idempotent: entries whose legacy_key already exists are skipped, never
 *   overwritten;
 * - never publishes anything and never deletes anything;
 * - images flagged as licensed stock photography are skipped unless
 *   --include-stock-images is given (they are not PDL products).
 *
 * The manifest is produced by the frontend: `npm run catalog:export-legacy`.
 */
class ImportLegacyCatalogCommand extends Command
{
    protected $signature = 'catalog:import-legacy
        {manifest : Path to the JSON manifest exported by the frontend}
        {--only=* : Import only these legacy keys}
        {--include-stock-images : Also import images marked as licensed stock photos}
        {--dry-run : Show what would happen without writing anything}';

    protected $description = 'Import legacy frontend catalog entries as unpublished drafts (idempotent)';

    public function handle(CatalogImageStore $images, SearchTextBuilder $searchText): int
    {
        $path = (string) $this->argument('manifest');
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($manifest) || ! isset($manifest['collections'], $manifest['products'])) {
            $this->error('Manifest not found or invalid.');

            return self::FAILURE;
        }
        $only = $this->option('only');
        $dryRun = (bool) $this->option('dry-run');

        $collectionIds = [];
        foreach ($manifest['collections'] as $entry) {
            $key = 'collection:'.$entry['slug'];
            $existing = ProductCollection::where('legacy_key', $key)->orWhere('slug', $entry['slug'])->first();
            if ($existing) {
                $collectionIds[$entry['slug']] = $existing->id;
                $this->line("  collection {$entry['slug']}: exists, skipped");

                continue;
            }
            $this->info("  collection {$entry['slug']}: create draft");
            if (! $dryRun) {
                $collectionIds[$entry['slug']] = ProductCollection::create([
                    'name' => $entry['name'],
                    'slug' => $entry['slug'],
                    'description' => $entry['description'] ?? null,
                    'legacy_key' => $key,
                ])->id;
            }
        }

        $created = 0;
        foreach ($manifest['products'] as $entry) {
            $key = (string) $entry['legacy_key'];
            if ($only && ! in_array($key, $only, true)) {
                continue;
            }
            if (Product::where('legacy_key', $key)->orWhere('slug', $entry['slug'])->exists()) {
                $this->line("  product {$key}: exists, skipped");

                continue;
            }
            $imageEntries = array_values(array_filter(
                $entry['images'] ?? [],
                fn ($i) => $this->option('include-stock-images') || empty($i['licensed_stock']),
            ));
            $this->info("  product {$key}: create draft with ".count($imageEntries).' image(s)');
            if ($dryRun) {
                continue;
            }

            $stored = [];
            try {
                foreach ($imageEntries as $image) {
                    $file = new UploadedFile($image['file'], basename($image['file']), null, null, true);
                    $stored[] = ['descriptor' => $images->store($file, 'products'), 'alt' => $image['alt'] ?? null];
                }

                DB::transaction(function () use ($entry, $key, $stored, $collectionIds, $searchText) {
                    $category = $entry['garment']
                        ? Category::firstOrCreate(['slug' => Str::slug($entry['garment'])], ['name' => $entry['garment']])
                        : null;
                    $product = Product::create([
                        'name' => $entry['name'],
                        'slug' => $entry['slug'],
                        'sku' => 'LEGACY-'.strtoupper(Str::slug($key)),
                        'description' => $entry['description'] ?? null,
                        'category_id' => $category?->id,
                        'garment_type' => $entry['garment'] ?? null,
                        'legacy_key' => $key,
                    ]);
                    if (isset($collectionIds[$entry['collection']])) {
                        $product->collections()->attach($collectionIds[$entry['collection']]);
                    }
                    foreach ($stored as $index => $image) {
                        $product->images()->make(['alt_text' => $image['alt'], 'display_order' => $index + 1])
                            ->forceFill([
                                'storage_key' => $image['descriptor']['key'],
                                'width' => $image['descriptor']['width'],
                                'height' => $image['descriptor']['height'],
                                'widths' => $image['descriptor']['widths'],
                                'is_primary' => $index === 0,
                            ])->save();
                    }
                    $searchText->refresh($product);
                });
                $created++;
            } catch (Throwable $e) {
                foreach ($stored as $image) {
                    $images->delete($image['descriptor']);
                }
                $this->error("  product {$key}: failed ({$e->getMessage()})");
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete; nothing was written.' : "Created {$created} draft product(s). Nothing was published.");

        return self::SUCCESS;
    }
}
