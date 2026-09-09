<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRow>
 */
class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 100000),
            'raw_payload' => [
                'row_number' => 15,
                'sheet' => '02 MEUBELAIR',
                'cells' => ['A' => '1', 'B' => '01', 'C' => '02', 'D' => '001', 'E' => '001', 'F' => '2020'],
                'parsed' => ['location_code' => '01', 'sequence_no' => '001'],
            ],
            'location_code' => '01',
            'category_code' => '02',
            'subcategory_code' => '001',
            'sequence_no' => str_pad((string) fake()->numberBetween(1, 998), 3, '0', STR_PAD_LEFT),
            'asset_year' => fake()->numberBetween(2015, 2026),
            'room_raw_value' => 'KEUANGAN',
            'matched_room_id' => null,
            'room_match_method' => 'none',
            'condition_raw' => 'B=[v]|KB=[-]|RB=[-]',
            'condition_parsed' => 'baik',
            'validation_status' => 'pending',
            'validation_messages' => [],
            'duplicate_of_asset_id' => null,
            'promoted_asset_id' => null,
            'promoted_at' => null,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['validation_status' => $status]);
    }
}
