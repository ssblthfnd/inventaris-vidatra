<?php

namespace App\Import;

use App\Import\Excel\ScannedRow;
use App\Import\Excel\SheetScanner;
use App\Import\Matching\RoomMatcher;
use App\Import\Parsing\RowParser;
use App\Import\Promotion\AssetPromoter;
use App\Import\Validation\DuplicateChecker;
use App\Import\Validation\MasterData;
use App\Import\Validation\RowValidator;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates the staging pipeline:
 *
 *   Excel file -> SheetScanner -> import_batch (1 file / 1 sheet / 1 category)
 *              -> per data row: RowParser -> RoomMatcher -> RowValidator -> import_row
 *              -> batch counters + lifecycle status
 *
 * Promotion (import_rows -> assets) is delegated to {@see AssetPromoter}. There is NEVER a
 * direct Excel -> assets path.
 *
 * Stage 6.9 R6 — `stageFile()`'s optional `$actor` enforces location scope
 * for `unit_admin`: after every row is parsed/inserted exactly as before, if
 * ANY row resolved to a location outside the actor's scope, the whole batch
 * is forced to `status = 'failed'` — never partially promotable — and each
 * out-of-scope row is marked accordingly (see `rejectOutOfScopeRows()`).
 * `promoteBatch()`'s optional `$actor` is threaded to {@see AssetPromoter},
 * which independently re-checks scope against the CURRENT actor at
 * promotion time — see that class's docblock for why staging-time and
 * promotion-time checks are both necessary. `$actor === null` (the CLI
 * commands' path) means unrestricted, matching a global role.
 */
final class ImportManager
{
    public function __construct(
        private readonly RowParser $parser,
        private readonly RoomMatcher $roomMatcher,
        private readonly AssetPromoter $promoter,
    ) {}

    /**
     * Stage + validate one workbook into a fresh import batch.
     *
     * @return array{batch_id:int, sheet:string, location_code:?string, category_code:?string,
     *               total:int, valid:int, warning:int, error:int, skipped_rows:int, status:string,
     *               scope_rejected:bool}
     */
    public function stageFile(string $path, ?int $uploadedBy = null, ?User $actor = null): array
    {
        $scanner = new SheetScanner($path);

        // buffer scanned rows first so batch metadata (sheet/location/category) is known
        /** @var list<ScannedRow> $dataRows */
        $dataRows = [];
        $skippedRows = 0;
        foreach ($scanner->rows() as $scanned) {
            if ($scanned->type === ScannedRow::TYPE_DATA) {
                $dataRows[] = $scanned;
            } elseif ($scanned->type === ScannedRow::TYPE_SKIPPED) {
                $skippedRows++;
            }
        }

        if ($dataRows === []) {
            throw new RuntimeException("No data rows found in [{$path}] (sheet [{$scanner->sheetName}]).");
        }

        $master = new MasterData;
        $duplicates = new DuplicateChecker;
        $validator = new RowValidator($master, $duplicates);
        $this->roomMatcher->forgetCache();

        $now = now();
        $batchId = DB::table('import_batches')->insertGetId([
            'source_filename' => basename($path),
            'source_sheet' => $scanner->sheetName,
            'category_code' => $master->hasCategory((string) $scanner->categoryCode) ? $scanner->categoryCode : null,
            'status' => 'validating',
            'total_rows' => 0,
            'valid_rows' => 0,
            'warning_rows' => 0,
            'error_rows' => 0,
            'imported_rows' => 0,
            'uploaded_by' => $uploadedBy,
            'notes' => $this->batchNote($scanner, $skippedRows),
            'imported_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $batchCategory = $scanner->categoryCode;

        foreach ($dataRows as $scanned) {
            $parsed = $this->parser->parse($scanned, (string) $batchCategory);
            $room = $this->roomMatcher->match($parsed->locationCode, $parsed->roomRawValue);
            $validation = $validator->validate($parsed, $room, $batchCategory);

            DB::table('import_rows')->insert([
                'import_batch_id' => $batchId,
                'row_number' => $scanned->rowNumber,
                'raw_payload' => json_encode([
                    'row_number' => $scanned->rowNumber,
                    'sheet' => $scanner->sheetName,
                    'source_file' => basename($path),
                    'block_subcategory_code' => $scanned->blockSubcategoryCode,
                    'block_subcategory_name' => $scanned->blockSubcategoryName,
                    'cells' => $scanned->cells,
                    'formulas' => $scanned->formulas,
                    'parsed' => $parsed->toArray(),
                    'room_match' => [
                        'method' => $room->method,
                        'match_key' => $room->matchKey,
                        'room_id' => $room->roomId,
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'location_code' => $parsed->locationCode,
                'category_code' => $parsed->categoryCode,
                'subcategory_code' => $parsed->subcategoryCode,
                'sequence_no' => $parsed->sequenceNo,
                'asset_year' => $parsed->assetYear,
                'room_raw_value' => $parsed->roomRawValue,
                'matched_room_id' => $room->roomId,
                'room_match_method' => $room->method,
                'condition_raw' => $parsed->conditionRaw,
                'condition_parsed' => $parsed->conditionParsed,
                'validation_status' => $validation->status,
                'validation_messages' => json_encode($validation->messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'duplicate_of_asset_id' => $validation->duplicateOfAssetId,
                'promoted_asset_id' => null,
                'promoted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $counts = $this->recomputeCounts($batchId);
        $status = ($counts['total'] > 0 && $counts['error'] === $counts['total']) ? 'failed' : 'validated';

        // Stage 6.9 R6 — a location-scoped actor (unit_admin) uploading ANY
        // row that resolved to a location outside their scope forces the
        // WHOLE batch non-promotable, regardless of how many other rows are
        // otherwise perfectly valid. Global actors (isGlobal() === true, the
        // $actor === null CLI path included) are completely unaffected —
        // this block is a no-op for them, byte-identical to before R6.
        $scopeRejected = false;
        if ($actor !== null) {
            $scope = LocationScope::for($actor);
            if (! $scope->isGlobal()) {
                $scopeRejected = $this->rejectOutOfScopeRows($batchId, $scope);
                if ($scopeRejected) {
                    $counts = $this->recomputeCounts($batchId);
                    $status = 'failed';
                }
            }
        }

        DB::table('import_batches')->where('id', $batchId)->update([
            'status' => $status,
            'total_rows' => $counts['total'],
            'valid_rows' => $counts['valid'],
            'warning_rows' => $counts['warning'],
            'error_rows' => $counts['error'],
            'updated_at' => now(),
        ]);

        return [
            'batch_id' => $batchId,
            'sheet' => $scanner->sheetName,
            'location_code' => $scanner->locationCode,
            'category_code' => $scanner->categoryCode,
            'total' => $counts['total'],
            'valid' => $counts['valid'],
            'warning' => $counts['warning'],
            'error' => $counts['error'],
            'skipped_rows' => $skippedRows,
            'status' => $status,
            'scope_rejected' => $scopeRejected,
        ];
    }

    /**
     * Stage 6.9 R6 — marks every row in this batch whose resolved
     * `location_code` is outside `$scope` as an `error` carrying a
     * `location_out_of_scope` message, and redacts any pre-existing
     * duplicate-detection detail on that SAME row (it's about to be
     * unconditionally rejected anyway, so there's no legitimate reason for
     * the actor to learn a cross-unit asset's id/identity through it — see
     * `redactDuplicateDetail()`). Rows with no resolved location at all are
     * intentionally left alone here: they already carry `location_missing`/
     * `invalid_location` errors from {@see RowValidator}, independent of
     * actor scope, and are already non-promotable for every role.
     *
     * @return bool whether any row was out of scope (the caller uses this to
     *              decide whether to force the batch's status to `failed`)
     */
    private function rejectOutOfScopeRows(int $batchId, LocationScope $scope): bool
    {
        $outOfScopeCodes = DB::table('import_rows')
            ->where('import_batch_id', $batchId)
            ->whereNotNull('location_code')
            ->distinct()
            ->pluck('location_code')
            ->reject(fn (string $code): bool => $scope->allows($code))
            ->values()
            ->all();

        if ($outOfScopeCodes === []) {
            return false;
        }

        $rows = DB::table('import_rows')
            ->where('import_batch_id', $batchId)
            ->whereIn('location_code', $outOfScopeCodes)
            ->get(['id', 'validation_messages']);

        foreach ($rows as $row) {
            $messages = $this->redactDuplicateDetail(
                json_decode((string) $row->validation_messages, true) ?: []
            );
            $messages[] = [
                'code' => 'location_out_of_scope',
                'severity' => 'error',
                'field' => 'location_code',
                'message' => 'Lokasi baris ini berada di luar unit yang menjadi wewenang Anda.',
            ];

            DB::table('import_rows')->where('id', $row->id)->update([
                'validation_status' => 'error',
                'validation_messages' => json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'duplicate_of_asset_id' => null,
                'updated_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Stage 6.9 R6 — a row about to be rejected for being out of the actor's
     * scope may ALSO have matched an existing asset during normal duplicate
     * detection ({@see DuplicateChecker}) — and since duplicate identity
     * includes `location_code`, that existing asset is, by construction, in
     * the SAME (out-of-scope) location, never the actor's own. Its id and
     * the `duplicate_existing_asset` message text (which embeds that id
     * verbatim) are stripped here so an unauthorized actor can never learn
     * a cross-unit asset's identity merely by uploading a file that happens
     * to collide with it. `duplicate_in_batch` (no asset id involved at
     * all) is left untouched — it discloses nothing about `assets`.
     *
     * @param  list<array{code:string,severity:string,field:?string,message:string}>  $messages
     * @return list<array{code:string,severity:string,field:?string,message:string}>
     */
    private function redactDuplicateDetail(array $messages): array
    {
        return array_values(array_map(function (array $m): array {
            if ($m['code'] !== 'duplicate_existing_asset') {
                return $m;
            }

            return [
                'code' => $m['code'],
                'severity' => $m['severity'],
                'field' => $m['field'],
                'message' => 'business identity duplicates an existing asset (details withheld: row is outside your unit).',
            ];
        }, $messages));
    }

    /**
     * @return array{promoted:int, skipped_already:int, failed:int, errors:list<array{row:int,message:string}>}
     */
    public function promoteBatch(int $batchId, ?User $actor = null): array
    {
        return $this->promoter->promoteBatch($batchId, $actor);
    }

    public function existingNonRolledBackBatches(string $filename, string $sheet): int
    {
        return DB::table('import_batches')
            ->where('source_filename', $filename)
            ->where('source_sheet', $sheet)
            ->where('status', '!=', 'rolled_back')
            ->count();
    }

    /** @return array{total:int, valid:int, warning:int, error:int} */
    private function recomputeCounts(int $batchId): array
    {
        $base = DB::table('import_rows')->where('import_batch_id', $batchId);

        return [
            'total' => (clone $base)->count(),
            'valid' => (clone $base)->where('validation_status', 'valid')->count(),
            'warning' => (clone $base)->where('validation_status', 'warning')->count(),
            'error' => (clone $base)->where('validation_status', 'error')->count(),
        ];
    }

    private function batchNote(SheetScanner $scanner, int $skippedRows): string
    {
        $bits = [
            "header row {$scanner->headerRow}",
            "first data row {$scanner->firstDataRow}",
        ];
        if ($scanner->locationLabel !== null) {
            $bits[] = "LOKASI label \"{$scanner->locationLabel}\"";
        }
        if ($scanner->categoryLabel !== null) {
            $bits[] = "KODE BARANG label \"{$scanner->categoryLabel}\"";
        }
        if ($skippedRows > 0) {
            $bits[] = "{$skippedRows} non-data row(s) skipped";
        }

        return implode('; ', $bits);
    }
}
