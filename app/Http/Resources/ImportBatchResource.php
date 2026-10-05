<?php

namespace App\Http\Resources;

use App\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ImportBatch $resource
 *
 * Read shape for one import batch (Tahap 6.1) — every count is the SAME column
 * `App\Import\ImportManager` / `App\Import\Promotion\AssetPromoter` already
 * maintain on `import_batches`; nothing here is recomputed independently.
 */
class ImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $batch = $this->resource;

        return [
            'id' => $batch->id,
            'source_filename' => $batch->source_filename,
            'source_sheet' => $batch->source_sheet,
            'category_code' => $batch->category_code,
            'category_name' => $batch->relationLoaded('category') ? $batch->category?->name : null,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'warning_rows' => $batch->warning_rows,
            'error_rows' => $batch->error_rows,
            'imported_rows' => $batch->imported_rows,
            'uploaded_by' => $batch->relationLoaded('uploadedBy') && $batch->uploadedBy !== null
                ? ['id' => $batch->uploadedBy->id, 'name' => $batch->uploadedBy->name]
                : null,
            'notes' => $batch->notes,
            'imported_at' => $batch->imported_at?->toIso8601String(),
            // Tahap 6.9 R9.4-11 — actor of the first promotion that imported something
            // (pairs with imported_at; NULL for CLI or batches promoted before R9.4-11).
            'imported_by' => $batch->relationLoaded('importedBy') && $batch->importedBy !== null
                ? ['id' => $batch->importedBy->id, 'name' => $batch->importedBy->name]
                : null,
            'created_at' => $batch->created_at?->toIso8601String(),
        ];
    }
}
