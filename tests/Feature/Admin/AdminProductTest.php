<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Royal Blue Agbada',
            'sku' => 'PDL-AG-100',
            'short_description' => 'Three-piece agbada.',
            'category_id' => Category::factory()->create()->id,
            'price' => 15_000_000,
            'collection_ids' => [ProductCollection::factory()->published()->create()->id],
            'variants' => [
                ['sku' => 'PDL-AG-100-M', 'size' => 'M'],
                ['sku' => 'PDL-AG-100-L', 'size' => 'L', 'price' => 16_000_000],
            ],
        ];
    }

    public function test_admin_creates_a_draft_product_with_variants_and_collections(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', 'royal-blue-agbada')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.publication_issues', ['Upload at least one image and choose a primary image.']);

        $product = Product::findOrFail($response->json('data.id'));
        $this->assertStringContainsString('royal blue agbada', $product->search_text);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $product->id, 'action' => 'created']);
    }

    public function test_status_cannot_be_set_through_the_product_form(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/products', $this->payload(['status' => 'published']))
            ->assertCreated();

        $this->assertSame('draft', $response->json('data.status'));
    }

    public function test_validation_rejects_bad_prices_duplicates_and_invalid_variants(): void
    {
        Product::factory()->create(['sku' => 'TAKEN-1', 'slug' => 'taken-slug']);

        $this->actingAs($this->admin)->postJson('/api/v1/admin/products', $this->payload([
            'sku' => 'TAKEN-1',
            'slug' => 'taken-slug',
            'price' => -5,
            'variants' => [
                ['sku' => 'DUP', 'size' => 'M'],
                ['sku' => 'dup', 'size' => 'm'],
                ['sku' => 'HEX', 'color_hex' => 'red'],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'sku', 'slug', 'price', 'variants.1.sku', 'variants.2.color_hex',
        ]);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_duplicate_size_colour_combinations_are_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/products', $this->payload([
            'variants' => [['sku' => 'A1', 'size' => 'M', 'color' => 'Navy'], ['sku' => 'A2', 'size' => 'm', 'color' => 'navy']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['variants.1.size']);
    }

    public function test_publishing_requires_complete_information_then_succeeds(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/admin/products', $this->payload())->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/products/{$id}/status", ['status' => 'published'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['publication']);
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');

        $this->attachImage(Product::findOrFail($id));
        $this->actingAs($this->admin)->postJson("/api/v1/admin/products/{$id}/status", ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson('/api/v1/products')->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/products/{$id}/status", ['status' => 'archived'])
            ->assertOk();
        $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    }

    public function test_update_is_atomic_when_a_later_step_fails(): void
    {
        $product = $this->publishedProduct(['name' => 'Original Name', 'sku' => 'ORIG-1']);

        // Removing every collection would make a published product incomplete:
        // the whole update (including the name change) must roll back.
        $this->actingAs($this->admin)->putJson("/api/v1/admin/products/{$product->id}", [
            'name' => 'Changed Name',
            'collection_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['publication']);

        $product->refresh();
        $this->assertSame('Original Name', $product->name);
        $this->assertCount(1, $product->collections);
    }

    public function test_variants_are_synced_and_foreign_variants_rejected(): void
    {
        $other = $this->publishedProduct(variants: [['sku' => 'OTHER-M', 'size' => 'M']]);
        $id = $this->actingAs($this->admin)->postJson('/api/v1/admin/products', $this->payload())->json('data.id');
        $variants = Product::findOrFail($id)->variants;

        $this->actingAs($this->admin)->putJson("/api/v1/admin/products/{$id}", [
            'variants' => [['id' => $other->variants->first()->id, 'sku' => 'X', 'size' => 'S']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants.0.id']);

        $this->actingAs($this->admin)->putJson("/api/v1/admin/products/{$id}", [
            'variants' => [
                ['id' => $variants[0]->id, 'sku' => 'PDL-AG-100-M', 'size' => 'M', 'stock_quantity' => 4],
                ['sku' => 'PDL-AG-100-XL', 'size' => 'XL'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.variants')
            ->assertJsonPath('data.variants.0.stock_quantity', 4)
            ->assertJsonPath('data.variants.1.size', 'XL');
        $this->assertDatabaseMissing('product_variants', ['sku' => 'PDL-AG-100-L']);
    }

    public function test_published_slug_is_immutable_and_published_products_cannot_be_deleted(): void
    {
        $product = $this->publishedProduct();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/products/{$product->id}", ['slug' => 'new-url'])
            ->assertUnprocessable()->assertJsonValidationErrors(['slug']);
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertUnprocessable();
        $this->assertModelExists($product);

        $draft = Product::factory()->create();
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/products/{$draft->id}")->assertNoContent();
        $this->assertModelMissing($draft);
    }

    public function test_admin_list_filters_and_dashboard_counts_real_data(): void
    {
        $this->publishedProduct(['name' => 'Live One']);
        Product::factory()->create(['name' => 'Draft One']);
        Product::factory()->archived()->create(['name' => 'Old One']);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/products?status=draft')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Draft One');
        $this->actingAs($this->admin)->getJson('/api/v1/admin/products?q=live')
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.products', ['draft' => 1, 'published' => 1, 'archived' => 1, 'total' => 3])
            ->assertJsonPath('data.products_without_images', 1)
            ->assertJsonPath('data.drafts_not_ready', 1)
            ->assertJsonMissingPath('data.revenue');
    }
}
