<?php

namespace Database\Factories;

use App\Enums\AssetCondition;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 *
 * NEVER sets `asset_code` — the database generates it (STORED column). The factory
 * only fills the five source components and descriptive fields, and does NOT depend on
 * the asset-number generator (Tahap 5.4).
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // sequence_no: a string, unique per test run so grouped business identities
            // never clash. Real historical values ('0001', '005A', ...) are set per-test.
            'sequence_no' => str_pad((string) fake()->unique()->numberBetween(1, 9998), 3, '0', STR_PAD_LEFT),
            'asset_year' => fake()->numberBetween(2015, 2026),
            'quantity' => 1,
            'condition' => AssetCondition::Baik,
            'is_written_off' => false,
            'brand_model' => fake()->optional()->company(),
            'serial_no' => fake()->optional()->bothify('??-#####'),
            'material' => fake()->optional()->randomElement(['KAYU', 'BESI', 'PLASTIK', 'Fiber']),
            'notes' => null,
            // location_code + category_code + subcategory_code: see configure().
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Asset $asset) {
            if ($asset->location_code === null) {
                $asset->location_code = Location::factory()->create()->code;
            }

            if ($asset->category_code === null && $asset->subcategory_code === null) {
                $subcategory = Subcategory::factory()->create();
                $asset->category_code = $subcategory->category_code;
                $asset->subcategory_code = $subcategory->code;
            }
        });
    }

    /** Fix the five identity components explicitly. */
    public function identity(string $location, string $category, string $subcategory, string $sequence, int $year): static
    {
        return $this->state(fn () => [
            'location_code' => $location,
            'category_code' => $category,
            'subcategory_code' => $subcategory,
            'sequence_no' => $sequence,
            'asset_year' => $year,
        ]);
    }

    /** Use an existing subcategory (sets both category_code and subcategory_code). */
    public function forSubcategory(Subcategory $subcategory): static
    {
        return $this->state(fn () => [
            'category_code' => $subcategory->category_code,
            'subcategory_code' => $subcategory->code,
        ]);
    }

    /** Place the asset in a room (keeps location_code consistent with the room). */
    public function inRoom(Room $room): static
    {
        return $this->state(fn () => [
            'room_id' => $room->id,
            'location_code' => $room->location_code,
        ]);
    }

    public function writtenOff(?string $on = null): static
    {
        return $this->state(fn () => [
            'is_written_off' => true,
            'written_off_on' => $on ?? fake()->date(),
        ]);
    }

    public function unknownCondition(): static
    {
        return $this->state(fn () => ['condition' => null]);
    }
}
