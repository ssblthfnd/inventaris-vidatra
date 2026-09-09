<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subcategory>
 */
class SubcategoryFactory extends Factory
{
    protected $model = Subcategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_code' => Category::factory(),
            // CHAR(3); unique across the whole table here (simplest safe default for tests).
            'code' => str_pad((string) fake()->unique()->numberBetween(1, 998), 3, '0', STR_PAD_LEFT),
            'name' => strtoupper(fake()->unique()->words(2, true)),
            'guide_name' => null,
            'is_active' => true,
        ];
    }

    /** Attach to an existing category. */
    public function forCategory(Category $category): static
    {
        return $this->state(fn () => ['category_code' => $category->code]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
