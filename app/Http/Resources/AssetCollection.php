<?php

namespace App\Http\Resources;

/**
 * Paginated `GET /api/assets` response:
 *   { "data": [AssetResource, ...], "meta": { current_page, last_page, per_page, total } }
 */
class AssetCollection extends PaginatedResourceCollection
{
    public $collects = AssetResource::class;
}
