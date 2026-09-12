<?php

namespace App\Http\Resources;

/**
 * Paginated `GET /api/users` response (Tahap 6.4):
 *   { "data": [UserManagementResource, ...], "meta": { current_page, last_page, per_page, total } }
 *
 * Same trimmed pagination envelope every other list endpoint uses — see
 * {@see PaginatedResourceCollection}.
 */
class UserManagementCollection extends PaginatedResourceCollection
{
    public $collects = UserManagementResource::class;
}
