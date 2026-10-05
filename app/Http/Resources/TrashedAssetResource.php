<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Tahap 6.9 R9.4-14 (D4) — one row of the asset Trash (`GET /api/assets/trash`).
 *
 * The normal {@see AssetResource} shape plus `deleted_at`, the one thing the
 * Trash needs to identify and order a deleted asset. {@see AssetResource} itself
 * keeps hiding the raw timestamp everywhere else. Who deleted an asset is not a
 * column: it lives in the asset's mutation history (shown on the asset detail
 * page), so it is not repeated here.
 */
class TrashedAssetResource extends AssetResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'deleted_at' => $this->resource->deleted_at?->toIso8601String(),
        ];
    }
}
