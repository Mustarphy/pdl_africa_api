<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\ProductCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductCollection> */
class ProductCollectionFactory extends Factory
{
    protected $model = ProductCollection::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'short_description' => $this->faker->sentence(),
            'status' => CatalogStatus::Draft,
            'display_order' => 0,
        ];
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
