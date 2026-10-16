<?php

namespace App\Models;

use App\Services\Asset\AssetPhotoService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata of one asset photo (Foto Aset). The JPEG itself lives on the
 * private disk at `path` (relative, `asset-photos/{asset_id}/{uuid}.jpg`) and
 * is only ever served through the authenticated photo endpoint — `path` is
 * hidden from serialisation and never part of any API resource.
 *
 * Rows are written and removed only by {@see AssetPhotoService},
 * which keeps the row and the file consistent. A photo is never updated in
 * place: replacing one means deleting it and uploading a new one.
 */
#[Fillable([
    'asset_id',
    'slot',
    'path',
    'mime',
    'size_bytes',
    'width',
    'height',
    'uploaded_by',
])]
class AssetPhoto extends Model
{
    /** @var list<string> */
    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Cache validator for the image response. Derived from the immutable row
     * (a photo is never modified, only deleted) and hashed, so it reveals
     * nothing about the storage path.
     */
    public function etag(): string
    {
        return '"'.hash('sha256', implode('|', [$this->id, $this->path, $this->size_bytes, $this->created_at?->getTimestamp()])).'"';
    }
}
