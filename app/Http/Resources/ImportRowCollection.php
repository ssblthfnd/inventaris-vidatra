<?php

namespace App\Http\Resources;

/**
 * Paginated `GET /api/imports/{batch}/rows` response:
 *   { "data": [ImportRowResource, ...], "meta": { current_page, last_page, per_page, total } }
 */
class ImportRowCollection extends PaginatedResourceCollection
{
    public $collects = ImportRowResource::class;
}
