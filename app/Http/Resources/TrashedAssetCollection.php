<?php

namespace App\Http\Resources;

/**
 * Paginated `GET /api/assets/trash` response (Tahap 6.9 R9.4-14 D4):
 *   { "data": [TrashedAssetResource, ...], "meta": { current_page, last_page, per_page, total } }
 */
class TrashedAssetCollection extends PaginatedResourceCollection
{
    public $collects = TrashedAssetResource::class;
}
