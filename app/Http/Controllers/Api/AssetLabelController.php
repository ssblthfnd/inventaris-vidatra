<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\BatchLabelAssetRequest;
use App\Http\Requests\Api\ShowAssetLabelRequest;
use App\Models\Asset;
use App\Services\Label\AssetLabelPdfService;
use Illuminate\Http\Response;

/**
 * Printable asset-label PDF generation (Tahap 6.0; Individual mode added R8),
 * `can:operator` (see routes/api.php) — printing a physical label is treated as an
 * operational action, not a plain read, even though generation itself never writes
 * to the asset domain.
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
 *
 * R8 — `batch()` additionally accepts `mode` (default `'a4'`, preserving the exact
 * pre-R8 grid-sheet behaviour byte-for-byte). `mode=individual` returns one PDF:
 * the single asset's own PDF directly when exactly one was requested, or ONE
 * multi-page PDF (one page per asset, every page still exactly the chosen label
 * size — never an A4 sheet) when more than one was requested. (A ZIP-of-N-PDFs
 * design was tried first and replaced outright after review — a single
 * multi-page PDF is more practical to print in bulk.) `show()` (the single-asset
 * GET route) is untouched — it already renders at exactly the chosen label's
 * physical size, i.e. it was already "Individual mode" in substance; R8 did not
 * need to change it, only reuse the same {@see AssetLabelPdfService::renderSingle()}
 * it already calls.
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
        $mode = $request->mode();

        $assetsById = Asset::query()->whereIn('id', $ids)->get(['id', 'asset_code'])->keyBy('id');

        // Rebuild in the exact order the client submitted (BatchLabelAssetRequest
        // already guarantees every id above resolves to a non-trashed asset).
        $ordered = collect($ids)->map(fn (int $id) => $assetsById->get($id));

        if ($mode === BatchLabelAssetRequest::MODE_INDIVIDUAL) {
            // Exactly one asset: return its own PDF directly. More than one:
            // every asset was already validated to exist (see
            // BatchLabelAssetRequest's own docblock) before this line ever
            // runs, so there is no partial-PDF failure mode to guard against.
            if ($ordered->count() === 1) {
                $asset = $ordered->first();
                $pdf = $service->renderSingle($asset, $size);

                return $pdf->download($service->individualFilename($asset));
            }

            $pdf = $service->renderIndividualMulti($ordered, $size);

            return $pdf->download('labels.pdf');
        }

        $pdf = $service->renderBatch($ordered, $size);

        return $pdf->download("label-aset-batch-{$size}.pdf");
    }
}
