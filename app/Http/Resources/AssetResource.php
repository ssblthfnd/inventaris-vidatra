<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Asset $resource
 *
 * Read shape for the inventory UI. Internal / audit columns are NOT exposed:
 * `import_row_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`.
 * `asset_code` is taken straight from the DB (STORED generated column) — never rebuilt.
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $asset = $this->resource;
        $subcategory = $asset->subcategory; // composite-resolved (bulk-loaded on index)

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,

            'location' => [
                'code' => $asset->location_code,
                'name' => $asset->location?->name,
                'alias' => $asset->location?->alias,
            ],
            'category' => [
                'code' => $asset->category_code,
                'name' => $asset->category?->name,
            ],
            'subcategory' => [
                'code' => $asset->subcategory_code,
                'name' => $subcategory?->name,
            ],

            'sequence_no' => $asset->sequence_no,
            'asset_year' => $asset->asset_year,

            'room' => $asset->room_id === null ? null : [
                'id' => $asset->room_id,
                'name' => $asset->room?->name,
            ],
            'room_raw_value' => $asset->room_raw_value,

            'condition' => $asset->condition?->value,
            'is_written_off' => $asset->is_written_off,
            'written_off_on' => $asset->written_off_on?->toDateString(),
            'written_off_note' => $asset->written_off_note,

            'brand_model' => $asset->brand_model,
            'serial_no' => $asset->serial_no,
            'material' => $asset->material,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'funding_source' => $asset->funding_source,
            'detail_type' => $asset->detail_type,
            'capacity_note' => $asset->capacity_note,
            'quantity' => $asset->quantity,
            'notes' => $asset->notes,
        ];
    }
}
