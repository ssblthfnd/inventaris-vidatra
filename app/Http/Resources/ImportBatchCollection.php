<?php

namespace App\Http\Resources;

/**
 * Paginated `GET /api/imports` response:
 *   { "data": [ImportBatchResource, ...], "meta": { current_page, last_page, per_page, total } }
 */
class ImportBatchCollection extends PaginatedResourceCollection
{
    public $collects = ImportBatchResource::class;
}
