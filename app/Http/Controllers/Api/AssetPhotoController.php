<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreAssetPhotoRequest;
use App\Http\Resources\AssetPhotoResource;
use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Services\Asset\AssetPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Foto Aset — photos are managed from the asset detail page only.
 *
 *   GET    /api/assets/{asset}/photos           (can:viewer)    metadata list
 *   GET    /api/assets/{asset}/photos/{photo}   (can:viewer)    the JPEG itself
 *   POST   /api/assets/{asset}/photos           (can:assets.edit, throttle:asset-photo-uploads)
 *   DELETE /api/assets/{asset}/photos/{photo}   (can:assets.edit)
 *
 * READ uses exactly the detail-page rule, AssetPolicy::viewDetail (trashed
 * asset → `assets.restore` + location; active → location), answered with 404
 * like the detail page. The two GET routes resolve a trashed asset
 * (`withTrashed()`) so that rule — not the binding — decides.
 *
 * WRITE uses AssetPolicy::update (location scope), answered with 403 like
 * `PUT /api/assets/{asset}` (decision K6-A). The write routes do NOT resolve
 * trashed assets, so uploading to / deleting from a soft-deleted asset is a
 * plain 404 (decision K5).
 *
 * `{photo}` is always scope-bound to `{asset}` (`scopeBindings()`): a photo id
 * of another asset is a 404 — the private path is never taken from the request.
 *
 * The image response is authorized on EVERY request (session auth + policy)
 * BEFORE any conditional-request handling, so a 304 is only ever sent to a
 * caller who may still see the photo; `Cache-Control: private, no-cache`
 * makes the browser revalidate each time, so a user who lost access gets the
 * 404 instead of a cached copy being reused.
 */
class AssetPhotoController extends ApiController
{
    public function index(Request $request, Asset $asset): JsonResponse
    {
        $this->ensureCanViewDetail($request, $asset);

        return AssetPhotoResource::collection($asset->photos()->get())
            ->additional(['meta' => [
                'max_photos' => AssetPhotoService::maxPerAsset(),
                'max_upload_kb' => (int) config('inventory.photos.max_upload_kb'),
                'output_max_side' => (int) config('inventory.photos.output_max_side'),
            ]])
            ->response();
    }

    public function show(Request $request, Asset $asset, AssetPhoto $photo, AssetPhotoService $service): Response
    {
        $this->ensureCanViewDetail($request, $asset);

        $response = new Response('', Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto-aset-'.$asset->id.'-'.$photo->slot.'.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-cache',
        ]);
        $response->setEtag($photo->etag());

        if ($response->isNotModified($request)) {
            return $response; // 304 — only reachable after the policy check above
        }

        $disk = $service->disk();
        $contents = $disk->exists($photo->path) ? $disk->get($photo->path) : null;
        if (! is_string($contents) || $contents === '') {
            abort(404, 'File foto tidak ditemukan.');
        }

        return $response->setContent($contents)->header('Content-Length', (string) strlen($contents));
    }

    public function store(StoreAssetPhotoRequest $request, Asset $asset, AssetPhotoService $service): JsonResponse
    {
        if ($request->user()->cannot('update', $asset)) {
            abort(403);
        }

        $photo = $service->store($asset, $request->file('photo'), $request->user());

        return (new AssetPhotoResource($photo))
            ->additional(['message' => "Foto slot {$photo->slot} berhasil ditambahkan."])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Asset $asset, AssetPhoto $photo, AssetPhotoService $service): Response
    {
        if ($request->user()->cannot('update', $asset)) {
            abort(403);
        }

        $service->delete($asset, $photo, $request->user());

        return response()->noContent();
    }

    private function ensureCanViewDetail(Request $request, Asset $asset): void
    {
        $user = $request->user();
        if ($user === null || $user->cannot('viewDetail', $asset)) {
            abort(404);
        }
    }
}
