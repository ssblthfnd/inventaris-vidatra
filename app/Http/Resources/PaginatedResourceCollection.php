<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Base class for paginated API list responses (Tahap 5.0 convention).
 *
 * A `LengthAwarePaginator` passed through a subclass of this produces exactly:
 *
 *   {
 *     "data": [ ... ],
 *     "meta": { "current_page", "last_page", "per_page", "total" }
 *   }
 *
 * i.e. the trimmed 4-key `meta` and NO `links` block. Non-paginated collections
 * fall back to Laravel's default `{ "data": [ ... ] }`.
 *
 * Not consumed by any endpoint yet — list endpoints arrive in Tahap 5.1+.
 */
abstract class PaginatedResourceCollection extends ResourceCollection
{
    /**
     * @param  Request  $request
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'last_page' => $paginated['last_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
            ],
        ];
    }
}
