<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AssetIndexRequest;
use App\Http\Requests\Api\StoreAssetRequest;
use App\Http\Requests\Api\UpdateAssetRequest;
use App\Http\Requests\Api\WriteOffAssetRequest;
use App\Http\Resources\AssetCollection;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Services\Asset\AssetWriteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inventory asset API.
 *
 * Read (Tahap 5.3, `can:viewer`):
 *  - Soft-deleted assets are NEVER returned (SoftDeletes default scope) — no withTrashed().
 *  - `is_written_off` is a filterable STATUS, not a deletion — written-off assets appear
 *    normally and can be filtered with `is_written_off=1|0`.
 *  - `subcategory` is resolved from the composite `(category_code, subcategory_code)` and
 *    bulk-loaded to avoid N+1 (Asset::loadSubcategoriesFor).
 *
 * Write (Tahap 5.4, `can:operator`):
 *  - All business logic lives in {@see AssetWriteService} (transactions, sequence
 *    generator, concurrency retry, mutation logging). The controller only maps
 *    HTTP ↔ service and picks the status code.
 */
class AssetController extends ApiController
{
    private const SEARCHABLE = ['asset_code', 'sequence_no', 'brand_model', 'serial_no', 'material', 'notes'];

    public function index(AssetIndexRequest $request): AssetCollection
    {
        $filters = $request->validated();

        $query = Asset::query()
            ->with(['location', 'category', 'room'])
            ->when(isset($filters['location_code']), fn (Builder $q) => $q->where('location_code', $filters['location_code']))
            ->when(isset($filters['category_code']), fn (Builder $q) => $q->where('category_code', $filters['category_code']))
            ->when(isset($filters['subcategory_code']), fn (Builder $q) => $q->where('subcategory_code', $filters['subcategory_code']))
            ->when(isset($filters['room_id']), fn (Builder $q) => $q->where('room_id', $filters['room_id']))
            ->when(isset($filters['condition']), fn (Builder $q) => $q->where('condition', $filters['condition']))
            ->when(isset($filters['is_written_off']), fn (Builder $q) => $q->where('is_written_off', $request->boolean('is_written_off')))
            ->when(isset($filters['asset_year']), fn (Builder $q) => $q->where('asset_year', $filters['asset_year']))
            ->when(isset($filters['q']), fn (Builder $q) => $this->applySearch($q, $filters['q']));

        $query->orderBy($request->sortColumn(), $request->sortDirection())->orderBy('id');

        $assets = $query->paginate($request->perPage())->withQueryString();

        Asset::loadSubcategoriesFor($assets->getCollection());

        return new AssetCollection($assets);
    }

    public function show(Asset $asset): AssetResource
    {
        $asset->load(['location', 'category', 'room']);

        return new AssetResource($asset);
    }

    /* ------------------------------------------------------------------ write (Tahap 5.4) */

    public function store(StoreAssetRequest $request, AssetWriteService $service): JsonResponse
    {
        $asset = $service->create($request->validated(), $request->user());

        return $this->assetResponse($asset)->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAssetRequest $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        $asset = $service->update($asset, $request->validated(), $request->user());

        return $this->assetResponse($asset);
    }

    public function destroy(Asset $asset, AssetWriteService $service): Response
    {
        $service->softDelete($asset);

        return response()->noContent(); // 204
    }

    public function restore(Asset $asset, AssetWriteService $service): JsonResponse
    {
        $asset = $service->restore($asset);

        return $this->assetResponse($asset);
    }

    public function writeOff(WriteOffAssetRequest $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        $asset = $service->writeOff($asset, $request->validated(), $request->user());

        return $this->assetResponse($asset);
    }

    public function unwriteOff(Request $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        $asset = $service->unwriteOff($asset, $request->user());

        return $this->assetResponse($asset);
    }

    /* ------------------------------------------------------------------ helpers */

    private function assetResponse(Asset $asset): JsonResponse
    {
        $asset->load(['location', 'category', 'room']);

        return (new AssetResource($asset))->response();
    }

    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.$this->escapeLike($term).'%';

        $query->where(function (Builder $q) use ($like): void {
            foreach (self::SEARCHABLE as $column) {
                $q->orWhere($column, 'like', $like);
            }
            $q->orWhereHas('room', fn (Builder $r) => $r->where('name', 'like', $like));
        });
    }
}
