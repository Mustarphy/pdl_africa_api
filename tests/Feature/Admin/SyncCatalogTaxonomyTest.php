<?php

namespace Tests\Feature\Admin;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Occasion;
use App\Models\ProductCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class SyncCatalogTaxonomyTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_senator_becomes_kaftans_when_no_kaftans_record_exists(): void
    {
        $senator = ProductCollection::factory()->published()->create(['name' => 'Senator Wear', 'slug' => 'senator']);
        $category = Category::factory()->create(['name' => 'Senator', 'slug' => 'senator', 'status' => 'published']);
        $product = $this->publishedProduct(['category_id' => $category->id], $senator);
        $before = $product->only(['id', 'sku', 'slug', 'price']);

        $this->artisan('catalog:sync-taxonomy')->assertSuccessful();

        $senator->refresh();
        $this->assertSame(['kaftans', 'Kaftans', CatalogStatus::Published], [$senator->slug, $senator->name, $senator->status]);
        $this->assertSame('kaftans', $category->refresh()->slug);
        $product->refresh();
        $this->assertSame($before, $product->only(['id', 'sku', 'slug', 'price']));
        $this->assertSame([$senator->id], $product->collections()->pluck('collections.id')->all());
        $this->assertStringContainsString('kaftans', $product->search_text);

        $this->assertSame(
            ['agbada', 'kaftans', 'pants', 'suits'],
            ProductCollection::orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertSame(5, Occasion::where('status', 'draft')->count());
        $this->assertSame(0, Occasion::has('products')->count());
    }

    public function test_senator_products_move_to_an_existing_kaftans_record_and_senator_is_archived(): void
    {
        $kaftans = ProductCollection::factory()->published()->create(['name' => 'Kaftans', 'slug' => 'kaftans']);
        $senator = ProductCollection::factory()->published()->create(['name' => 'Senator', 'slug' => 'senator']);
        $kaftanCategory = Category::factory()->create(['name' => 'Kaftans', 'slug' => 'kaftans']);
        $senatorCategory = Category::factory()->create(['name' => 'Senator', 'slug' => 'senator-wear']);
        $both = $this->publishedProduct(['category_id' => $senatorCategory->id], $senator);
        $both->collections()->attach($kaftans->id, ['position' => 1]);
        $only = $this->publishedProduct(['category_id' => $senatorCategory->id], $senator);

        $this->artisan('catalog:sync-taxonomy', ['--skip-occasions' => true])->assertSuccessful();

        $this->assertSame(CatalogStatus::Archived, $senator->refresh()->status);
        $this->assertSame(0, $senator->products()->count());
        $this->assertEqualsCanonicalizing([$both->id, $only->id], $kaftans->products()->pluck('products.id')->all());
        $this->assertSame(CatalogStatus::Archived, $senatorCategory->refresh()->status);
        $this->assertSame($kaftanCategory->id, $only->refresh()->category_id);
        $this->assertModelExists($senator);
        $this->assertSame(0, Occasion::count());

        // Idempotent: a second run changes nothing.
        $this->artisan('catalog:sync-taxonomy', ['--skip-occasions' => true])->assertSuccessful();
        $this->assertSame(2, $kaftans->products()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $senator = ProductCollection::factory()->create(['name' => 'Senator', 'slug' => 'senator']);

        $this->artisan('catalog:sync-taxonomy', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('senator', $senator->refresh()->slug);
        $this->assertSame(1, ProductCollection::count());
        $this->assertSame(0, Occasion::count());
    }
}
