<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ImportRowIndexRequest;
use App\Http\Requests\Api\StoreImportRequest;
use App\Http\Resources\ImportBatchCollection;
use App\Http\Resources\ImportBatchResource;
use App\Http\Resources\ImportRowCollection;
use App\Import\ImportManager;
use App\Import\Promotion\AssetPromoter;
use App\Import\Reporting\ImportReporter;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Import Excel UI (Tahap 6.1), `can:operator` — a thin HTTP layer over the EXISTING
 * staging pipeline. Every actual import decision (parsing, room matching, validation,
 * duplicate detection, promotion) is made by {@see ImportManager} / its collaborators,
 * exactly as it is for `php artisan inventory:import` / `inventory:promote`. This
 * controller adds no import logic of its own — only upload handling, pagination,
 * and read shaping.
 */
class ImportController extends ApiController
{
    /** @var array<string, string> */
    private const STATUS_MESSAGES = [
        'validated' => 'File berhasil diunggah dan divalidasi.',
        'failed' => 'File diunggah, namun seluruh baris berstatus Error dan tidak dapat dipromosikan.',
    ];

    /**
     * Import history — every batch, newest first (Tahap 6.1's "Import History").
     */
    public function index(Request $request): ImportBatchCollection
    {
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $batches = ImportBatch::query()
            ->with(['category', 'uploadedBy'])
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return new ImportBatchCollection($batches);
    }

    /**
     * Upload + stage one `.xlsx` workbook. NEVER creates a final asset — staging
     * only ({@see ImportManager::stageFile()}). The uploaded file is kept on the
     * private `local` disk for audit on success; removed immediately on failure
     * (nothing worth auditing if staging never produced a batch).
     */
    public function store(StoreImportRequest $request, ImportManager $manager): JsonResponse
    {
        $file = $request->uploadedFile();
        $originalName = $file->getClientOriginalName();
        $directory = 'imports/'.(string) Str::uuid();

        $storedRelativePath = $file->storeAs($directory, $originalName, 'local');
        $absolutePath = Storage::disk('local')->path($storedRelativePath);

        try {
            $summary = $manager->stageFile($absolutePath, $request->user()->id);
        } catch (RuntimeException $e) {
            Storage::disk('local')->deleteDirectory($directory);
            Log::warning('Import staging failed', ['file' => $originalName, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'file' => ['File Excel tidak dapat diproses. Pastikan file menggunakan format dan struktur kolom yang benar (lihat template import), lalu coba lagi.'],
            ]);
        }

        $batch = ImportBatch::with(['category', 'uploadedBy'])->findOrFail($summary['batch_id']);

        return (new ImportBatchResource($batch))
            ->additional(['message' => self::STATUS_MESSAGES[$summary['status']] ?? self::STATUS_MESSAGES['validated']])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * One batch's own summary — the counters {@see ImportManager} /
     * {@see AssetPromoter} already maintain, nothing recomputed.
     */
    public function show(ImportBatch $batch): ImportBatchResource
    {
        $batch->load(['category', 'uploadedBy']);

        return new ImportBatchResource($batch);
    }

    /**
     * Paginated row-level preview for one batch, optionally filtered by status.
     * `status=duplicate` is UI-only sugar over the existing message codes — see
     * {@see ImportRowIndexRequest}'s docblock; it is never a 5th `validation_status`.
     */
    public function rows(ImportRowIndexRequest $request, ImportBatch $batch): ImportRowCollection
    {
        $query = $batch->importRows()->with('matchedRoom')->orderBy('row_number');

        $status = $request->status();
        if ($status === 'duplicate') {
            $query->where(function ($q): void {
                $q->whereRaw("JSON_SEARCH(validation_messages, 'one', 'duplicate_in_batch') IS NOT NULL")
                    ->orWhereRaw("JSON_SEARCH(validation_messages, 'one', 'duplicate_existing_asset') IS NOT NULL");
            });
        } elseif ($status !== null) {
            $query->where('validation_status', $status);
        }

        $rows = $query->paginate($request->perPage())->withQueryString();

        return new ImportRowCollection($rows);
    }

    /**
     * Promote every valid+warning, not-yet-promoted row into `assets`
     * ({@see ImportManager::promoteBatch()} -> {@see AssetPromoter}).
     * Idempotent: re-promoting an already-imported batch just reports
     * `skipped_already` for rows it already created.
     */
    public function promote(ImportBatch $batch, ImportManager $manager): JsonResponse
    {
        try {
            $result = $manager->promoteBatch($batch->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['batch' => [$e->getMessage()]]);
        }

        $batch->refresh()->load(['category', 'uploadedBy']);

        return response()->json([
            'data' => new ImportBatchResource($batch),
            'promotion' => $result,
        ]);
    }

    /**
     * Read-only data-quality / consistency report for one batch
     * ({@see ImportReporter} — the SAME class `php artisan inventory:report` uses).
     */
    public function report(ImportBatch $batch): JsonResponse
    {
        $reporter = new ImportReporter([$batch->id]);

        return response()->json([
            'data' => [
                'summary' => $reporter->promotionSummary(),
                'consistency' => $reporter->consistencyCheck(),
                'room_mapping' => $reporter->roomMapping(),
                'data_quality' => $reporter->dataQuality(),
                'duplicates' => $reporter->duplicates(),
            ],
        ]);
    }
}
