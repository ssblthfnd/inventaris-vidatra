<?php

namespace App\Http\Resources;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envelope for `GET /api/dashboard` (Tahap 5.6).
 *
 * `$this->resource` is the plain aggregation array from
 * {@see DashboardService::build()}. This class only shapes
 * the top level and hands `recent_mutations` to the shared MutationLogResource so
 * the mutation shape stays identical to the Mutation History API (Tahap 5.5).
 */
class DashboardResource extends JsonResource
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
            'recent_mutations' => MutationLogResource::collection($this->resource['recent_mutations']),
        ];
    }
}
