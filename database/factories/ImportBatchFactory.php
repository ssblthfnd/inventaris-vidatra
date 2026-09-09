<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_filename' => 'Inventaris '.fake()->word().'.xlsx',
            'source_sheet' => fake()->numerify('## ').strtoupper(fake()->word()),
            'category_code' => null,
            'status' => 'uploaded',
            'total_rows' => 0,
            'valid_rows' => 0,
            'warning_rows' => 0,
            'error_rows' => 0,
            'imported_rows' => 0,
            'uploaded_by' => null,
            'notes' => null,
            'imported_at' => null,
        ];
    }

    public function validated(int $total = 10, int $valid = 8, int $warning = 2, int $error = 0): static
    {
        return $this->state(fn () => [
            'status' => 'validated',
            'total_rows' => $total,
            'valid_rows' => $valid,
            'warning_rows' => $warning,
            'error_rows' => $error,
        ]);
    }
}
