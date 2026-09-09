<?php

namespace App\Import\Promotion;

use App\Import\Parsing\ParsedRow;
use App\Import\Validation\DuplicateChecker;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Promotes staged rows into `assets` (Tahap 4 §26–§32).
 *
 *  - Only rows with validation_status IN ('valid','warning') AND promoted_asset_id IS NULL.
 *  - Each row promoted inside its own DB transaction. A row that fails on a constraint is
 *    NOT silently skipped — the error is written back to validation_messages.
 *  - Re-running is safe: an already-promoted row is skipped (idempotent).
 *  - `asset_code` is NEVER written — it is a DB generated column.
 *  - NO mutation_logs are created (initial inventory = initial state, §31).
 *  - Existing-asset duplicate is re-checked inside the transaction (final-state check, §29).
 */
final class AssetPromoter
{
    public function __construct(private readonly DuplicateChecker $duplicates)
    {
    }

    /**
     * @return array{promoted:int, skipped_already:int, failed:int, errors:list<array{row:int,message:string}>}
     */
    public function promoteBatch(int $batchId): array
    {
        $batch = DB::table('import_batches')->find($batchId);
        if ($batch === null) {
            throw new RuntimeException("Import batch {$batchId} not found.");
        }
        if (! in_array($batch->status, ['validated', 'partially_imported', 'imported'], true)) {
            throw new RuntimeException(
                "Batch {$batchId} is [{$batch->status}] — validate it before promotion."
            );
        }

        $result = ['promoted' => 0, 'skipped_already' => 0, 'failed' => 0, 'errors' => []];

        $rows = DB::table('import_rows')
            ->where('import_batch_id', $batchId)
            ->orderBy('row_number')
            ->get();

        foreach ($rows as $stagedRow) {
            if ($stagedRow->promoted_asset_id !== null) {
                $result['skipped_already']++;

                continue;
            }
            if (! in_array($stagedRow->validation_status, ['valid', 'warning'], true)) {
                continue;
            }

            try {
                $assetId = $this->promoteOne($stagedRow);
                $result['promoted']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['row' => (int) $stagedRow->row_number, 'message' => $e->getMessage()];
                $this->recordPromotionError($stagedRow, $e->getMessage());
            }
        }

        $this->refreshBatchCounters($batchId);

        return $result;
    }

    private function promoteOne(object $stagedRow): int
    {
        $payload = json_decode((string) $stagedRow->raw_payload, true, flags: JSON_THROW_ON_ERROR);
        $parsed = $payload['parsed'] ?? null;
        if (! is_array($parsed)) {
            throw new RuntimeException('raw_payload has no "parsed" section');
        }

        return DB::transaction(function () use ($stagedRow, $parsed): int {
            // final-state duplicate guard (§29) — another batch may have promoted the same
            // identity since this row was validated.
            $identity = new ParsedRow(
                rowNumber: (int) $stagedRow->row_number,
                locationCode: $parsed['location_code'] ?? null,
                categoryCode: $parsed['category_code'] ?? null,
                subcategoryCode: $parsed['subcategory_code'] ?? null,
                sequenceNo: $parsed['sequence_no'] ?? null,
                assetYear: $parsed['asset_year'] ?? null,
                roomRawValue: null, conditionRaw: '', conditionParsed: null,
                isWrittenOff: false, writtenOffOn: null, writtenOffNote: null,
                brandModel: null, serialNo: null, material: null, purchaseDate: null,
                fundingSource: null, detailType: null, capacityNote: null,
                quantity: 1, notes: null, blockSubcategoryCode: null,
            );
            $existing = $this->duplicates->existingAssetId($identity);
            if ($existing !== null) {
                throw new RuntimeException("identity already exists as asset id {$existing}");
            }

            $now = now();
            $assetId = DB::table('assets')->insertGetId([
                'location_code' => $parsed['location_code'],
                'category_code' => $parsed['category_code'],
                'subcategory_code' => $parsed['subcategory_code'],
                'sequence_no' => $parsed['sequence_no'],       // string, EXACTLY as read
                'asset_year' => $parsed['asset_year'],
                // asset_code: GENERATED — do NOT insert
                'room_id' => $stagedRow->matched_room_id,       // null => unmapped (kept for review)
                'room_raw_value' => $parsed['room_raw_value'],
                'condition' => $parsed['condition_parsed'],     // may be null (unknown/ambiguous)
                'is_written_off' => $parsed['is_written_off'] ? 1 : 0,
                'written_off_on' => $parsed['written_off_on'],
                'written_off_note' => $parsed['written_off_note'],
                'brand_model' => $parsed['brand_model'],
                'serial_no' => $parsed['serial_no'],
                'material' => $parsed['material'],
                'purchase_date' => $parsed['purchase_date'],
                'funding_source' => $parsed['funding_source'],
                'detail_type' => $parsed['detail_type'],
                'capacity_note' => $parsed['capacity_note'],
                'quantity' => 1,
                'notes' => $parsed['notes'],
                'import_row_id' => $stagedRow->id,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $affected = DB::table('import_rows')
                ->where('id', $stagedRow->id)
                ->whereNull('promoted_asset_id')
                ->update(['promoted_asset_id' => $assetId, 'promoted_at' => $now, 'updated_at' => $now]);

            if ($affected !== 1) {
                // concurrent promotion of the same row — abort so we don't double count
                throw new RuntimeException('import_row was promoted concurrently');
            }

            return $assetId;
        });
    }

    private function recordPromotionError(object $stagedRow, string $message): void
    {
        $messages = json_decode((string) $stagedRow->validation_messages, true) ?: [];
        $messages[] = [
            'code' => 'promotion_failed',
            'severity' => 'error',
            'field' => null,
            'message' => 'promotion failed: ' . $message,
        ];
        DB::table('import_rows')->where('id', $stagedRow->id)->update([
            'validation_messages' => json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    private function refreshBatchCounters(int $batchId): void
    {
        $base = DB::table('import_rows')->where('import_batch_id', $batchId);

        $total = (clone $base)->count();
        $valid = (clone $base)->where('validation_status', 'valid')->count();
        $warning = (clone $base)->where('validation_status', 'warning')->count();
        $error = (clone $base)->where('validation_status', 'error')->count();
        $imported = (clone $base)->whereNotNull('promoted_asset_id')->count();
        $promotable = $valid + $warning;

        $status = match (true) {
            $total > 0 && $error === $total => 'failed',
            $promotable > 0 && $imported === 0 => 'validated',
            $imported > 0 && $imported < $promotable => 'partially_imported',
            $imported > 0 && $imported === $promotable => 'imported',
            default => 'validated',
        };

        DB::table('import_batches')->where('id', $batchId)->update([
            'status' => $status,
            'total_rows' => $total,
            'valid_rows' => $valid,
            'warning_rows' => $warning,
            'error_rows' => $error,
            'imported_rows' => $imported,
            'imported_at' => $imported > 0 ? now() : null,
            'updated_at' => now(),
        ]);
    }
}
