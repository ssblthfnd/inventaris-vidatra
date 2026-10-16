<?php

namespace App\Import\SourceResolution;

use App\Enums\AssetSource;
use App\Import\Promotion\AssetPromoter;
use App\Import\Validation\RowValidation;
use App\Models\ImportBatch;
use App\Models\User;
use App\Policies\ImportBatchPolicy;
use App\Support\LocationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tahap 2 — Sumber Aset: the import-preview correction for a staged row's
 * SUMBER value. The user picks one of the six {@see AssetSource} categories for
 * one or more staged rows of a batch, instead of editing the workbook and
 * re-importing it.
 *
 * The choice is persisted on the staged row (`import_rows.asset_source_override`
 * + who/when) inside one transaction, so it is what {@see AssetPromoter} reads
 * when the row is promoted — never a frontend-only state. `raw_payload` (the
 * verbatim workbook copy) is never rewritten. Same structure and the same
 * authorization boundary as the room-mapping resolver (R9.2 / R9.4-07 D2):
 *
 *  - WHAT: the route's `can:assets.import`;
 *  - WHERE: {@see ImportBatchPolicy::batchWithinScope()} for the current actor,
 *    re-derived on every call (403 when the batch is not entirely in scope);
 *  - only rows of THIS batch that are not yet promoted can be changed — a row id
 *    from another batch, an unknown id or an already-promoted row fails the
 *    whole call (422), nothing is partially applied;
 *  - the category itself is validated against the enum by the FormRequest, and
 *    again here (the method only accepts an {@see AssetSource}).
 *
 * Correcting a row resolves exactly the SUMBER messages (`asset_source_missing`,
 * `asset_source_unrecognized`); every other
 * message stays. A `warning` row becomes `valid` only when nothing else keeps it
 * at warning — the same rule, and the same `promotion_failed` exception, as
 * `RoomMappingResolver::withoutRoomUnmapped()`. Batch counters move by the exact
 * delta. Already-promoted assets are never touched (re-import never changes the
 * source of an existing asset either).
 */
final class AssetSourceResolver
{
    /** Message codes produced by RowParser::parseSource() — the ones a correction resolves. */
    public const SOURCE_MESSAGE_CODES = ['asset_source_missing', 'asset_source_unrecognized'];

    /**
     * @param  list<int>  $rowIds
     * @return array{asset_source:string, updated_rows:int, rows_became_valid:int, resolved_by:array{id:int,name:string}, resolved_at:string}
     *
     * @throws AuthorizationException batch not entirely within the actor's scope (403)
     * @throws RuntimeException a row id is not an unpromoted row of this batch (422)
     */
    public function resolve(ImportBatch $batch, array $rowIds, AssetSource $source, User $actor): array
    {
        if (! ImportBatchPolicy::batchWithinScope($batch->id, LocationScope::for($actor))) {
            throw new AuthorizationException(
                "Batch {$batch->id} contains data outside your assigned location."
            );
        }

        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));

        return DB::transaction(function () use ($batch, $rowIds, $source, $actor): array {
            $rows = DB::table('import_rows')
                ->where('import_batch_id', $batch->id)
                ->whereIn('id', $rowIds)
                ->whereNull('promoted_asset_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'validation_status', 'validation_messages']);

            if ($rows->count() !== count($rowIds)) {
                throw new RuntimeException(
                    'Sebagian baris tidak ditemukan pada batch ini atau sudah dipromosikan. Muat ulang pratinjau lalu coba lagi.'
                );
            }

            $now = now();
            $becameValid = 0;

            foreach ($rows as $row) {
                [$status, $messages] = $this->withoutSourceMessages($row);
                if ($status === RowValidation::VALID && $row->validation_status !== RowValidation::VALID) {
                    $becameValid++;
                }

                DB::table('import_rows')->where('id', $row->id)->update([
                    'asset_source_override' => $source->value,
                    'asset_source_resolved_by' => $actor->id,
                    'asset_source_resolved_at' => $now,
                    'validation_status' => $status,
                    'validation_messages' => $messages,
                    'updated_at' => $now,
                ]);
            }

            // Only warning -> valid can happen here (an `error` row keeps its
            // status, a `valid` row stays valid), so promotable totals and the
            // batch status are unchanged; the counters move by the exact delta.
            if ($becameValid > 0) {
                DB::table('import_batches')->where('id', $batch->id)->update([
                    'valid_rows' => DB::raw('valid_rows + '.$becameValid),
                    'warning_rows' => DB::raw('warning_rows - '.$becameValid),
                    'updated_at' => $now,
                ]);
            }

            return [
                'asset_source' => $source->value,
                'updated_rows' => $rows->count(),
                'rows_became_valid' => $becameValid,
                'resolved_by' => ['id' => $actor->id, 'name' => $actor->name],
                'resolved_at' => $now->toIso8601String(),
            ];
        });
    }

    /**
     * @return array{0:string, 1:string} new validation_status, JSON-encoded remaining messages
     */
    private function withoutSourceMessages(object $row): array
    {
        $messages = json_decode((string) $row->validation_messages, true) ?: [];
        $remaining = array_values(array_filter(
            $messages,
            fn (array $m): bool => ! in_array($m['code'] ?? null, self::SOURCE_MESSAGE_CODES, true),
        ));

        $stillFlagged = false;
        foreach ($remaining as $m) {
            $severity = $m['severity'] ?? null;
            if ($severity === 'warning' || ($severity === 'error' && ($m['code'] ?? null) !== 'promotion_failed')) {
                $stillFlagged = true;
                break;
            }
        }

        // `error` rows stay `error` (another real error keeps them there); a
        // `warning` row with nothing else flagged becomes `valid`.
        $status = ($row->validation_status === RowValidation::WARNING && ! $stillFlagged)
            ? RowValidation::VALID
            : $row->validation_status;

        return [$status, json_encode($remaining, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
}
