<?php

namespace App\Http\Resources;

use App\Services\Report\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envelope for `GET /api/reports/inventory` (Tahap 6.3).
 *
 * `$this->resource` is the plain aggregation array from
 * {@see ReportService::build()} — already fully aggregated server-side, so this
 * class only shapes the top level. No raw asset rows are ever included.
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'summary' => $this->resource['summary'],
            'by_location' => $this->resource['by_location'],
            'by_category' => $this->resource['by_category'],
            'by_room' => $this->resource['by_room'],
            'by_condition' => $this->resource['by_condition'],
            'by_year' => $this->resource['by_year'],
        ];
    }
}
