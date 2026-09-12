<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Services\Asset\AssetExportService;
use App\Services\Excel\AssetSheetColumns;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * `GET /api/assets/export` (Tahap 6.2), `can:operator` — exports the SAME filtered
 * result set `GET /api/assets` would show, as a workbook structurally matching the
 * real source files (`data/excel/Inventaris {Meubelair,Elektronik,Alat
 * Kebersihan}.xlsx`).
 *
 * Deliberately reuses {@see AssetIndexRequest} (the exact FormRequest `AssetController::index()`
 * uses) rather than a second, export-specific filter vocabulary — every query
 * parameter this endpoint accepts already means exactly what it means on
 * `GET /api/assets`. `page` / `per_page` / `sort` are accepted (harmless — an
 * export has no pages) except `sort`/`direction`, which this endpoint DOES honour
 * for row order, matching whatever ordering the user was looking at.
 *
 * Read-only: only ever SELECTs from `assets` (via {@see FiltersAssets}) and never
 * writes to the database, never touches `data/excel/*.xlsx` (those are historical
 * source files, never overwritten), and never persists the generated workbook —
 * it is built in a temp file, read fully into memory, and the temp file removed
 * immediately (same pattern as `ImportTemplateController`).
 *
 * One workbook, one sheet per category actually present in the result (not one
 * file per category, and not one undifferentiated flat sheet for every category):
 * a single-category filter (or an export limited to one category by nature)
 * naturally yields exactly one sheet; "export all" yields one sheet per category
 * — each sheet keeps that category's own real column layout
 * ({@see AssetSheetColumns}), so structurally different
 * source formats are never forced into one artificial table.
 */
class AssetExportController extends ApiController
{
    use FiltersAssets;

    public function export(AssetIndexRequest $request, AssetExportService $service): Response
    {
        $assets = $this->assetsMatchingFilters($request)
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id')
            ->get();

        $spreadsheet = $service->build($assets);

        $tempPath = tempnam(sys_get_temp_dir(), 'asset-export-');
        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        $contents = file_get_contents($tempPath);
        unlink($tempPath);

        $filename = $service->filenameFor($assets);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}
