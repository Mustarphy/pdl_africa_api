<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\SearchTextBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'sku' => strtoupper(Str::random(8)),
            'short_description' => $this->faker->sentence(),
            'category_id' => Category::factory(),
            'price' => 5_000_000,
            'status' => CatalogStatus::Draft,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            app(SearchTextBuilder::class)->refresh($product);
        });
    }

    public function published(): static
    {
        return $this->state([
            'status' => CatalogStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(['status' => CatalogStatus::Archived]);
    }
}
