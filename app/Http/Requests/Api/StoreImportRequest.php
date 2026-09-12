<?php

namespace App\Http\Requests\Api;

use App\Import\Excel\SheetScanner;
use App\Import\ImportManager;
use App\Import\Validation\RowValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * `POST /api/imports` (Tahap 6.1) — upload one `.xlsx` workbook to be staged by the
 * EXISTING {@see ImportManager}. This request only validates the upload
 * itself (file present, extension, size); it does not know anything about the
 * importer's own row-level rules — those stay exactly where they already live
 * ({@see RowValidator}).
 *
 * Matches {@see SheetScanner::assertReadableXlsx()}'s own
 * constraint: only `.xlsx` is ever accepted, same as `inventory:import`.
 */
class StoreImportRequest extends FormRequest
{
    /** 10 MB — comfortably above the real workbooks (largest is ~250 data rows). */
    public const MAX_KILOBYTES = 10240;

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Pilih file Excel (.xlsx) terlebih dahulu.',
            'file.file' => 'Berkas yang diunggah tidak valid.',
            'file.mimes' => 'Hanya file Excel (.xlsx) yang diterima.',
            'file.max' => 'Ukuran file melebihi batas maksimum ('.(self::MAX_KILOBYTES / 1024).' MB).',
        ];
    }

    public function uploadedFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
