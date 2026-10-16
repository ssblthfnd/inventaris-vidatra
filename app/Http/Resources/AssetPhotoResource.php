<?php

namespace App\Http\Resources;

use App\Models\AssetPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AssetPhoto $resource
 *
 * Read shape of one asset photo (Foto Aset) — metadata only. `url` is the
 * authenticated API endpoint that streams the JPEG after the same detail
 * policy check; the private storage `path` is NEVER part of this resource.
 */
class AssetPhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $photo = $this->resource;

        return [
            'id' => $photo->id,
            'slot' => $photo->slot,
            'mime' => $photo->mime,
            'size_bytes' => $photo->size_bytes,
            'width' => $photo->width,
            'height' => $photo->height,
            'url' => route('api.assets.photos.show', ['asset' => $photo->asset_id, 'photo' => $photo->id], absolute: false),
            'created_at' => $photo->created_at?->toIso8601String(),
        ];
    }
}
