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
        // utf8mb4_0900_ai_ci collation would merge "KETUA HARIAN" and "Ketua Harian".
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
     * @return array{exact_name:int, alias:int, none:int, total:int, distinct_raw:int}
     */
    public function roomMethodTotals(?array $roomMapping = null): array
    {
        $rows = $roomMapping ?? $this->roomMapping();
        $out = ['exact_name' => 0, 'alias' => 0, 'none' => 0, 'total' => 0, 'distinct_raw' => count($rows)];
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

        $promotionFailed = (clone $base)
            ->where('validation_status', '!=', 'error')
            ->whereNull('promoted_asset_id')
            ->get(['validation_messages'])
            ->filter(fn ($r) => collect(json_decode((string) $r->validation_messages, true) ?: [])
                ->contains(fn ($m) => $m['code'] === 'promotion_failed'))
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
            'not_yet_promoted' => $promotable - $promoted,
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
     * @param  array{exact_name:int, alias:int, none:int, total:int, distinct_raw:int}|null  $roomMethodTotals
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
            'room methods: exact_name + alias + none == total rows',
            $rm['exact_name'] + $rm['alias'] + $rm['none'] === $rm['total'] && $rm['total'] === $s['total'],
            "{$rm['exact_name']} + {$rm['alias']} + {$rm['none']} == {$rm['total']} (== total {$s['total']})",
        );
        $add(
            'no unmapped room among promoted assets',
            DB::table('assets')->whereIn('import_row_id', (clone $this->rows())->select('id'))->whereNull('room_id')->count() === $rm['none'],
            'assets with room_id NULL == staging rows with method none (' . $rm['none'] . ')',
        );
        $add(
            'no mutation_logs created by import',
            DB::table('mutation_logs')->count() === 0,
            'mutation_logs count == 0',
        );

        return $checks;
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
