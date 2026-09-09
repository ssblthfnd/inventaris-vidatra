<?php

namespace Tests\Feature\Http;

use App\Http\Resources\PaginatedResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Tahap 5.0 — response convention foundation. A paginated collection through
 * PaginatedResourceCollection must produce exactly { data, meta:{4 keys} } and no links.
 * (No list endpoint consumes this yet — Tahap 5.1+.)
 */
class PaginatedResourceCollectionTest extends TestCase
{
    public function test_pagination_envelope_has_only_the_four_meta_keys_and_no_links(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [['id' => 1], ['id' => 2]],
            total: 94,
            perPage: 20,
            currentPage: 1,
        );

        $collection = new class($paginator) extends PaginatedResourceCollection
        {
            public $collects = JsonResource::class;
        };

        $payload = $collection->response(Request::create('/api/example'))->getData(true);

        $this->assertSame(['data', 'meta'], array_keys($payload));
        $this->assertCount(2, $payload['data']);
        $this->assertSame(
            ['current_page', 'last_page', 'per_page', 'total'],
            array_keys($payload['meta']),
        );
        $this->assertSame(
            ['current_page' => 1, 'last_page' => 5, 'per_page' => 20, 'total' => 94],
            $payload['meta'],
        );
        $this->assertArrayNotHasKey('links', $payload);
    }
}
