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
 */
final class ImportManager
{
    public function __construct(
        private readonly RowParser $parser,
        private readonly RoomMatcher $roomMatcher,
        private readonly AssetPromoter $promoter,
    ) {
    }

    /**
     * Stage + validate one workbook into a fresh import batch.
     *
     * @return array{batch_id:int, sheet:string, location_code:?string, category_code:?string,
     *               total:int, valid:int, warning:int, error:int, skipped_rows:int, status:string}
     */
    public function stageFile(string $path, ?int $uploadedBy = null): array
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

        $master = new MasterData();
        $duplicates = new DuplicateChecker();
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
        ];
    }

    /**
     * @return array{promoted:int, skipped_already:int, failed:int, errors:list<array{row:int,message:string}>}
     */
    public function promoteBatch(int $batchId): array
    {
        return $this->promoter->promoteBatch($batchId);
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
