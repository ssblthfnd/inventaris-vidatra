<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ImportRowIndexRequest;
use App\Http\Requests\Api\ResolveRoomMappingRequest;
use App\Http\Requests\Api\StoreImportRequest;
use App\Http\Resources\ImportBatchCollection;
use App\Http\Resources\ImportBatchResource;
use App\Http\Resources\ImportRowCollection;
use App\Import\ImportManager;
use App\Import\Promotion\AssetPromoter;
use App\Import\Reporting\ImportReporter;
use App\Import\RoomMapping\RoomMappingResolver;
use App\Models\ImportBatch;
use App\Policies\ImportBatchPolicy;
use App\Support\LocationScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Import Excel UI (Tahap 6.1). Stage 6.9 R6 regates `store`/`show`/`rows`/
 * `promote`/`report` from `can:operator` to `can:assets.import` — so
 * `unit_admin` can reach them too. `index` (the cross-batch history list)
 * moved to `can:assets.import` too in R9.3, but admits only a global-scope
 * actor: unit_admin still has no way to browse every OTHER user's import
 * history, and no "which batches may this actor list" scheme is invented
 * for it here. A thin HTTP layer over
 * the EXISTING staging pipeline either way — every actual import decision
 * (parsing, room matching, validation, duplicate detection, promotion) is
 * made by {@see ImportManager} / its collaborators, exactly as it is for
 * `php artisan inventory:import` / `inventory:promote`. This controller
 * adds no import logic of its own — only upload handling, pagination,
 * read shaping, and the per-batch LOCATION-scope check via
 * {@see ImportBatchPolicy} for the single-batch endpoints (R6 introduced it
 * as an ownership check; R9.4-07 D2 made location scope the one boundary for
 * every batch operation — the uploader is audit metadata only).
 */
class ImportController extends ApiController
{
    /** @var array<string, string> */
    private const STATUS_MESSAGES = [
        'validated' => 'File berhasil diunggah dan divalidasi.',
        'failed' => 'File diunggah, namun seluruh baris berstatus Error dan tidak dapat dipromosikan.',
    ];

    private const SCOPE_REJECTED_MESSAGE = 'File berisi data di luar unit yang menjadi wewenang Anda dan tidak dapat diproses.';

    /**
     * Import history — every batch, newest first (Tahap 6.1's "Import History").
     *
     * Stage 6.9 R9.3 — route gate is `can:assets.import` (WHAT). This list is
     * NOT location-scoped (every batch, every uploader, every location), so
     * WHERE is answered here: only a GLOBAL-scope actor may list it. A
     * unit_admin holds `assets.import` but gets 403, exactly as under the
     * old `can:operator` gate; super_admin now reaches it.
     */
    public function index(Request $request): ImportBatchCollection
    {
        abort_unless(LocationScope::for($request->user())->isGlobal(), 403);

        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $batches = ImportBatch::query()
            ->with(['category', 'uploadedBy', 'importedBy'])
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
            $summary = $manager->stageFile($absolutePath, $request->user()->id, $request->user());
        } catch (RuntimeException $e) {
            Storage::disk('local')->deleteDirectory($directory);
            Log::warning('Import staging failed', ['file' => $originalName, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages([
                'file' => ['File Excel tidak dapat diproses. Pastikan file menggunakan format dan struktur kolom yang benar (lihat template import), lalu coba lagi.'],
            ]);
        } catch (\Throwable $e) {
            // Tahap 6.9 R8.2 (P3-3): an unexpected (non-RuntimeException) failure
            // must not leave the uploaded file behind either — nothing was
            // successfully staged, so there is nothing worth auditing, same
            // reasoning as the RuntimeException branch above. The original
            // exception is rethrown UNCHANGED (no message/response shape change)
            // so this stays purely a cleanup addition, not a behavior change.
            Storage::disk('local')->deleteDirectory($directory);

            throw $e;
        }

        // Stage 6.9 R6 — the batch (and its rows) stay in the database
        // either way (audit/history, per the approved design), but a
        // location-scoped upload containing out-of-scope data gets a clear
        // rejection response rather than the normal 201 — never silently
        // treated the same as an ordinary "all rows errored" file.
        if ($summary['scope_rejected']) {
            abort(403, self::SCOPE_REJECTED_MESSAGE);
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
    public function show(Request $request, ImportBatch $batch, AssetPromoter $promoter): ImportBatchResource
    {
        // Tahap 6.9 R9.4-07 (D2) — a unit_admin may view a batch whose rows are
        // ALL in its own location, whoever uploaded it (App\Policies\ImportBatchPolicy,
        // same rule as mapping/promotion); 404, not 403, matching this app's
        // read-path convention of never confirming a resource exists to a
        // caller who isn't allowed to see it.
        $this->ensureVisible($request, $batch);

        $batch->load(['category', 'uploadedBy', 'importedBy']);

        // Tahap 6.9 R9.4-10 (D3) — the promotion confirmation reads this fresh
        // batch right before asking, so it carries how many assets the next
        // promotion would create without a room (same rule as the promotion).
        return (new ImportBatchResource($batch))
            ->withRoomlessPendingCount($promoter->roomlessPendingCount($batch->id, $request->user()));
    }

    /**
     * Paginated row-level preview for one batch, optionally filtered by status.
     * `status=duplicate` is UI-only sugar over the existing message codes — see
     * {@see ImportRowIndexRequest}'s docblock; it is never a 5th `validation_status`.
     */
    public function rows(ImportRowIndexRequest $request, ImportBatch $batch): ImportRowCollection
    {
        $this->ensureVisible($request, $batch);

        $query = $batch->importRows()
            ->with(['matchedRoom', 'roomMappingResolution.resolvedBy', 'promotedBy'])
            ->orderBy('row_number');

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
    public function promote(Request $request, ImportBatch $batch, ImportManager $manager, AssetPromoter $promoter): JsonResponse
    {
        // Stage 6.9 R6 — promotion authority is LOCATION-based: a different
        // unit_admin who shares the SAME location as whoever staged this
        // batch is legitimately allowed to promote it too (multiple
        // unit_admins per unit is an explicitly supported Stage 6.9
        // scenario since R1). AssetPromoter applies the shared
        // ImportBatchPolicy::batchWithinScope() rule (R9.4-07 D2 — the same
        // one show()/rows()/report() use) to the CURRENT actor (AuthorizationException,
        // uncaught here on purpose — Laravel's default handler turns it
        // into a 403, matching every other scope violation in this app; it
        // is NOT a RuntimeException, so the catch below never intercepts it).
        try {
            $result = $manager->promoteBatch($batch->id, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['batch' => [$e->getMessage()]]);
        }

        $batch->refresh()->load(['category', 'uploadedBy', 'importedBy']);

        return response()->json([
            // R9.4-10 (D3) — what a further promotion would still create without a
            // room after this run (normally 0).
            'data' => (new ImportBatchResource($batch))
                ->withRoomlessPendingCount($promoter->roomlessPendingCount($batch->id, $request->user())),
            'promotion' => $result,
        ]);
    }

    /**
     * Read-only data-quality / consistency report for one batch
     * ({@see ImportReporter} — the SAME class `php artisan inventory:report` uses).
     */
    public function report(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->ensureVisible($request, $batch);

        $reporter = new ImportReporter([$batch->id]);

        // Tahap 6.9 R9.1 (P2, query-redundancy hardening): summary/room_mapping are
        // needed for their own response keys below AND as consistencyCheck()'s
        // inputs — compute each exactly once and pass the results in, instead of
        // letting consistencyCheck() recompute both internally a second time
        // (was 22 queries total for this endpoint, 9 of them pure re-execution;
        // see ImportReporter::consistencyCheck()'s docblock). Values returned are
        // identical either way — this only removes duplicate query execution.
        $summary = $reporter->promotionSummary();
        $roomMapping = $reporter->roomMapping();
        $roomMethodTotals = $reporter->roomMethodTotals($roomMapping);

        return response()->json([
            'data' => [
                'summary' => $summary,
                'consistency' => $reporter->consistencyCheck($summary, $roomMethodTotals),
                'room_mapping' => $roomMapping,
                'data_quality' => $reporter->dataQuality(),
                'duplicates' => $reporter->duplicates(),
            ],
        ]);
    }

    /**
     * Tahap 6.9 R9.2 — distinct (location_code, normalized raw value) unmapped
     * room groups for this batch.
     *
     * Tahap 6.9 R9.4-07 (D2) — gated by the same {@see ImportBatchPolicy} as
     * show()/rows()/report(): a batch outside the actor's scope (foreign,
     * mixed, or with no located row) is a 404, exactly like a batch that does
     * not exist. Previously a unit_admin got 200 with only its own location's
     * groups — an empty list for a foreign batch (which confirmed the batch
     * existed) and its own groups out of a mixed batch.
     */
    public function roomMappings(Request $request, ImportBatch $batch, RoomMappingResolver $resolver): JsonResponse
    {
        $this->ensureVisible($request, $batch);

        return response()->json([
            'data' => $resolver->unmappedGroups($batch, $request->user()),
        ]);
    }

    /**
     * Tahap 6.9 R9.2 — resolves one unmapped room group (`location_code` +
     * `raw_value`) to `room_id`, for this batch's still-unresolved,
     * still-unpromoted rows only. `save_as_alias` additionally persists a
     * `room_aliases` row so future imports resolve it automatically via the
     * existing {@see \App\Import\Matching\RoomMatcher}. See
     * {@see RoomMappingResolver::resolve()} for the full authorization/
     * concurrency contract.
     */
    public function resolveRoomMapping(ResolveRoomMappingRequest $request, ImportBatch $batch, RoomMappingResolver $resolver): JsonResponse
    {
        try {
            $result = $resolver->resolve(
                $batch,
                $request->validated('location_code'),
                $request->validated('raw_value'),
                (int) $request->validated('room_id'),
                $request->saveAsAlias(),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['room_id' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Tahap 6.9 R9.4-07 (D2) — the one visibility check for the read endpoints
     * (show / rows / report / room-mappings), via {@see ImportBatchPolicy}.
     *
     * A batch the actor may not reach must read exactly like one that does not
     * exist. Route-model binding answers a missing id with this same
     * ModelNotFoundException, so status AND body match; a bare abort(404) (used
     * before D2) produced a different message, which revealed that the batch
     * existed.
     */
    private function ensureVisible(Request $request, ImportBatch $batch): void
    {
        if ($request->user()->cannot('view', $batch)) {
            throw (new ModelNotFoundException)->setModel(ImportBatch::class, [$batch->getRouteKey()]);
        }
    }
}
