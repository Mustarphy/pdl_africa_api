<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class AdminImageTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
    }

    public function test_uploads_are_reencoded_to_webp_with_generated_names(): void
    {
        $product = Product::factory()->create();

        $response = $this->actingAs($this->admin)->post("/api/v1/admin/products/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('../../evil name.jpg', 1200, 1500)],
            'alt_texts' => ['Front view of the agbada'],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = ProductImage::sole();
        $this->assertTrue($image->is_primary);
        $this->assertSame([480, 960, 1200], $image->widths);
        $this->assertMatchesRegularExpression('#^catalog/products/[0-9a-f-]{36}$#', $image->storage_key);
        foreach ($image->widths as $w) {
            Storage::disk('public')->assertExists("{$image->storage_key}-{$w}.webp");
            $this->assertSame('image/webp', getimagesizefromstring(Storage::disk('public')->get("{$image->storage_key}-{$w}.webp"))['mime']);
        }
        $this->assertStringNotContainsString('evil', json_encode(Storage::disk('public')->allFiles()));
        $response->assertJsonPath('data.images.0.alt_text', 'Front view of the agbada');
        $this->assertStringNotContainsString($image->storage_key, $response->getContent());
    }

    public function test_non_images_disguised_files_and_tiny_images_are_rejected(): void
    {
        $product = Product::factory()->create();
        $upload = fn (UploadedFile $file) => $this->actingAs($this->admin)->post(
            "/api/v1/admin/products/{$product->id}/images",
            ['images' => [$file]],
            ['Accept' => 'application/json'],
        );

        $upload(UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'))->assertUnprocessable();
        $upload(UploadedFile::fake()->createWithContent('fake.jpg', '<?php system($_GET["c"]);'))->assertUnprocessable();
        $upload(UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'))->assertUnprocessable();
        $upload(UploadedFile::fake()->image('small.jpg', 200, 200))->assertUnprocessable();

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_one_primary_image_and_switching_primary(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin)->post("/api/v1/admin/products/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('a.jpg', 800, 1000), UploadedFile::fake()->image('b.png', 800, 1000)],
        ], ['Accept' => 'application/json'])->assertCreated();

        [$first, $second] = ProductImage::orderBy('id')->get();
        $this->assertTrue($first->is_primary);
        $this->assertFalse($second->is_primary);

        $this->actingAs($this->admin)->patchJson("/api/v1/admin/products/{$product->id}/images/{$second->id}", ['is_primary' => true])
            ->assertOk();
        $this->assertSame(1, ProductImage::where('is_primary', true)->count());
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_images_of_another_product_cannot_be_touched(): void
    {
        $mine = Product::factory()->create();
        $other = Product::factory()->create();
        $foreign = $this->attachImage($other);

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/products/{$mine->id}/images/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/products/{$mine->id}/images/{$foreign->id}", ['alt_text' => 'x'])->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_deleting_an_image_removes_files_and_promotes_the_next_primary(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin)->post("/api/v1/admin/products/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('a.jpg', 800, 1000), UploadedFile::fake()->image('b.jpg', 800, 1000)],
        ], ['Accept' => 'application/json']);
        [$first, $second] = ProductImage::orderBy('id')->get();

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/products/{$product->id}/images/{$first->id}")->assertOk();

        Storage::disk('public')->assertMissing("{$first->storage_key}-480.webp");
        Storage::disk('public')->assertExists("{$second->storage_key}-480.webp");
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_last_image_of_a_published_product_cannot_be_deleted(): void
    {
        $product = $this->publishedProduct();
        $image = $product->images()->first();

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['publication']);
        $this->assertModelExists($image);
    }

    public function test_collection_banner_upload_replaces_old_files_and_enables_publishing(): void
    {
        $collection = ProductCollection::factory()->create();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/collections/{$collection->id}/status", ['status' => 'published'])
            ->assertUnprocessable();

        $this->actingAs($this->admin)->post("/api/v1/admin/collections/{$collection->id}/images/banner", [
            'image' => UploadedFile::fake()->image('banner.jpg', 1600, 2000), 'alt' => 'Model in agbada',
        ], ['Accept' => 'application/json'])->assertOk();
        $firstKey = $collection->fresh()->banner_image['key'];

        $this->actingAs($this->admin)->post("/api/v1/admin/collections/{$collection->id}/images/banner", [
            'image' => UploadedFile::fake()->image('banner2.jpg', 1600, 2000),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing("{$firstKey}-480.webp");

        $this->actingAs($this->admin)->postJson("/api/v1/admin/collections/{$collection->id}/status", ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson("/api/v1/collections/{$collection->slug}")
            ->assertOk()->assertJsonPath('data.banner_image.width', 1600);

        $this->actingAs($this->admin)->post("/api/v1/admin/collections/{$collection->id}/images/logo", [
            'image' => UploadedFile::fake()->image('x.jpg', 800, 800),
        ], ['Accept' => 'application/json'])->assertNotFound();
    }
}
