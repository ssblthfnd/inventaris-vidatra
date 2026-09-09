<?php

namespace App\Console\Commands;

use App\Import\Excel\ScannedRow;
use App\Import\Matching\RoomMatchResult;
use App\Import\Matching\RoomMatcher;
use App\Import\Parsing\ParsedRow;
use App\Import\Parsing\RowParser;
use App\Import\Parsing\ValueNormalizer;
use App\Import\Promotion\AssetPromoter;
use App\Import\Reporting\ImportReporter;
use App\Import\Validation\DuplicateChecker;
use App\Import\Validation\MasterData;
use App\Import\Validation\RowValidation;
use App\Import\Validation\RowValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic test matrix for the Tahap 4 import pipeline (§42).
 *
 * Runs against the real (seeded) MySQL database inside ONE transaction that is always
 * rolled back — MySQL-specific features (generated column, CHECK, composite FK) cannot be
 * exercised under the sqlite test connection configured in phpunit.xml.
 *
 *   php artisan inventory:selftest
 */
class InventorySelfTestCommand extends Command
{
    protected $signature = 'inventory:selftest';
    protected $description = 'Run the 23 mandatory import-pipeline test cases (transaction, rolled back)';

    private RowParser $parser;
    private RoomMatcher $matcher;
    private int $pass = 0;
    private int $fail = 0;

    public function handle(RowParser $parser, RoomMatcher $matcher): int
    {
        $this->parser = $parser;
        $this->matcher = $matcher;

        if (DB::table('locations')->count() === 0) {
            $this->error('Seed master data first:  php artisan db:seed --class=Database\\Seeders\\MasterDataSeeder');

            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            $this->matcher->forgetCache();
            $this->runAll();
        } finally {
            DB::rollBack();
            $this->matcher->forgetCache();
        }

        $this->newLine();
        $this->line(sprintf('<info>%d passed</info>, <%s>%d failed</>', $this->pass, $this->fail ? 'error' : 'info', $this->fail));

        return $this->fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function runAll(): void
    {
        $master = new MasterData();

        // Each single-row validation test gets a fresh DuplicateChecker so identities do
        // not leak between unrelated cases. In-batch duplicate detection is exercised
        // explicitly in test 13 with its own checker.
        $validate = function (array $cells, string $batchCat = '02') use ($master): RowValidation {
            $validator = new RowValidator($master, new DuplicateChecker());
            $parsed = $this->parser->parse($this->mkRow($cells), $batchCat);
            $room = $this->matcher->match($parsed->locationCode, $parsed->roomRawValue);

            return $validator->validate($parsed, $room, $batchCat);
        };

        $good = ['B' => '01', 'C' => '02', 'D' => '001', 'E' => '001', 'F' => '2020', 'K' => '1', 'L' => 'v', 'O' => 'KEUANGAN'];

        // 1
        $this->check('01 valid asset row', function () use ($validate, $good) {
            $v = $validate($good);

            return [$v->status === RowValidation::VALID, "status={$v->status}"];
        });

        // 2
        $this->check('02 invalid location -> error', function () use ($validate, $good) {
            $v = $validate(['B' => '99'] + $good);

            return [$this->hasCode($v, 'invalid_location') && $v->status === 'error', $this->codes($v)];
        });

        // 3
        $this->check('03 invalid category -> error', function () use ($validate, $good) {
            $v = $validate(['C' => '88'] + $good, '88');

            return [$this->hasCode($v, 'invalid_category') && $v->status === 'error', $this->codes($v)];
        });

        // 4
        $this->check('04 invalid category/subcategory combo (02/999) -> error', function () use ($validate, $good) {
            $v = $validate(['D' => '999'] + $good);

            return [$this->hasCode($v, 'invalid_subcategory') && $v->status === 'error', $this->codes($v)];
        });

        // 5
        $this->check('05 empty sequence -> error', function () use ($validate, $good) {
            $v = $validate(['E' => ''] + $good);

            return [$this->hasCode($v, 'missing_sequence') && $v->status === 'error', $this->codes($v)];
        });

        // 6 + 7
        $this->check('06 suffix sequence 005A -> valid, stored as "005A"', function () use ($validate, $good) {
            $parsed = $this->parser->parse($this->mkRow(['E' => '005A'] + $good), '02');
            $v = $validate(['E' => '005A'] + $good);

            return [$parsed->sequenceNo === '005A' && $v->status === RowValidation::VALID, "seq={$parsed->sequenceNo} status={$v->status}"];
        });
        $this->check('07 suffix sequence 005B -> valid, distinct from 005A', function () {
            $a = $this->parser->parse($this->mkRow(['B' => '01', 'C' => '02', 'D' => '001', 'E' => '005A', 'F' => '2020']), '02');
            $b = $this->parser->parse($this->mkRow(['B' => '01', 'C' => '02', 'D' => '001', 'E' => '005B', 'F' => '2020']), '02');

            return [$a->identityKey() !== $b->identityKey() && $b->sequenceNo === '005B', "{$a->identityLabel()} vs {$b->identityLabel()}"];
        });

        // 8
        $this->check('08 invalid year (1500) -> error', function () use ($validate, $good) {
            $v = $validate(['F' => '1500'] + $good);

            return [$this->hasCode($v, 'invalid_year') && $v->status === 'error', $this->codes($v)];
        });

        // 9
        $this->check('09 exact room match (GUDANG @ 01)', function () {
            $r = $this->matcher->match('01', 'GUDANG');

            return [$r->method === RoomMatchResult::METHOD_EXACT_NAME && $r->roomId !== null, "method={$r->method} room={$r->roomId}"];
        });

        // 10
        $this->check('10 alias room match (PERSO DAN UMUM @ 01)', function () {
            $r = $this->matcher->match('01', 'PERSO DAN UMUM');
            $name = DB::table('rooms')->where('id', $r->roomId)->value('name');

            return [$r->method === RoomMatchResult::METHOD_ALIAS && $name === 'Ruangan Personalia & SARPRAS', "method={$r->method} -> {$name}"];
        });

        // 11
        $this->check('11 unknown room -> none + warning room_unmapped', function () use ($validate, $good) {
            $r = $this->matcher->match('01', 'PLANET MARS');
            $v = $validate(['O' => 'PLANET MARS'] + $good);

            return [
                $r->method === RoomMatchResult::METHOD_NONE && $r->roomId === null
                    && $this->hasCode($v, 'room_unmapped') && $v->status === 'warning',
                "match={$r->method} " . $this->codes($v),
            ];
        });

        // 12
        $this->check('12 same room name, different locations = different rooms', function () {
            $loc01 = $this->matcher->match('01', 'GUDANG');
            DB::table('rooms')->insert(['location_code' => '03', 'name' => 'Gudang', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->matcher->forgetCache();
            $loc03 = $this->matcher->match('03', 'GUDANG');
            $loc01b = $this->matcher->match('01', 'GUDANG');
            $this->matcher->forgetCache();

            return [
                $loc01->roomId !== null && $loc03->roomId !== null
                    && $loc01->roomId !== $loc03->roomId && $loc01b->roomId === $loc01->roomId,
                "loc01={$loc01->roomId} loc03={$loc03->roomId}",
            ];
        });

        // 13
        $this->check('13 duplicate within batch -> error, NO duplicate_of_asset_id', function () use ($master) {
            $dc = new DuplicateChecker();
            $vv = new RowValidator($master, $dc);
            $cells = ['B' => '01', 'C' => '02', 'D' => '001', 'E' => '777', 'F' => '2020', 'K' => '1', 'L' => 'v', 'O' => 'KEUANGAN'];
            $p1 = $this->parser->parse($this->mkRow($cells, 50), '02');
            $p2 = $this->parser->parse($this->mkRow($cells, 51), '02');
            $r = $this->matcher->match('01', 'KEUANGAN');
            $v1 = $vv->validate($p1, $r, '02');
            $v2 = $vv->validate($p2, $r, '02');

            return [
                $v1->status === RowValidation::VALID
                    && $this->hasCode($v2, 'duplicate_in_batch') && $v2->status === 'error'
                    && $v2->duplicateOfAssetId === null,
                "v1={$v1->status} v2={$v2->status} dupId=" . var_export($v2->duplicateOfAssetId, true),
            ];
        });

        // 14
        $this->check('14 duplicate against existing asset -> error + duplicate_of_asset_id', function () use ($master) {
            $assetId = DB::table('assets')->insertGetId($this->assetRow(['sequence_no' => '888', 'asset_year' => 2020]));
            $dc = new DuplicateChecker();
            $vv = new RowValidator($master, $dc);
            $p = $this->parser->parse($this->mkRow(['B' => '01', 'C' => '02', 'D' => '001', 'E' => '888', 'F' => '2020', 'K' => '1', 'L' => 'v', 'O' => 'KEUANGAN']), '02');
            $v = $vv->validate($p, $this->matcher->match('01', 'KEUANGAN'), '02');

            return [
                $this->hasCode($v, 'duplicate_existing_asset') && $v->status === 'error' && $v->duplicateOfAssetId === $assetId,
                "dupId={$v->duplicateOfAssetId} expected={$assetId}",
            ];
        });

        // 15
        $this->check('15 "Di Junk" -> is_written_off=1, condition still parsed from B/KB/RB', function () {
            $p = $this->parser->parse($this->mkRow(['B' => '01', 'C' => '03', 'D' => '001', 'E' => '900', 'F' => '2015', 'L' => 'v', 'S' => 'Di Junk', 'T' => '45548']), '03');

            return [
                $p->isWrittenOff === true && $p->conditionParsed === 'baik' && $p->writtenOffOn === '2024-09-13',
                "writtenOff={$p->isWrittenOff} cond={$p->conditionParsed} on={$p->writtenOffOn}",
            ];
        });

        // 16
        $this->check('16 unknown condition -> condition NULL + warning', function () use ($validate, $good) {
            $p = $this->parser->parse($this->mkRow(['L' => '', 'M' => '', 'N' => ''] + $good), '02');
            $v = $validate(['L' => '', 'M' => '', 'N' => ''] + $good);

            return [$p->conditionParsed === null && $this->hasCode($v, 'condition_missing') && $v->status === 'warning', $this->codes($v)];
        });

        // 17
        $this->check('17 Excel serial date parsed correctly', function () {
            $r = ValueNormalizer::parseDate(45175); // -> 2023-09-06

            return [$r['recognised'] && $r['date'] === '2023-09-06', json_encode($r)];
        });
        $this->check('17b Indonesian text date "30 Mei 2023"', function () {
            $r = ValueNormalizer::parseDate('30 Mei 2023');

            return [$r['recognised'] && $r['date'] === '2023-05-30', json_encode($r)];
        });

        // 18
        $this->check('18 blank date -> null, no warning', function () {
            $a = ValueNormalizer::parseDate('');
            $b = ValueNormalizer::parseDate(null);

            return [$a['date'] === null && $a['recognised'] && $b['date'] === null && $b['recognised'], json_encode([$a, $b])];
        });
        $this->check('18b bare non-serial number -> null + not recognised (would warn)', function () {
            $r = ValueNormalizer::parseDate('7');

            return [$r['date'] === null && $r['recognised'] === false, json_encode($r)];
        });

        // 19
        $this->check('19 already promoted row -> skipped on re-promote', function () {
            $batchId = $this->stageMiniBatch([['E' => '910', 'O' => 'KEUANGAN']]);
            $promoter = app(AssetPromoter::class);
            $r1 = $promoter->promoteBatch($batchId);
            $r2 = $promoter->promoteBatch($batchId);

            return [$r1['promoted'] === 1 && $r2['promoted'] === 0 && $r2['skipped_already'] === 1, json_encode([$r1, $r2])];
        });

        // 20
        $this->check('20 promotion transaction rollback on constraint failure', function () {
            $batchId = $this->stageMiniBatch([['E' => '911', 'O' => 'KEUANGAN']]);
            // corrupt the staged parsed year to violate CHECK(asset_year BETWEEN 1980 AND 2100)
            $row = DB::table('import_rows')->where('import_batch_id', $batchId)->first();
            $payload = json_decode((string) $row->raw_payload, true);
            $payload['parsed']['asset_year'] = 3000;
            DB::table('import_rows')->where('id', $row->id)->update(['raw_payload' => json_encode($payload)]);

            $before = DB::table('assets')->count();
            $r = app(AssetPromoter::class)->promoteBatch($batchId);
            $after = DB::table('assets')->count();
            $reloaded = DB::table('import_rows')->where('id', $row->id)->first();
            $msgs = json_decode((string) $reloaded->validation_messages, true);
            $hasErr = collect($msgs)->contains(fn ($m) => $m['code'] === 'promotion_failed');

            return [
                $r['failed'] === 1 && $r['promoted'] === 0 && $after === $before
                    && $reloaded->promoted_asset_id === null && $hasErr,
                "failed={$r['failed']} before={$before} after={$after} recorded=" . ($hasErr ? 'yes' : 'no'),
            ];
        });

        // 21
        $this->check('21 generated asset_code = loc.cat.subcat.seq.year', function () {
            $batchId = $this->stageMiniBatch([['B' => '01', 'C' => '02', 'D' => '003', 'E' => '0017A', 'F' => '2024', 'O' => 'KEUANGAN']]);
            app(AssetPromoter::class)->promoteBatch($batchId);
            $code = DB::table('assets')->where(['sequence_no' => '0017A', 'asset_year' => 2024])->value('asset_code');

            return [$code === '01.02.003.0017A.2024', "asset_code={$code}"];
        });

        // 22
        $this->check('22 promoted asset quantity remains 1', function () {
            $batchId = $this->stageMiniBatch([['E' => '912', 'O' => 'KEUANGAN']]);
            app(AssetPromoter::class)->promoteBatch($batchId);
            $q = DB::table('assets')->where('sequence_no', '912')->value('quantity');

            return [(int) $q === 1, "quantity={$q}"];
        });

        // 23
        $this->check('23 no mutation_logs created during initial import', function () {
            $before = DB::table('mutation_logs')->count();
            $batchId = $this->stageMiniBatch([['E' => '913', 'O' => 'KEUANGAN']]);
            app(AssetPromoter::class)->promoteBatch($batchId);
            $after = DB::table('mutation_logs')->count();

            return [$before === 0 && $after === 0, "before={$before} after={$after}"];
        });

        // 24 — regression: the report over the real imported batches must be self-consistent
        $this->check('24 report consistency check on real imported batches', function () {
            $batchIds = DB::table('import_batches')
                ->whereIn('source_filename', ['Inventaris Meubelair.xlsx', 'Inventaris Elektronik.xlsx', 'Inventaris Alat Kebersihan.xlsx'])
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            if ($batchIds === []) {
                return [false, 'no imported batches found — run: php artisan inventory:import --all --promote'];
            }
            $reporter = new ImportReporter($batchIds);
            $failed = array_filter($reporter->consistencyCheck(), fn ($c) => ! $c['ok']);
            $s = $reporter->promotionSummary();

            return [
                $failed === [],
                sprintf('total=%d valid=%d warning=%d error=%d promotable=%d promoted=%d failed=%d; %d check(s) failed',
                    $s['total'], $s['valid'], $s['warning'], $s['error'], $s['promotable'], $s['promoted'], $s['promotion_failed'], count($failed)),
            ];
        });
    }

    // ---------------------------------------------------------------- helpers

    private function check(string $label, \Closure $fn): void
    {
        try {
            [$ok, $detail] = $fn();
        } catch (\Throwable $e) {
            $ok = false;
            $detail = 'EXCEPTION: ' . $e->getMessage();
        }
        if ($ok) {
            $this->pass++;
            $this->line("  <info>PASS</info>  {$label}");
        } else {
            $this->fail++;
            $this->line("  <error>FAIL</error>  {$label}  — {$detail}");
        }
    }

    /** @param array<string,string> $cells */
    private function mkRow(array $cells, int $rowNumber = 100, ?string $blockCode = null): ScannedRow
    {
        return new ScannedRow($rowNumber, ScannedRow::TYPE_DATA, $cells, [], $blockCode);
    }

    private function hasCode(RowValidation $v, string $code): bool
    {
        foreach ($v->messages as $m) {
            if ($m['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    private function codes(RowValidation $v): string
    {
        return 'codes=[' . implode(',', array_map(fn ($m) => $m['code'], $v->messages)) . "] status={$v->status}";
    }

    /** @param array<string,mixed> $overrides */
    private function assetRow(array $overrides = []): array
    {
        return array_merge([
            'location_code' => '01', 'category_code' => '02', 'subcategory_code' => '001',
            'sequence_no' => '999', 'asset_year' => 2020, 'quantity' => 1, 'is_written_off' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    /**
     * Stage a tiny batch straight into import_rows (bypassing Excel) for promotion tests.
     *
     * @param list<array<string,string>> $rowCellSets
     */
    private function stageMiniBatch(array $rowCellSets): int
    {
        $now = now();
        $batchId = DB::table('import_batches')->insertGetId([
            'source_filename' => 'selftest.xlsx', 'source_sheet' => 'SELFTEST', 'category_code' => '02',
            'status' => 'validated', 'total_rows' => 0, 'valid_rows' => 0, 'warning_rows' => 0,
            'error_rows' => 0, 'imported_rows' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $rowNo = 1;
        foreach ($rowCellSets as $cells) {
            $cells = array_merge(['B' => '01', 'C' => '02', 'D' => '001', 'F' => '2020', 'K' => '1', 'L' => 'v'], $cells);
            $parsed = $this->parser->parse($this->mkRow($cells, $rowNo), '02');
            $room = $this->matcher->match($parsed->locationCode, $parsed->roomRawValue);

            DB::table('import_rows')->insert([
                'import_batch_id' => $batchId,
                'row_number' => $rowNo++,
                'raw_payload' => json_encode(['cells' => $cells, 'parsed' => $parsed->toArray()]),
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
                'validation_status' => 'valid',
                'validation_messages' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('import_batches')->where('id', $batchId)->update(['total_rows' => count($rowCellSets), 'valid_rows' => count($rowCellSets)]);

        return $batchId;
    }
}
