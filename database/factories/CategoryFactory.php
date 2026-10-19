<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => CatalogStatus::Published,
            'display_order' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => CatalogStatus::Draft]);
    }
}
