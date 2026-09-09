<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_code' => Location::factory(),
            'name' => 'Ruangan '.fake()->unique()->streetName(),
            'pic' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }

    /** Place the room in an existing location. */
    public function forLocation(Location $location): static
    {
        return $this->state(fn () => ['location_code' => $location->code]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
