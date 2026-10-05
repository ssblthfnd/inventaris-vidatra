<?php

namespace App\Import\Reporting;

use App\Import\Parsing\ValueNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reports over staged import data (Tahap 4 §36–§40). Every number is derived
 * from the `import_rows` / `assets` tables, never from an in-memory counter.
 */
final class ImportReporter
{
    /** @param list<int> $batchIds */
    public function __construct(private readonly array $batchIds)
    {
    }

    private function rows(): \Illuminate\Database\Query\Builder
    {
        return DB::table('import_rows')->whereIn('import_batch_id', $this->batchIds);
    }

    /** @return list<array<string,mixed>> */
    public function batchSummary(): array
    {
        return DB::table('import_batches')
            ->whereIn('id', $this->batchIds)
            ->orderBy('id')
            ->get()
            ->map(fn ($b) => [
                'batch_id' => $b->id,
                'file' => $b->source_filename,
                'sheet' => $b->source_sheet,
                'category' => $b->category_code,
                'status' => $b->status,
                'total' => $b->total_rows,
                'valid' => $b->valid_rows,
                'warning' => $b->warning_rows,
                'error' => $b->error_rows,
                'imported' => $b->imported_rows,
            ])->all();
    }

    /**
     * @return list<array{location_code:string,raw_value:string,normalized_match_key:string,
     *                     match_method:string,matched_room_id:?int,canonical_room_name:?string,count:int}>
     */
    public function roomMapping(): array
    {
        $canonical = DB::table('rooms')->pluck('name', 'id');

        // Group in PHP with exact (case-sensitive) string keys — a MySQL GROUP BY under the
        // utf8mb4_unicode_ci collation would merge "KETUA HARIAN" and "Ketua Harian".
        $buckets = [];
        foreach ($this->rows()->get(['room_raw_value', 'location_code', 'room_match_method', 'matched_room_id']) as $r) {
            $raw = $r->room_raw_value ?? '(blank)';
            $key = $raw . "\x1f" . $r->location_code . "\x1f" . ($r->room_match_method ?? 'none') . "\x1f" . ($r->matched_room_id ?? '');
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'location_code' => (string) $r->location_code,
                    'raw_value' => (string) $raw,
                    'normalized_match_key' => ValueNormalizer::roomMatchKey((string) $raw),
                    'match_method' => (string) ($r->room_match_method ?? 'none'),
                    'matched_room_id' => $r->matched_room_id !== null ? (int) $r->matched_room_id : null,
                    'canonical_room_name' => $r->matched_room_id !== null ? ($canonical[$r->matched_room_id] ?? null) : null,
                    'count' => 0,
                ];
            }
            $buckets[$key]['count']++;
        }

        $out = array_values($buckets);
        usort($out, fn ($a, $b) => [$a['match_method'], -$a['count'], $a['raw_value']] <=> [$b['match_method'], -$b['count'], $b['raw_value']]);

        return $out;
    }

    /**
     * @param  list<array{location_code:string,raw_value:string,normalized_match_key:string,match_method:string,matched_room_id:?int,canonical_room_name:?string,count:int}>|null  $roomMapping
     *         pass an already-computed {@see roomMapping()} result to avoid recomputing it
     *         (Tahap 6.9 R9.1 — see {@see consistencyCheck()}'s docblock); omitted/null keeps the
     *         original self-contained behavior (computes it internally), unchanged for existing
     *         callers like `InventoryReportCommand`.
     * Tahap 6.9 R9.4-02: `manual` / `manual_alias` (rows resolved in the import room-mapping
     * flow) are counted separately from the automatic `exact_name` / `alias`.
     *
     * @return array{exact_name:int, alias:int, manual:int, manual_alias:int, none:int, total:int, distinct_raw:int}
     */
    public function roomMethodTotals(?array $roomMapping = null): array
    {
        $rows = $roomMapping ?? $this->roomMapping();
        $out = ['exact_name' => 0, 'alias' => 0, 'manual' => 0, 'manual_alias' => 0, 'none' => 0, 'total' => 0, 'distinct_raw' => count($rows)];
        foreach ($rows as $r) {
            $out[$r['match_method']] += $r['count'];
            $out['total'] += $r['count'];
        }

        return $out;
    }

    /**
     * Which distinct raw room values collapse onto the same (location_code, match_key), and
     * therefore share a single room_alias / canonical room. Explains "N raw values -> M aliases".
     *
     * @return list<array{location_code:string,match_key:string,canonical_room_name:?string,method:string,raw_values:list<string>}>
     */
    public function matchKeyCollapse(): array
    {
        $canonical = DB::table('rooms')->pluck('name', 'id');
        $groups = [];
        foreach ($this->rows()->get(['room_raw_value', 'location_code', 'room_match_method', 'matched_room_id']) as $r) {
            $raw = (string) ($r->room_raw_value ?? '(blank)');
            $mk = ValueNormalizer::roomMatchKey($raw);
            $gk = $r->location_code . "\x1f" . $mk;
            $groups[$gk]['location_code'] = (string) $r->location_code;
            $groups[$gk]['match_key'] = $mk;
            $groups[$gk]['method'] = (string) ($r->room_match_method ?? 'none');
            $groups[$gk]['canonical_room_name'] = $r->matched_room_id !== null ? ($canonical[$r->matched_room_id] ?? null) : null;
            $groups[$gk]['raw_values'][$raw] = true;
        }

        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'location_code' => $g['location_code'],
                'match_key' => $g['match_key'],
                'canonical_room_name' => $g['canonical_room_name'],
                'method' => $g['method'],
                'raw_values' => array_keys($g['raw_values']),
            ];
        }
        usort($out, fn ($a, $b) => [count($b['raw_values']), $a['match_key']] <=> [count($a['raw_values']), $b['match_key']]);

        return $out;
    }

    /**
     * Master locations with the number of imported assets that reference each.
     *
     * @return list<array{code:string,name:string,alias:?string,is_active:int,asset_count:int}>
     */
    public function locationsOverview(): array
    {
        $assetCounts = DB::table('assets')
            ->selectRaw('location_code, COUNT(*) c')
            ->groupBy('location_code')
            ->pluck('c', 'location_code');

        return DB::table('locations')->orderBy('code')->get()->map(fn ($l) => [
            'code' => $l->code,
            'name' => $l->name,
            'alias' => $l->alias,
            'is_active' => (int) $l->is_active,
            'asset_count' => (int) ($assetCounts[$l->code] ?? 0),
        ])->all();
    }

    /** @return array<string,int> */
    public function conditionSummary(): array
    {
        $out = ['baik' => 0, 'kurang_baik' => 0, 'rusak_berat' => 0, 'NULL' => 0, 'written_off' => 0];

        foreach ($this->rows()->get(['raw_payload', 'condition_parsed']) as $r) {
            $out[$r->condition_parsed ?? 'NULL']++;
            $parsed = json_decode((string) $r->raw_payload, true)['parsed'] ?? [];
            if (! empty($parsed['is_written_off'])) {
                $out['written_off']++;
            }
        }

        return $out;
    }

    /** @return list<array{raw:string,count:int}>  unrecognised Keadaan Barang raw strings */
    public function unknownConditionRawValues(): array
    {
        $seen = [];
        foreach ($this->rows()->get(['validation_messages', 'condition_raw']) as $r) {
            $messages = json_decode((string) $r->validation_messages, true) ?: [];
            foreach ($messages as $m) {
                if (in_array($m['code'], ['condition_raw_unrecognized', 'condition_missing', 'condition_ambiguous'], true)) {
                    $key = (string) $r->condition_raw;
                    $seen[$key] = ($seen[$key] ?? 0) + 1;
                }
            }
        }
        arsort($seen);

        return array_map(fn ($k, $v) => ['raw' => $k, 'count' => $v], array_keys($seen), array_values($seen));
    }

    /**
     * @return array{in_batch:list<array<string,mixed>>, existing:list<array<string,mixed>>}
     */
    public function duplicates(): array
    {
        $inBatch = [];
        $existing = [];

        foreach ($this->rows()->orderBy('import_batch_id')->orderBy('row_number')->get() as $r) {
            $messages = json_decode((string) $r->validation_messages, true) ?: [];
            foreach ($messages as $m) {
                if ($m['code'] === 'duplicate_in_batch') {
                    $inBatch[] = [
                        'batch_id' => $r->import_batch_id,
                        'row' => $r->row_number,
                        'identity' => $this->identityLabel($r),
                        'detail' => $m['message'],
                    ];
                }
                if ($m['code'] === 'duplicate_existing_asset') {
                    $existing[] = [
                        'batch_id' => $r->import_batch_id,
                        'row' => $r->row_number,
                        'identity' => $this->identityLabel($r),
                        'existing_asset_id' => $r->duplicate_of_asset_id,
                    ];
                }
            }
        }

        return ['in_batch' => $inBatch, 'existing' => $existing];
    }

    /** @return list<array{code:string,severity:string,count:int}> */
    public function dataQuality(): array
    {
        $counts = [];
        foreach ($this->rows()->pluck('validation_messages') as $json) {
            foreach (json_decode((string) $json, true) ?: [] as $m) {
                $k = $m['code'] . '|' . $m['severity'];
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($counts as $k => $v) {
            [$code, $severity] = explode('|', $k);
            $out[] = ['code' => $code, 'severity' => $severity, 'count' => $v];
        }
        usort($out, fn ($a, $b) => [$b['severity'], $b['count']] <=> [$a['severity'], $a['count']]);

        return $out;
    }

    /**
     * The single set of numbers every section of the report must agree with.
     *
     * Tahap 6.9 R9.4-D6 — the three promotion states of a promotable row are
     * mutually exclusive, each read from the row itself:
     *   - `promoted`         has `promoted_asset_id`
     *   - `promotion_failed` not promoted, carries a `promotion_failed` message
     *                        (AssetPromoter records it and leaves the row
     *                        retryable; a later successful retry makes it
     *                        `promoted`, so it stops counting here)
     *   - `not_yet_promoted` not promoted and never failed — genuinely pending
     * so `promoted + promotion_failed + not_yet_promoted == promotable`.
     * `not_yet_promoted` used to be `promotable - promoted`, which also counted
     * every failed row and made that check fail whenever a failure was pending.
     *
     * @return array{total:int,valid:int,warning:int,error:int,promotable:int,
     *               promoted:int,promotion_failed:int,not_yet_promoted:int,assets_created:int}
     */
    public function promotionSummary(): array
    {
        $base = $this->rows();

        $total = (clone $base)->count();
        $valid = (clone $base)->where('validation_status', 'valid')->count();
        $warning = (clone $base)->where('validation_status', 'warning')->count();
        $error = (clone $base)->where('validation_status', 'error')->count();
        $promotable = $valid + $warning;
        $promoted = (clone $base)->whereNotNull('promoted_asset_id')->count();

        $unpromoted = (clone $base)
            ->where('validation_status', '!=', 'error')
            ->whereNull('promoted_asset_id')
            ->get(['validation_status', 'validation_messages']);
        $hasFailed = fn ($r): bool => collect(json_decode((string) $r->validation_messages, true) ?: [])
            ->contains(fn ($m) => $m['code'] === 'promotion_failed');

        $promotionFailed = $unpromoted->filter($hasFailed)->count();
        $notYetPromoted = $unpromoted
            ->filter(fn ($r): bool => in_array($r->validation_status, ['valid', 'warning'], true) && ! $hasFailed($r))
            ->count();

        $assetsCreated = DB::table('assets')
            ->whereIn('import_row_id', (clone $base)->select('id'))
            ->count();

        return [
            'total' => $total,
            'valid' => $valid,
            'warning' => $warning,
            'error' => $error,
            'promotable' => $promotable,
            'promoted' => $promoted,
            'promotion_failed' => $promotionFailed,
            'not_yet_promoted' => $notYetPromoted,
            'assets_created' => $assetsCreated,
        ];
    }

    /**
     * Regression check: the report must never present contradictory counts.
     *
     * Tahap 6.9 R9.1 (query-redundancy hardening, P2): a caller that has already
     * computed {@see promotionSummary()} and/or {@see roomMethodTotals()} for the
     * SAME batch scope in the same request (e.g. `ImportController::report()`,
     * which needs both independently for its own response keys) can pass them in
     * here to avoid this method re-running those queries a second time. Omitted/
     * null preserves the original self-contained behavior — computes both itself
     * — unchanged for existing callers like `InventorySelfTestCommand` that only
     * ever call `consistencyCheck()` on its own.
     *
     * @param  array{total:int,valid:int,warning:int,error:int,promotable:int,promoted:int,promotion_failed:int,not_yet_promoted:int,assets_created:int}|null  $summary
     * @param  array{exact_name:int, alias:int, manual:int, manual_alias:int, none:int, total:int, distinct_raw:int}|null  $roomMethodTotals
     * @return list<array{check:string, ok:bool, detail:string}>
     */
    public function consistencyCheck(?array $summary = null, ?array $roomMethodTotals = null): array
    {
        $s = $summary ?? $this->promotionSummary();
        $rm = $roomMethodTotals ?? $this->roomMethodTotals();

        $checks = [];
        $add = function (string $name, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        $add(
            'valid + warning + error == total',
            $s['valid'] + $s['warning'] + $s['error'] === $s['total'],
            "{$s['valid']} + {$s['warning']} + {$s['error']} == {$s['total']}",
        );
        $add(
            'promotable == valid + warning',
            $s['promotable'] === $s['valid'] + $s['warning'],
            "{$s['promotable']} == {$s['valid']} + {$s['warning']}",
        );
        $add(
            'promoted <= promotable',
            $s['promoted'] <= $s['promotable'],
            "{$s['promoted']} <= {$s['promotable']}",
        );
        $add(
            'promoted + promotion_failed + not_yet_promoted == promotable',
            $s['promoted'] + $s['promotion_failed'] + $s['not_yet_promoted'] === $s['promotable'],
            "{$s['promoted']} + {$s['promotion_failed']} + {$s['not_yet_promoted']} == {$s['promotable']}",
        );
        $add(
            'assets_created == promoted',
            $s['assets_created'] === $s['promoted'],
            "{$s['assets_created']} == {$s['promoted']}",
        );
        $add(
            'room methods: exact_name + alias + manual + manual_alias + none == total rows',
            $rm['exact_name'] + $rm['alias'] + $rm['manual'] + $rm['manual_alias'] + $rm['none'] === $rm['total'] && $rm['total'] === $s['total'],
            "{$rm['exact_name']} + {$rm['alias']} + {$rm['manual']} + {$rm['manual_alias']} + {$rm['none']} == {$rm['total']} (== total {$s['total']})",
        );
        $room = $this->promotedRoomConsistency();
        $add(
            'promoted assets keep the room their staged row had at promotion',
            $room['mismatched'] === 0,
            "{$room['compared']} compared (of which {$room['without_room']} without room), {$room['mismatched']} mismatched; "
                . "{$room['not_verifiable']} not verifiable (asset changed after import)",
        );

        $createLogs = $this->importedAssetCreateLogCount();
        $add(
            'no CREATE mutation_logs for assets imported by this batch',
            $createLogs === 0,
            "CREATE mutation_logs on this batch's imported assets == {$createLogs} (later edits to those assets are not counted)",
        );

        return $checks;
    }

    /**
     * Tahap 6.9 R9.4-12 — replaces "assets with room_id NULL == staging rows with
     * method none", which compared populations that don't correspond: promoted
     * assets vs EVERY staged `none` row (incl. error / not-yet-promoted rows), and
     * current `assets.room_id` even after a later room move.
     *
     * Correct population: promoted rows of this scope, each paired with ITS asset
     * via `import_rows.promoted_asset_id` (what AssetPromoter writes). For each
     * pair, `assets.room_id` must equal `import_rows.matched_room_id` (NULL ==
     * NULL included). An asset with ANY mutation_log may have legitimately
     * changed room since (every app write path logs a mutation), so it cannot be
     * verified from current state and is counted as `not_verifiable`, never as a
     * mismatch. Soft-deleted assets are included (`DB::table` ignores the
     * SoftDeletes scope); deleting an asset logs a mutation anyway.
     *
     * @return array{compared:int, without_room:int, mismatched:int, not_verifiable:int}
     */
    private function promotedRoomConsistency(): array
    {
        $pairs = (clone $this->rows())
            ->join('assets', 'assets.id', '=', 'import_rows.promoted_asset_id')
            ->whereNotNull('import_rows.promoted_asset_id')
            ->selectRaw('import_rows.matched_room_id as staged_room_id, assets.room_id as asset_room_id')
            ->selectRaw('EXISTS (SELECT 1 FROM mutation_logs WHERE mutation_logs.asset_id = assets.id) as has_mutations')
            ->get();

        $out = ['compared' => 0, 'without_room' => 0, 'mismatched' => 0, 'not_verifiable' => 0];
        foreach ($pairs as $p) {
            if ((bool) $p->has_mutations) {
                $out['not_verifiable']++;

                continue;
            }
            $out['compared']++;
            if ($p->asset_room_id === null) {
                $out['without_room']++;
            }
            $staged = $p->staged_room_id === null ? null : (int) $p->staged_room_id;
            $actual = $p->asset_room_id === null ? null : (int) $p->asset_room_id;
            if ($staged !== $actual) {
                $out['mismatched']++;
            }
        }

        return $out;
    }

    /**
     * Tahap 6.9 R9.4-12 — replaces "mutation_logs count == 0", which counted the
     * GLOBAL table and so failed as soon as any unrelated asset had ever been
     * edited. Promotion deliberately writes no mutation_logs (§31); the one log a
     * creation path writes is CREATE (AssetWriteService, UI-created assets only),
     * so a CREATE log on an asset imported by this scope is exactly the evidence
     * "the import recorded a mutation" would leave. Ordinary later edits/moves/
     * deletes of those assets are legitimate and NOT counted.
     */
    private function importedAssetCreateLogCount(): int
    {
        return DB::table('mutation_logs')
            ->whereIn('asset_id', (clone $this->rows())->whereNotNull('promoted_asset_id')->select('promoted_asset_id'))
            ->where('event_type', 'CREATE')
            ->count();
    }

    private function identityLabel(object $r): string
    {
        return sprintf(
            '%s.%s.%s.%s.%s',
            $r->location_code ?? '??',
            $r->category_code ?? '??',
            $r->subcategory_code ?? '???',
            $r->sequence_no ?? '?',
            $r->asset_year ?? '????',
        );
    }
}
