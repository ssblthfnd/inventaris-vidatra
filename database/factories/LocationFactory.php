<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // CHAR(2), 10..98 avoids the seeded 01..04 range in tests that also seed.
            'code' => (string) fake()->unique()->numberBetween(10, 98),
            'name' => 'Lokasi '.fake()->unique()->company(),
            'alias' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
