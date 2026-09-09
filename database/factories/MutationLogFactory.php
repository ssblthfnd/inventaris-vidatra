<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\MutationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MutationLog>
 */
class MutationLogFactory extends Factory
{
    protected $model = MutationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'type' => fake()->randomElement([
                'pindah_ruangan', 'perbaikan', 'penghapusan', 'perubahan_kondisi', 'lainnya',
            ]),
            'mutation_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'from_location_code' => null,
            'to_location_code' => null,
            'from_room_id' => null,
            'to_room_id' => null,
            'from_room_label' => null,
            'to_room_label' => null,
            'condition_before' => null,
            'condition_after' => null,
            'notes' => null,
            'performed_by' => null,
        ];
    }
}
