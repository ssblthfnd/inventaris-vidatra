<?php

namespace App\Http\Resources;

use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/dashboard/rooms` — a page of plain aggregate rows built by
 * {@see DashboardService::roomPage()}
 * (`id`, `name`, `location_code`, `location_name`, `asset_count`; the
 * "Tanpa Ruangan" bucket has `id`/location fields NULL), with the standard
 * `meta` pagination block.
 */
class DashboardRoomCollection extends PaginatedResourceCollection
{
    public $collects = JsonResource::class;
}
