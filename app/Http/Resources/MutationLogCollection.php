<?php

namespace App\Http\Resources;

/**
 * Paginated `GET /api/assets/{asset}/mutations` response:
 *   { "data": [MutationLogResource, ...], "meta": { current_page, last_page, per_page, total } }
 */
class MutationLogCollection extends PaginatedResourceCollection
{
    public $collects = MutationLogResource::class;
}
