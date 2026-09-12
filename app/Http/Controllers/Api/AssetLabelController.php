<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\BatchLabelAssetRequest;
use App\Http\Requests\Api\ShowAssetLabelRequest;
use App\Models\Asset;
use App\Services\Label\AssetLabelPdfService;
use Illuminate\Http\Response;

/**
 * Printable asset-label PDF generation (Tahap 6.0), `can:operator` (see routes/api.php)
 * — printing a physical label is treated as an operational action, not a plain read,
 * even though generation itself never writes to the asset domain.
 *
 * Neither route uses `->withTrashed()`: a soft-deleted asset is not something the app
 * prints a fresh label for. The single-asset route 404s automatically via the default
 * `SoftDeletes` route-model-binding scope; the batch route rejects a trashed id via
 * {@see BatchLabelAssetRequest} (422, all-or-nothing — no silent partial PDF).
 *
 * Both routes accept an optional `size` (Tahap 6.0.2, one of
 * {@see AssetLabelPdfService::SIZES}, default `'small'`) and respond with a real
 * download (`Content-Disposition: attachment`) rather than an inline stream — the
 * frontend fetches the PDF as a Blob and triggers the save itself, so there is
 * never a blank intermediate browser tab.
 */
class AssetLabelController extends ApiController
{
    public function show(ShowAssetLabelRequest $request, Asset $asset, AssetLabelPdfService $service): Response
    {
        $size = $request->size();
        $pdf = $service->renderSingle($asset, $size);

        return $pdf->download("label-asset-{$asset->id}-{$size}.pdf");
    }

    public function batch(BatchLabelAssetRequest $request, AssetLabelPdfService $service): Response
    {
        $ids = $request->assetIds();
        $size = $request->size();

        $assetsById = Asset::query()->whereIn('id', $ids)->get(['id', 'asset_code'])->keyBy('id');

        // Rebuild in the exact order the client submitted (BatchLabelAssetRequest
        // already guarantees every id above resolves to a non-trashed asset).
        $ordered = collect($ids)->map(fn (int $id) => $assetsById->get($id));

        $pdf = $service->renderBatch($ordered, $size);

        return $pdf->download("label-aset-batch-{$size}.pdf");
    }
}
