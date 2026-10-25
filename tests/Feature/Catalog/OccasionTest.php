<?php

namespace Tests\Feature\Catalog;

use App\Models\Occasion;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class OccasionTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_public_occasions_list_only_published_ones_with_published_product_counts(): void
    {
        $weddings = Occasion::factory()->published()->create(['name' => 'Weddings', 'slug' => 'weddings']);
        Occasion::factory()->create(['slug' => 'draft-occasion']);
        Occasion::factory()->archived()->create(['slug' => 'old-occasion']);

        $published = $this->publishedProduct();
        $draft = Product::factory()->create();
        $weddings->products()->attach([$published->id, $draft->id]);

        $this->getJson('/api/v1/occasions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'weddings')
            ->assertJsonPath('data.0.product_count', 1);

        $this->getJson('/api/v1/occasions/weddings')->assertOk()->assertJsonPath('data.name', 'Weddings');
        $this->getJson('/api/v1/occasions/draft-occasion')->assertNotFound();
        $this->getJson('/api/v1/occasions/old-occasion')->assertNotFound();
    }

    public function test_products_can_be_filtered_by_occasion_without_leaking_drafts(): void
    {
        $weddings = Occasion::factory()->published()->create(['slug' => 'weddings']);
        $hidden = Occasion::factory()->create(['slug' => 'hidden']);
        $match = $this->publishedProduct(['name' => 'Wedding Agbada']);
        $other = $this->publishedProduct(['name' => 'Office Suit']);
        $draft = Product::factory()->create(['name' => 'Draft Agbada']);
        $weddings->products()->attach([$match->id, $draft->id]);
        $hidden->products()->attach($other->id);

        $this->getJson('/api/v1/products?occasion=weddings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $match->slug);

        // An unpublished occasion exposes nothing.
        $this->getJson('/api/v1/products?occasion=hidden')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_manages_occasions_and_assignments(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $product = $this->publishedProduct();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/occasions', [
            'name' => 'Formal Events',
            'description' => 'Polished looks for black-tie evenings.',
            'display_order' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'formal-events')
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->putJson("/api/v1/admin/occasions/{$id}/products", ['product_ids' => [$product->id]])
            ->assertOk()
            ->assertJsonPath('data.product_count', 1)
            ->assertJsonPath('data.products.0.id', $product->id);

        $this->postJson("/api/v1/admin/occasions/{$id}/image", [
            'image' => UploadedFile::fake()->image('formal.jpg', 1200, 1500),
            'alt' => 'Guest in a navy suit',
        ])->assertOk()->assertJsonPath('data.image_alt', 'Guest in a navy suit');

        $this->postJson("/api/v1/admin/occasions/{$id}/status", ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');

        // Published slugs are locked; removing assignments keeps the product.
        $this->putJson("/api/v1/admin/occasions/{$id}", ['slug' => 'renamed'])->assertUnprocessable();
        $this->putJson("/api/v1/admin/occasions/{$id}/products", ['product_ids' => []])
            ->assertOk()->assertJsonPath('data.product_count', 0);
        $this->assertModelExists($product);
        $this->deleteJson("/api/v1/admin/occasions/{$id}")->assertUnprocessable();
    }

    public function test_products_are_assigned_to_occasions_from_the_product_form(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->publishedProduct();
        [$a, $b] = Occasion::factory()->count(2)->create();

        $this->actingAs($admin)->putJson("/api/v1/admin/products/{$product->id}", [
            'name' => $product->name,
            'sku' => $product->sku,
            'occasion_ids' => [$a->id, $b->id],
        ])->assertOk()->assertJsonPath('data.occasion_ids', [$a->id, $b->id]);

        $this->putJson("/api/v1/admin/products/{$product->id}", [
            'name' => $product->name,
            'sku' => $product->sku,
            'occasion_ids' => [$b->id],
        ])->assertOk()->assertJsonPath('data.occasion_ids', [$b->id]);
    }

    public function test_guests_cannot_manage_occasions(): void
    {
        $occasion = Occasion::factory()->create();

        $this->postJson('/api/v1/admin/occasions', ['name' => 'X'])->assertUnauthorized();
        $this->putJson("/api/v1/admin/occasions/{$occasion->id}/products", ['product_ids' => []])->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/admin/occasions/{$occasion->id}/status", ['status' => 'published'])
            ->assertForbidden();
    }
}
