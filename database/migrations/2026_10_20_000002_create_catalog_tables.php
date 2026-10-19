<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'display_order']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('short_description', 255)->nullable();
            $table->text('description')->nullable();
            // Processed image descriptors (storage key, size, widths); never
            // client-supplied paths.
            $table->json('cover_image')->nullable();
            $table->string('cover_image_alt', 255)->nullable();
            $table->json('banner_image')->nullable();
            $table->string('banner_image_alt', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('display_order')->default(0);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            $table->timestamp('published_at')->nullable();
            // Links a record to the frontend's legacy catalog entry it replaces.
            $table->string('legacy_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'display_order']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('sku', 64)->unique();
            $table->string('short_description', 255)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('garment_type', 80)->nullable();
            $table->string('material', 160)->nullable();
            $table->text('care_instructions')->nullable();
            // Minor units (kobo for NGN). Null = price on request.
            $table->unsignedBigInteger('price')->nullable();
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('legacy_key', 120)->nullable()->unique();
            // Lower-cased denormalised text for catalog search.
            $table->text('search_text')->nullable();
            $table->timestamps();

            $table->index(['status', 'display_order']);
            $table->index(['status', 'is_featured']);
            $table->index('garment_type');
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->primary(['collection_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            // '' = not applicable (NULLs would bypass the unique index below).
            $table->string('size', 40)->default('');
            $table->string('color', 60)->default('');
            $table->char('color_hex', 7)->nullable();
            // Overrides the product price when set (minor units).
            $table->unsignedBigInteger('price')->nullable();
            $table->unsignedInteger('stock_quantity')->nullable();
            $table->string('availability', 20)->default('available');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'size', 'color']);
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('storage_key', 120)->unique();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('widths');
            $table->string('alt_text', 255)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_primary')->default(false);
            // Enforces at most one primary image per product at the database
            // level (NULL for non-primary rows never collides).
            $table->unsignedBigInteger('primary_product_id')
                ->nullable()
                ->storedAs('CASE WHEN is_primary = 1 THEN product_id ELSE NULL END');
            $table->timestamps();

            $table->unique('primary_product_id');
            $table->index(['product_id', 'display_order']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 160);
            // Names of changed fields only; never values.
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('categories');
    }
};
