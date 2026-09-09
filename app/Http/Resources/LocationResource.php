<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Location $resource
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'alias' => $this->resource->alias,
            'is_active' => $this->resource->is_active,
        ];
    }
}
