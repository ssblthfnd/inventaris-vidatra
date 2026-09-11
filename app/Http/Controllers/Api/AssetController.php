<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AssetIndexRequest;
use App\Http\Requests\Api\BatchDeleteAssetRequest;
use App\Http\Requests\Api\BatchStoreAssetRequest;
use App\Http\Requests\Api\BatchUpdateAssetRequest;
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
        // Multi-value filters: OR *within* one filter, AND *between* filters
        // (docs/api_convention.md §8.1). Every list comes from the request already
        // validated + normalised; an empty list means "filter not applied".
        $query = Asset::query()
            ->with(['location', 'category', 'room'])
            ->when($request->locationCodes(), fn (Builder $q, array $codes) => $q->whereIn('location_code', $codes))
            ->when($request->categoryCodes(), fn (Builder $q, array $codes) => $q->whereIn('category_code', $codes))
            ->when($request->subcategoryPairs(), fn (Builder $q, array $pairs) => $q->where(function (Builder $inner) use ($pairs): void {
                foreach ($pairs as [$categoryCode, $subcategoryCode]) {
                    $inner->orWhere(fn (Builder $w) => $w
                        ->where('category_code', $categoryCode)
                        ->where('subcategory_code', $subcategoryCode));
                }
            }))
            ->when($request->roomIds(), fn (Builder $q, array $ids) => $q->whereIn('room_id', $ids))
            ->when($request->conditionFilter(), fn (Builder $q, array $condition) => $q->where(function (Builder $inner) use ($condition): void {
                if ($condition['values'] !== []) {
                    $inner->orWhereIn('condition', $condition['values']);
                }
                if ($condition['includeNull']) {
                    $inner->orWhereNull('condition');
                }
            }))
            ->when($request->assetYears(), fn (Builder $q, array $years) => $q->whereIn('asset_year', $years))
            ->when($request->writtenOffValue() !== null, fn (Builder $q) => $q->where('is_written_off', $request->writtenOffValue()))
            ->when($request->validated('q'), fn (Builder $q, string $term) => $this->applySearch($q, $term));

        $query->orderBy($request->sortColumn(), $request->sortDirection())->orderBy('id');

        $assets = $query->paginate($request->perPage())->withQueryString();

        Asset::loadSubcategoriesFor($assets->getCollection());

        return new AssetCollection($assets);
    }

    public function show(Request $request, Asset $asset): AssetResource
    {
        // The route resolves soft-deleted assets (`->withTrashed()`) so operator/admin
        // can view + restore them; a viewer must still see a plain 404 (Tahap 5.8.5).
        if ($asset->trashed() && ! $request->user()?->canWriteInventory()) {
            abort(404);
        }

        $asset->load(['location', 'category', 'room']);

        return new AssetResource($asset);
    }

    /* ------------------------------------------------------------------ write (Tahap 5.4) */

    public function store(StoreAssetRequest $request, AssetWriteService $service): JsonResponse
    {
        $asset = $service->create($request->validated(), $request->user());

        return $this->assetResponse($asset)->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Batch create — `count` identical assets in one atomic transaction (Tahap 5.8.4).
     * Each asset is a separate row with its own server-generated sequence /
     * `asset_code` and `quantity = 1`.
     */
    public function storeBatch(BatchStoreAssetRequest $request, AssetWriteService $service): JsonResponse
    {
        $count = $request->assetCount();
        $created = $service->createBatch($request->assetTemplate(), $count, $request->user());

        return AssetResource::collection($created)
            ->additional([
                'message' => "{$count} aset berhasil dibuat.",
                'count' => $count,
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAssetRequest $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        $asset = $service->update($asset, $request->validated(), $request->user());

        return $this->assetResponse($asset);
    }

    /**
     * Batch edit — the safe descriptive fields only, across many assets in one
     * atomic transaction (Tahap 5.8.6). See {@see AssetWriteService::batchUpdate()}.
     */
    public function batchUpdate(BatchUpdateAssetRequest $request, AssetWriteService $service): JsonResponse
    {
        $result = $service->batchUpdate($request->assetIds(), $request->changes(), $request->user());

        return response()->json([
            'message' => $this->batchUpdateMessage($result),
            ...$result,
        ]);
    }

    public function destroy(Request $request, Asset $asset, AssetWriteService $service): Response
    {
        $service->softDelete($asset, $request->user());

        return response()->noContent(); // 204
    }

    /**
     * Batch soft delete (Tahap 5.8.7). Unlike the single-asset endpoint (204 No
     * Content), this returns 200 with `{message, requested, deleted}` — the same
     * shape choice `batchUpdate` already made — so the frontend can flash an
     * accurate count without a second round trip.
     */
    public function batchDestroy(BatchDeleteAssetRequest $request, AssetWriteService $service): JsonResponse
    {
        $result = $service->batchSoftDelete($request->assetIds(), $request->user());

        return response()->json([
            'message' => "{$result['deleted']} aset dipindahkan ke Trash.",
            ...$result,
        ]);
    }

    public function restore(Request $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        $asset = $service->restore($asset, $request->user());

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

    /**
     * @param  array{requested: int, updated: int, unchanged: int}  $result
     */
    private function batchUpdateMessage(array $result): string
    {
        ['requested' => $requested, 'updated' => $updated, 'unchanged' => $unchanged] = $result;

        if ($updated === 0) {
            return "{$requested} aset diproses. Tidak ada perubahan karena nilainya sudah sama.";
        }

        if ($unchanged === 0) {
            return "{$requested} aset berhasil diperbarui.";
        }

        return "{$requested} aset diproses. {$updated} aset berubah, {$unchanged} aset tidak mengalami perubahan.";
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
