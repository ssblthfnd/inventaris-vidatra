<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ImportTemplateRequest;
use App\Services\Import\ImportTemplateService;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * `GET /api/imports/template?category=02|03|06` (Tahap 6.1), `can:operator`.
 *
 * Strictly READ-ONLY: {@see ImportTemplateService} only ever reads master data to
 * label the sheet for humans and never writes to the database. The workbook is
 * built in a temp file, read fully into memory, and returned as a plain buffered
 * download (same shape as `AssetLabelController`'s PDF responses) rather than a
 * streamed `BinaryFileResponse` — a streamed file response never buffers its body,
 * which is fine for a browser but makes the response opaque to anything (including
 * tests) that inspects it after the fact. The temp file is removed immediately.
 */
class ImportTemplateController extends ApiController
{
    public function show(ImportTemplateRequest $request, ImportTemplateService $service): Response
    {
        $categoryCode = $request->categoryCode();
        $spreadsheet = $service->generate($categoryCode);

        // Tahap 6.9 R8.2 (P3-3): guaranteed cleanup, same pattern as
        // AssetExportController — see that file's comment for the rationale.
        $tempPath = tempnam(sys_get_temp_dir(), 'import-template-');
        try {
            (new Xlsx($spreadsheet))->save($tempPath);
            $spreadsheet->disconnectWorksheets();

            $contents = file_get_contents($tempPath);
        } finally {
            if (is_string($tempPath) && is_file($tempPath)) {
                unlink($tempPath);
            }
        }

        $filename = "template-impor-{$categoryCode}.xlsx";

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}
