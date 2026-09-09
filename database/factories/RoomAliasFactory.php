<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomAlias;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RoomAlias>
 */
class RoomAliasFactory extends Factory
{
    protected $model = RoomAlias::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $raw = strtoupper(fake()->unique()->words(2, true));

        return [
            // location_code + room_id filled by configure() so the composite FK
            // (location_code, room_id) -> rooms(location_code, id) always holds.
            'raw_value' => $raw,
            'match_key' => Str::upper(preg_replace('/\s+/', ' ', trim($raw))),
            'source' => 'manual',
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (RoomAlias $alias) {
            if ($alias->room_id === null) {
                $room = Room::factory()->create();
                $alias->room_id = $room->id;
                $alias->location_code = $room->location_code;
            } elseif ($alias->location_code === null) {
                $alias->location_code = Room::whereKey($alias->room_id)->value('location_code');
            }
        });
    }

    /** Point at an existing room (keeps location_code consistent). */
    public function forRoom(Room $room): static
    {
        return $this->state(fn () => [
            'room_id' => $room->id,
            'location_code' => $room->location_code,
        ]);
    }

    public function tahap1Seed(): static
    {
        return $this->state(fn () => ['source' => 'tahap1_seed']);
    }
}
