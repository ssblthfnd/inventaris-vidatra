<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Http\Requests\Api\BatchDeleteAssetRequest;
use App\Http\Requests\Api\BatchStoreAssetRequest;
use App\Http\Requests\Api\BatchUpdateAssetRequest;
use App\Http\Requests\Api\StoreAssetEntriesRequest;
use App\Http\Requests\Api\StoreAssetRequest;
use App\Http\Requests\Api\UpdateAssetRequest;
use App\Http\Requests\Api\WriteOffAssetRequest;
use App\Http\Resources\AssetCollection;
use App\Http\Resources\AssetResource;
use App\Http\Resources\TrashedAssetCollection;
use App\Models\Asset;
use App\Models\Room;
use App\Services\Asset\AssetWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inventory asset API.
 *
 * Read (Tahap 5.3, `can:viewer`):
 *  - Soft-deleted assets are NEVER returned (SoftDeletes default scope) — no withTrashed().
 *    The one list of deleted assets is the separate Trash, `trash()` (R9.4-14, `can:assets.restore`).
 *  - `is_written_off` is a filterable STATUS, not a deletion — written-off assets appear
 *    normally and can be filtered with `is_written_off=1|0`.
 *  - `subcategory` is resolved from the composite `(category_code, subcategory_code)` and
 *    bulk-loaded to avoid N+1 (Asset::loadSubcategoriesFor).
 *
 * Write (Tahap 5.4, previously `can:operator` for every action; Stage 6.9 R5
 * regates each single-asset write action to its own named `can:assets.*`
 * ability — see routes/api.php — so `unit_admin` can reach them too):
 *  - All business logic lives in {@see AssetWriteService} (transactions, sequence
 *    generator, concurrency retry, mutation logging). The controller only maps
 *    HTTP ↔ service and picks the status code.
 *  - Stage 6.9 R5 — the route gate only answers WHAT (does this role have
 *    this ability at all); WHERE (is this specific asset/location in the
 *    actor's scope) is checked here via `AssetPolicy`/`LocationScope` before
 *    the service ever runs, for every single-asset write action. Batch
 *    actions (`batchUpdate`/`batchDestroy`) authorize inside
 *    {@see AssetWriteService} itself, after the batch's rows are locked but
 *    before any of them is mutated — see that class's docblock.
 */
class AssetController extends ApiController
{
    use FiltersAssets;

    public function index(AssetIndexRequest $request): AssetCollection
    {
        // Multi-value filters: OR *within* one filter, AND *between* filters
        // (docs/api_convention.md §8.1). Every list comes from the request already
        // validated + normalised; an empty list means "filter not applied". The
        // filter chain itself lives in FiltersAssets (Tahap 6.2) so the export
        // endpoint can reuse the exact same query instead of a second one.
        $query = $this->assetsMatchingFilters($request);

        $query->orderBy($request->sortColumn(), $request->sortDirection())->orderBy('id');

        $assets = $query->paginate($request->perPage())->withQueryString();

        Asset::loadSubcategoriesFor($assets->getCollection());

        return new AssetCollection($assets);
    }

    /**
     * Tahap 6.9 R9.4-14 (D4) — the asset Trash: soft-deleted assets only, so an
     * actor who may restore can find them first. Route gate is the existing
     * `can:assets.restore` (no new ability); WHERE is the same LocationScope
     * clause the active list uses (FiltersAssets), so a unit_admin only ever
     * sees its own location's deleted assets — a foreign location requested in
     * `location_code[]` simply matches nothing, never a 403 that would confirm
     * anything. Same filter vocabulary as `index()` ({@see AssetIndexRequest}),
     * applied on top of `onlyTrashed()`. Fixed order: most recently deleted
     * first, id as tie-breaker (`sort`/`direction` are not used here).
     * Restoring goes through the existing `POST assets/{asset}/restore`.
     */
    public function trash(AssetIndexRequest $request): TrashedAssetCollection
    {
        $assets = $this->assetsMatchingFilters($request, Asset::onlyTrashed())
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        Asset::loadSubcategoriesFor($assets->getCollection());

        return new TrashedAssetCollection($assets);
    }

    public function show(Request $request, Asset $asset): AssetResource
    {
        // The route resolves soft-deleted assets (`->withTrashed()`) so an actor
        // who may restore them can open them first; anyone else (e.g. a viewer)
        // must still see a plain 404 (Tahap 5.8.5).
        //
        // Stage 6.9 R9.3 — WHAT is the named `assets.restore` ability (it used to
        // be the legacy `canWriteInventory()`, i.e. literal admin/operator only,
        // which silently 404'd super_admin and unit_admin even though the
        // restore endpoint itself admits both); WHERE is `AssetPolicy::restore`
        // (LocationScope), so a unit_admin never sees another unit's trashed
        // asset — 404 there too, never 403, for the same don't-confirm-existence
        // reason as the active-asset check below.
        if ($asset->trashed()) {
            $user = $request->user();
            if ($user === null || $user->cannot('assets.restore') || $user->cannot('restore', $asset)) {
                abort(404);
            }
        }

        // Stage 6.9 R4 — a unit_admin requesting an asset outside their scope
        // gets the same plain 404 as the trashed-asset case above (AssetPolicy::view,
        // via LocationScope), never a 403 — this endpoint already has a
        // precedent of using 404 to hide something a caller isn't allowed to
        // see, and a 403 would confirm the asset exists in another unit.
        if ($request->user()?->cannot('view', $asset)) {
            abort(404);
        }

        $asset->load(['location', 'category', 'room']);

        return new AssetResource($asset);
    }

    /* ------------------------------------------------------------------ write (Tahap 5.4) */

    public function store(StoreAssetRequest $request, AssetWriteService $service): JsonResponse
    {
        $data = $request->validated();

        // Stage 6.9 R5 — no existing Asset to check yet; authorize the
        // TARGET location directly. Never rewritten to the actor's own
        // location — an out-of-scope request is rejected, not silently
        // corrected (that would hide a client mistake, per the approved
        // design).
        if ($request->user()->cannot('create', [Asset::class, $data['location_code']])) {
            abort(403);
        }

        $asset = $service->create($data, $request->user());

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
        $template = $request->assetTemplate();

        // Stage 6.9 R5 — a batch-create template has exactly ONE location_code
        // shared by every asset in the batch, so one check authorizes the
        // whole request (no per-asset loop needed, unlike batchUpdate/batchDestroy).
        if ($request->user()->cannot('create', [Asset::class, $template['location_code']])) {
            abort(403);
        }

        $created = $service->createBatch($template, $count, $request->user());

        return AssetResource::collection($created)
            ->additional([
                'message' => "{$count} aset berhasil dibuat.",
                'count' => $count,
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Multiple entry (Tahap 6.9 R10) — 1..100 independent assets, each with its own
     * fields and optionally a manually entered number, in one atomic transaction
     * ({@see AssetWriteService::createEntries()}). `data` is in request order.
     *
     * Every distinct target location is authorized BEFORE anything is written,
     * with the same `AssetPolicy::create` check `store()` uses: one row outside the
     * actor's scope rejects the whole request (403) — no row is created and no
     * location is rewritten to the actor's own.
     */
    public function storeEntries(StoreAssetEntriesRequest $request, AssetWriteService $service): JsonResponse
    {
        foreach ($request->locationCodes() as $locationCode) {
            if ($request->user()->cannot('create', [Asset::class, $locationCode])) {
                abort(403);
            }
        }

        $created = $service->createEntries($request->items(), $request->user());
        $count = $created->count();

        return AssetResource::collection($created)
            ->additional([
                'message' => "{$count} aset berhasil ditambahkan.",
                'count' => $count,
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAssetRequest $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        $data = $request->validated();

        // Stage 6.9 R5 — checks BOTH the asset's current location and the
        // (possibly resubmitted-unchanged, possibly different) target
        // location_code in `$data`. For unit_admin these must be the SAME
        // one location, so this single check is also what blocks using this
        // field to transfer an asset to another unit — see AssetPolicy::update()'s
        // own docblock for why no separate branch is needed.
        if ($request->user()->cannot('update', [$asset, $data['location_code']])) {
            abort(403);
        }

        // Stage 6.9 R5 — independent second check when the request actually
        // moves the asset to a different room (UpdateAssetRequest backfills
        // room_id to the current value when omitted, so this only fires on a
        // REAL change). Redundant with the check above in practice (the room
        // is already required elsewhere to belong to `$data['location_code']`),
        // kept explicit per the approved design's "two independent checks"
        // requirement for a room move.
        if (array_key_exists('room_id', $data) && $data['room_id'] !== null && $data['room_id'] !== $asset->room_id) {
            $targetRoom = Room::find($data['room_id']);
            if ($targetRoom === null || $request->user()->cannot('moveRoom', [$asset, $targetRoom])) {
                abort(403);
            }
        }

        $asset = $service->update($asset, $data, $request->user());

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
        if ($request->user()->cannot('delete', $asset)) {
            abort(403);
        }

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
        // Stage 6.9 R5 — `restore` resolves `->withTrashed()` (route
        // definition), so a cross-unit trashed asset IS found here; the
        // Policy check (not "not found") is what stops a unit_admin from
        // restoring it — never a silent global fallback.
        if ($request->user()->cannot('restore', $asset)) {
            abort(403);
        }

        $asset = $service->restore($asset, $request->user());

        return $this->assetResponse($asset);
    }

    public function writeOff(WriteOffAssetRequest $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        if ($request->user()->cannot('writeOff', $asset)) {
            abort(403);
        }

        $asset = $service->writeOff($asset, $request->validated(), $request->user());

        return $this->assetResponse($asset);
    }

    public function unwriteOff(Request $request, Asset $asset, AssetWriteService $service): JsonResponse
    {
        if ($request->user()->cannot('writeOff', $asset)) {
            abort(403);
        }

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
}
