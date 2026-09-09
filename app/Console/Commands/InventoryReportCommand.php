<?php

namespace App\Console\Commands;

use App\Import\Reporting\ImportReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import staging / data-quality reports (Tahap 4 §34, §36–§40) over one or more batches.
 * Every number is read straight from `import_rows` / `import_batches` / `assets`.
 *
 * Section 0 is a REGRESSION CHECK: it fails the command (exit 1) if any two sections would
 * disagree (e.g. valid+warning+error != total, promoted != assets_created, room method
 * totals != total rows).
 *
 *   php artisan inventory:report --all
 *   php artisan inventory:report --batch=1 --batch=2
 */
class InventoryReportCommand extends Command
{
    protected $signature = 'inventory:report
        {--batch=* : One or more import_batches.id}
        {--all : Report over every batch}';

    protected $description = 'Show import staging / data-quality reports (with a consistency regression check)';

    public function handle(): int
    {
        $batchIds = $this->option('all')
            ? DB::table('import_batches')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all()
            : array_map('intval', (array) $this->option('batch'));

        if ($batchIds === []) {
            $this->error('Provide --batch=<id> (repeatable) or --all.');

            return self::INVALID;
        }

        $reporter = new ImportReporter($batchIds);
        $exit = self::SUCCESS;

        // ---------------------------------------------------------------- 0
        $this->section('0. CONSISTENCY REGRESSION CHECK');
        $s = $reporter->promotionSummary();
        $this->line(sprintf(
            '  total=%d  valid=%d  warning=%d  error=%d  |  promotable=%d  promoted=%d  promotion_failed=%d  not_yet_promoted=%d  |  assets_created=%d',
            $s['total'], $s['valid'], $s['warning'], $s['error'],
            $s['promotable'], $s['promoted'], $s['promotion_failed'], $s['not_yet_promoted'], $s['assets_created'],
        ));
        $this->newLine();
        foreach ($reporter->consistencyCheck() as $c) {
            $tag = $c['ok'] ? '<info>OK  </info>' : '<error>FAIL</error>';
            $this->line("  {$tag}  {$c['check']}   [{$c['detail']}]");
            if (! $c['ok']) {
                $exit = self::FAILURE;
            }
        }

        // ---------------------------------------------------------------- A
        $this->section('A. BATCH SUMMARY');
        $this->table(
            ['batch', 'file', 'sheet', 'cat', 'status', 'total', 'valid', 'warning', 'error', 'imported'],
            array_map(fn ($b) => [
                $b['batch_id'], $b['file'], $b['sheet'], $b['category'], $b['status'],
                $b['total'], $b['valid'], $b['warning'], $b['error'], $b['imported'],
            ], $reporter->batchSummary()),
        );
        $this->line(sprintf(
            "  TOTAL   total=%d  valid=%d  warning=%d  error=%d  imported=%d",
            $s['total'], $s['valid'], $s['warning'], $s['error'], $s['promoted'],
        ));

        // ---------------------------------------------------------------- B
        $this->section('B. LOCATIONS  (master + imported asset count)');
        $this->table(
            ['code', 'name', 'alias', 'active', 'asset_count', 'source'],
            array_map(fn ($l) => [
                $l['code'], $l['name'], $l['alias'] ?? '—', $l['is_active'] ? 'yes' : 'no',
                $l['asset_count'], 'master_data.md §1 (finalized Tahap 1)',
            ], $reporter->locationsOverview()),
        );

        // ---------------------------------------------------------------- C
        $rm = $reporter->roomMethodTotals();
        $this->section("C. ROOM MAPPING  (distinct raw = {$rm['distinct_raw']}  |  exact_name={$rm['exact_name']}  alias={$rm['alias']}  none={$rm['none']}  total={$rm['total']})");
        $this->table(
            ['location_code', 'raw_value', 'normalized_match_key', 'match_method', 'matched_room_id', 'canonical_room_name', 'count'],
            array_map(fn ($r) => [
                $r['location_code'], $r['raw_value'], $r['normalized_match_key'], $r['match_method'],
                $r['matched_room_id'] ?? 'NULL', $r['canonical_room_name'] ?? '— UNMAPPED —', $r['count'],
            ], $reporter->roomMapping()),
        );

        // ---------------------------------------------------------------- C2
        $this->section('C2. MATCH-KEY COLLAPSE  (raw values sharing one (location, match_key) -> one alias)');
        $collapse = $reporter->matchKeyCollapse();
        $multi = array_values(array_filter($collapse, fn ($g) => count($g['raw_values']) > 1));
        $this->line('  ' . count($collapse) . ' distinct (location, match_key) groups; '
            . count($multi) . ' of them cover more than one raw spelling:');
        $this->table(
            ['loc', 'match_key', 'canonical_room', 'method', '# raw', 'raw_values'],
            array_map(fn ($g) => [
                $g['location_code'], $g['match_key'], $g['canonical_room_name'] ?? '—', $g['method'],
                count($g['raw_values']), implode('  •  ', $g['raw_values']),
            ], $multi),
        );

        // ---------------------------------------------------------------- D
        $this->section('D. CONDITION SUMMARY');
        $cs = $reporter->conditionSummary();
        $this->table(['bucket', 'count'], array_map(fn ($k, $v) => [$k, $v], array_keys($cs), array_values($cs)));
        $unknown = $reporter->unknownConditionRawValues();
        if ($unknown !== []) {
            $this->line('  unresolved raw "Keadaan Barang" values (condition = NULL):');
            $this->table(['condition_raw', 'rows'], array_map(fn ($u) => [$u['raw'], $u['count']], $unknown));
        }

        // ---------------------------------------------------------------- E
        $this->section('E. DUPLICATES');
        $dups = $reporter->duplicates();
        $this->line('  in-batch duplicates: ' . count($dups['in_batch']));
        if ($dups['in_batch'] !== []) {
            $this->table(['batch', 'row', 'identity', 'detail'],
                array_map(fn ($d) => [$d['batch_id'], $d['row'], $d['identity'], $d['detail']], $dups['in_batch']));
        }
        $this->line('  duplicates against existing assets: ' . count($dups['existing']));
        if ($dups['existing'] !== []) {
            $this->table(['batch', 'row', 'identity', 'existing asset id'],
                array_map(fn ($d) => [$d['batch_id'], $d['row'], $d['identity'], $d['existing_asset_id']], $dups['existing']));
        }

        // ---------------------------------------------------------------- F
        $this->section('F. DATA QUALITY  (validation messages by code)');
        $dq = $reporter->dataQuality();
        $this->table(['code', 'severity', 'count'], array_map(fn ($q) => [$q['code'], $q['severity'], $q['count']], $dq));
        if ($dq === []) {
            $this->line('  (no warnings or errors)');
        }

        // ---------------------------------------------------------------- G
        $this->section('G. PROMOTION SUMMARY');
        $this->table(
            ['metric', 'value'],
            [
                ['total staged rows', $s['total']],
                ['valid', $s['valid']],
                ['warning', $s['warning']],
                ['error', $s['error']],
                ['promotable (valid + warning)', $s['promotable']],
                ['promoted', $s['promoted']],
                ['promotion_failed', $s['promotion_failed']],
                ['not yet promoted', $s['not_yet_promoted']],
                ['assets created from these batches', $s['assets_created']],
            ],
        );

        $this->newLine();
        $this->line($exit === self::SUCCESS
            ? '<info>Consistency regression check: PASS</info>'
            : '<error>Consistency regression check: FAIL — see section 0</error>');

        return $exit;
    }

    private function section(string $title): void
    {
        $this->line("\n<comment>" . str_repeat('─', 78) . "</comment>");
        $this->line("<comment>{$title}</comment>");
        $this->line('<comment>' . str_repeat('─', 78) . '</comment>');
    }
}
