<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_only_published_products_are_listed(): void
    {
        $published = $this->publishedProduct(['name' => 'Royal Agbada']);
        Product::factory()->create(['name' => 'Draft Agbada']);
        Product::factory()->archived()->create(['name' => 'Archived Agbada']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $published->slug)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_draft_and_archived_products_are_not_found_by_slug(): void
    {
        $draft = Product::factory()->create();
        $archived = Product::factory()->archived()->create();

        $this->getJson("/api/v1/products/{$draft->slug}")->assertNotFound();
        $this->getJson("/api/v1/products/{$archived->slug}")->assertNotFound();
    }

    public function test_product_detail_exposes_public_fields_only(): void
    {
        $product = $this->publishedProduct(
            ['name' => 'Royal Agbada', 'price' => 12_000_000, 'description' => 'Hand embroidered.'],
            variants: [
                ['sku' => 'RA-M', 'size' => 'M', 'stock_quantity' => 3],
                ['sku' => 'RA-L', 'size' => 'L', 'is_active' => false],
            ],
        );

        $response = $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Royal Agbada')
            ->assertJsonPath('data.price', ['amount' => 12_000_000, 'currency' => 'NGN'])
            ->assertJsonPath('data.description', 'Hand embroidered.')
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.size', 'M')
            ->assertJsonPath('data.variants.0.is_available', true);

        $body = $response->getContent();
        foreach (['search_text', 'storage_key', 'stock_quantity', 'catalog/products/', 'status'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringStartsWith('http', $response->json('data.images.0.src'));
    }

    public function test_unpublished_collections_are_hidden_everywhere(): void
    {
        $visible = ProductCollection::factory()->published()->create(['name' => 'Agbada']);
        $hidden = ProductCollection::factory()->create(['name' => 'Secret Drop']);
        $product = $this->publishedProduct(collection: $visible);
        $product->collections()->attach($hidden);

        $this->getJson('/api/v1/collections')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $visible->slug)
            ->assertJsonPath('data.0.product_count', 1);
        $this->getJson("/api/v1/collections/{$hidden->slug}")->assertNotFound();
        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertJsonCount(1, 'data.collections')
            ->assertJsonPath('data.collections.0.slug', $visible->slug);
        $this->getJson("/api/v1/products?collection={$hidden->slug}")->assertJsonCount(0, 'data');
    }

    public function test_filters_combine_and_facets_count_other_filters(): void
    {
        $agbada = ProductCollection::factory()->published()->create(['name' => 'Agbada']);
        $kaftans = ProductCollection::factory()->published()->create(['name' => 'Kaftans']);
        $this->publishedProduct(['name' => 'Navy Agbada'], $agbada, [['sku' => 'NA-M', 'size' => 'M', 'color' => 'Navy']]);
        $this->publishedProduct(['name' => 'Black Agbada'], $agbada, [['sku' => 'BA-L', 'size' => 'L', 'color' => 'Black']]);
        $this->publishedProduct(['name' => 'Black Kaftan'], $kaftans, [['sku' => 'BK-L', 'size' => 'L', 'color' => 'Black']]);

        $response = $this->getJson("/api/v1/products?collection={$agbada->slug}&color=Black&facets=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Black Agbada');

        $collectionFacet = collect($response->json('facets'))->firstWhere('key', 'collection')['values'];
        // Collection counts apply the colour filter but not the collection one.
        $this->assertEqualsCanonicalizing(
            [[$agbada->slug, 1, true], [$kaftans->slug, 1, false]],
            array_map(fn ($v) => [$v['value'], $v['count'], $v['selected']], $collectionFacet),
        );
    }

    public function test_search_matches_words_case_insensitively_and_ranks_names_first(): void
    {
        $this->publishedProduct(['name' => 'Plain Kaftan', 'description' => 'Pairs well with an agbada.']);
        $this->publishedProduct(['name' => 'Black Agbada', 'sku' => 'PDL-AG-001']);
        $this->publishedProduct(['name' => 'Navy Agbada']);

        $this->getJson('/api/v1/products?q=AGBADAS')->assertJsonPath('data.0.name', fn ($n) => str_contains($n, 'Agbada'))
            ->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/products?q=black%20agbada')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Black Agbada');
        $this->getJson('/api/v1/products?q=pdl-ag-001')->assertJsonPath('data.0.name', 'Black Agbada');
        $this->getJson('/api/v1/products?q=trousers')->assertJsonCount(0, 'data');
    }

    public function test_sorting_and_pagination(): void
    {
        foreach ([30, 10, 20] as $i => $price) {
            $this->publishedProduct(['name' => "Item {$i}", 'price' => $price * 100_000]);
        }

        $this->getJson('/api/v1/products?sort=price_asc&per_page=2')
            ->assertJsonPath('data.0.price.amount', 1_000_000)
            ->assertJsonPath('data.1.price.amount', 2_000_000)
            ->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/products?sort=price_desc&per_page=2&page=2')
            ->assertJsonPath('data.0.price.amount', 1_000_000);
    }

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $this->getJson('/api/v1/products?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/v1/products?sort=drop_table')->assertUnprocessable();
        $this->getJson('/api/v1/products?q='.str_repeat('a', 200))->assertUnprocessable();
    }

    public function test_listing_avoids_n_plus_one_queries(): void
    {
        foreach (range(1, 8) as $i) {
            $this->publishedProduct(variants: [['sku' => "SKU-{$i}", 'size' => 'M']]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(8, 'data');
        $this->assertLessThan(12, count(DB::getQueryLog()));
    }

    public function test_search_suggestions_return_products_and_collections(): void
    {
        $agbada = ProductCollection::factory()->published()->create(['name' => 'Agbada']);
        $this->publishedProduct(['name' => 'Green Agbada'], $agbada);

        $this->getJson('/api/v1/search/suggestions?q=agb')
            ->assertOk()
            ->assertJsonPath('data.collections.0.slug', $agbada->slug)
            ->assertJsonPath('data.products.0.name', 'Green Agbada')
            ->assertJsonPath('data.total', 1);
    }

    public function test_legacy_map_lists_only_published_replacements(): void
    {
        $this->publishedProduct(['slug' => 'teal-kaftan-new', 'legacy_key' => 'dev-kaftan-teal']);
        Product::factory()->create(['legacy_key' => 'dev-kaftan-brown']); // draft: not yet a replacement

        $this->getJson('/api/v1/catalog/legacy-map')->assertOk()
            ->assertExactJson(['data' => [
                'products' => [['legacy_key' => 'dev-kaftan-teal', 'slug' => 'teal-kaftan-new']],
                'collections' => [],
            ]]);
    }

    public function test_public_responses_are_cacheable_and_admin_ones_are_not(): void
    {
        $this->getJson('/api/v1/collections')->assertHeader('Cache-Control', 'max-age=60, public');
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
