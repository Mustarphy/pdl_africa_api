<?php

namespace Tests\Feature\Admin;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\ProductCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportLegacyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function manifest(): string
    {
        // Fake uploads live in tmpfile() handles that vanish when garbage
        // collected, so persist real files for the command to read.
        $persist = function (string $name) {
            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('legacy-').'-'.$name;
            $fake = UploadedFile::fake()->image($name, 800, 1000);
            copy($fake->getRealPath(), $path);

            return $path;
        };
        $own = $persist('own.jpg');
        $stock = $persist('stock.jpg');
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, json_encode([
            'collections' => [['slug' => 'senator', 'name' => 'Senator Wear']],
            'products' => [[
                'legacy_key' => 'dev-senator-cream',
                'slug' => 'cream-senator',
                'name' => 'Cream Senator',
                'collection' => 'senator',
                'garment' => 'Senator',
                'images' => [
                    ['file' => $own, 'alt' => 'Cream senator on a mannequin'],
                    ['file' => $stock, 'alt' => 'Stock photo', 'licensed_stock' => true],
                ],
            ]],
        ]));

        return $path;
    }

    public function test_imports_drafts_once_and_never_publishes(): void
    {
        Storage::fake('public');
        $manifest = $this->manifest();

        $this->artisan('catalog:import-legacy', ['manifest' => $manifest])->assertSuccessful();

        $product = Product::with('images', 'collections')->sole();
        $this->assertSame(CatalogStatus::Draft, $product->status);
        $this->assertSame('dev-senator-cream', $product->legacy_key);
        $this->assertCount(1, $product->images, 'stock images are skipped by default');
        $this->assertSame(CatalogStatus::Draft, ProductCollection::sole()->status);
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');

        // Re-running changes nothing (idempotent, no overwrite).
        $product->update(['name' => 'Edited by admin']);
        $this->artisan('catalog:import-legacy', ['manifest' => $manifest])->assertSuccessful();
        $this->assertSame(1, Product::count());
        $this->assertSame('Edited by admin', $product->fresh()->name);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Storage::fake('public');
        $this->artisan('catalog:import-legacy', ['manifest' => $this->manifest(), '--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
