<?php

namespace Tests\Feature\Api;

use App\Enums\AssetSource;
use App\Enums\UserRole;
use App\Import\Excel\SheetScanner;
use App\Import\ImportManager;
use App\Import\SourceResolution\AssetSourceResolver;
use App\Models\Asset;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use App\Services\Asset\AssetExportService;
use App\Services\Import\ImportTemplateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 2 — Sumber Aset in the Excel import: the SUMBER column (U) is read from
 * workbooks that carry its header, historical workbooks keep working, a blank or
 * unrecognised value is a promotable warning, the preview correction is stored on
 * the staged row and used by promotion, promotion is REFUSED (server-side) while
 * any unrecognised value is unresolved, and nothing unknown is ever converted
 * silently — neither to Yayasan nor to "Belum diberi keterangan".
 */
class Stage2ImportAssetSourceTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    /** Stages rows for location 01 / category 02 / subcategory 001 (room KEUANGAN exists). */
    private function stage(array $rows, bool $withSourceColumn = true): int
    {
        $this->scope('01', '02', '001');
        if (! Room::query()->where('location_code', '01')->where('name', 'KEUANGAN')->exists()) {
            $this->room('01', ['name' => 'KEUANGAN']);
        }
        $upload = $this->makeImportUpload('02', $rows, withSourceColumn: $withSourceColumn);

        return app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];
    }

    private function rowOf(int $batchId, int $index = 0): ImportRow
    {
        return ImportRow::query()->where('import_batch_id', $batchId)->orderBy('row_number')->skip($index)->firstOrFail();
    }

    /** @return list<string> */
    private function codes(ImportRow $row): array
    {
        return array_column($row->fresh()->validation_messages ?? [], 'code');
    }

    private function promote(int $batchId): void
    {
        Sanctum::actingAs($this->operator());
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();
    }

    private function sourceOfAsset(string $sequenceNo): string
    {
        return Asset::query()->where('sequence_no', $sequenceNo)->firstOrFail()->asset_source->value;
    }

    /* ================================================================== workbook layouts */

    public function test_a_historical_workbook_without_column_u_imports_unchanged_as_belum_diberi_keterangan(): void
    {
        // a stray value in U is ignored when the header row has no SUMBER label
        $batchId = $this->stage([$this->validImportRowCells(['E' => '601', 'U' => 'BOSDA'])], withSourceColumn: false);
        $row = $this->rowOf($batchId);

        $this->assertSame('valid', $row->validation_status);
        $this->assertSame([], $this->codes($row), 'no new message for legacy workbooks');
        $this->assertStringContainsString('no SUMBER column', ImportBatch::findOrFail($batchId)->notes);

        Sanctum::actingAs($this->operator());
        $this->getJson("/api/imports/{$batchId}/rows")->assertOk()
            ->assertJsonPath('data.0.asset_source', 'belum_diberi_keterangan')
            ->assertJsonPath('data.0.asset_source_status', 'column_absent');

        $this->promote($batchId);
        $this->assertSame('belum_diberi_keterangan', $this->sourceOfAsset('601'));
    }

    public function test_a_blank_source_is_a_promotable_warning_recorded_as_belum_diberi_keterangan(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '602'])]);
        $row = $this->rowOf($batchId);

        $this->assertSame('warning', $row->validation_status);
        $this->assertSame(['asset_source_missing'], $this->codes($row));

        $this->promote($batchId);
        $this->assertSame('belum_diberi_keterangan', $this->sourceOfAsset('602'));
    }

    public function test_recognised_values_are_normalised_and_promoted(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '611', 'U' => 'bosda']),
            $this->validImportRowCells(['E' => '612', 'U' => '  Yayasan ']),
            $this->validImportRowCells(['E' => '613', 'U' => 'BELUM DIBERI  KETERANGAN']),
            $this->validImportRowCells(['E' => '614', 'U' => 'Lainnya']),
        ]);

        foreach ([0, 1, 2, 3] as $i) {
            $this->assertSame('valid', $this->rowOf($batchId, $i)->validation_status);
        }

        Sanctum::actingAs($this->operator());
        $this->getJson("/api/imports/{$batchId}/rows")->assertOk()
            ->assertJsonPath('data.0.asset_source', 'bosda')
            ->assertJsonPath('data.0.asset_source_label', 'BOSDA')
            ->assertJsonPath('data.1.asset_source_label', 'Yayasan')
            ->assertJsonPath('data.2.asset_source', 'belum_diberi_keterangan')
            ->assertJsonPath('data.3.asset_source_status', 'recognized');

        $this->promote($batchId);
        $this->assertSame('bosda', $this->sourceOfAsset('611'));
        $this->assertSame('yayasan', $this->sourceOfAsset('612'));
        $this->assertSame('belum_diberi_keterangan', $this->sourceOfAsset('613'));
        $this->assertSame('lainnya', $this->sourceOfAsset('614'));
    }

    /* ================================================================== unrecognised values */

    public function test_an_unrecognised_value_is_a_warning_showing_the_original_text(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '621', 'U' => 'Dana Desa'])]);
        $row = $this->rowOf($batchId);

        $this->assertSame('warning', $row->validation_status);
        $this->assertSame(['asset_source_unrecognized'], $this->codes($row));
        $this->assertStringContainsString('[Dana Desa]', $row->validation_messages[0]['message']);

        Sanctum::actingAs($this->operator());
        $this->getJson("/api/imports/{$batchId}/rows")->assertOk()
            ->assertJsonPath('data.0.asset_source', null)
            ->assertJsonPath('data.0.asset_source_raw', 'Dana Desa')
            ->assertJsonPath('data.0.asset_source_status', 'unrecognized');
        $this->getJson("/api/imports/{$batchId}")->assertOk()
            ->assertJsonPath('data.unresolved_source_pending_count', 1);
    }

    public function test_promotion_is_refused_while_an_unrecognised_value_is_unresolved(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '622', 'U' => 'Yayasan Lama']),
            $this->validImportRowCells(['E' => '623', 'U' => 'YYS']),
            $this->validImportRowCells(['E' => '624', 'U' => 'BOSDA']), // fine on its own
        ]);
        $countersBefore = ImportBatch::findOrFail($batchId)->only(['status', 'valid_rows', 'warning_rows', 'imported_rows']);
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/imports/{$batchId}/promote")
            ->assertStatus(422)
            ->assertJsonValidationErrors('batch')
            ->assertJsonPath('errors.batch.0', fn (string $m) => str_contains($m, '2 baris') && str_contains($m, 'Belum diberi keterangan'));

        // nothing at all was promoted — not even the recognised row — and nothing was converted
        $this->assertSame(0, Asset::count());
        $this->assertSame(0, ImportRow::query()->where('import_batch_id', $batchId)->whereNotNull('promoted_asset_id')->count());
        $this->assertSame($countersBefore, ImportBatch::findOrFail($batchId)->only(['status', 'valid_rows', 'warning_rows', 'imported_rows']));
        $this->assertNull($this->rowOf($batchId)->asset_source_override, 'no silent correction');
        $this->assertSame(['asset_source_unrecognized'], $this->codes($this->rowOf($batchId)));
    }

    public function test_promotion_is_still_refused_after_resolving_only_some_rows(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '625', 'U' => 'xyz']),
            $this->validImportRowCells(['E' => '626', 'U' => 'abc']),
        ]);
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/imports/{$batchId}/asset-sources/resolve", ['row_ids' => [$this->rowOf($batchId)->id], 'asset_source' => 'bosp'])
            ->assertOk();
        $this->getJson("/api/imports/{$batchId}")->assertJsonPath('data.unresolved_source_pending_count', 1);

        $this->postJson("/api/imports/{$batchId}/promote")->assertStatus(422);
        $this->assertSame(0, Asset::count());

        $this->postJson("/api/imports/{$batchId}/asset-sources/resolve", ['row_ids' => [$this->rowOf($batchId, 1)->id], 'asset_source' => 'bostk'])
            ->assertOk();
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 2);
        $this->assertSame('bosp', $this->sourceOfAsset('625'));
        $this->assertSame('bostk', $this->sourceOfAsset('626'));
    }

    public function test_the_promoter_itself_refuses_so_the_cli_path_cannot_skip_the_check(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '627', 'U' => 'xyz'])]);

        try {
            app(ImportManager::class)->promoteBatch($batchId); // CLI path: no actor
            $this->fail('promotion should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Promosi ditahan', $e->getMessage());
        }
        $this->assertSame(0, Asset::count());
    }

    public function test_the_unresolved_filter_lists_exactly_the_rows_holding_promotion_back(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '628', 'U' => 'xyz']),
            $this->validImportRowCells(['E' => '629']), // blank: warning, but promotable
            $this->validImportRowCells(['E' => '630', 'U' => 'Yayasan']),
        ]);
        Sanctum::actingAs($this->operator());

        $this->assertSame(['628'], $this->getJson("/api/imports/{$batchId}/rows?status=source_unresolved")->assertOk()->json('data.*.sequence_no'));

        $this->postJson("/api/imports/{$batchId}/asset-sources/resolve", ['row_ids' => [$this->rowOf($batchId)->id], 'asset_source' => 'lainnya'])
            ->assertOk();
        $this->assertSame([], $this->getJson("/api/imports/{$batchId}/rows?status=source_unresolved")->assertOk()->json('data'));
    }

    public function test_the_preview_correction_is_stored_on_the_row_and_used_by_promotion(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '631', 'U' => 'Dana Desa'])]);
        $row = $this->rowOf($batchId);
        $user = $this->operator();
        Sanctum::actingAs($user);

        $this->postJson("/api/imports/{$batchId}/asset-sources/resolve", ['row_ids' => [$row->id], 'asset_source' => 'BOSP'])
            ->assertOk()
            ->assertJsonPath('data.asset_source', 'bosp')
            ->assertJsonPath('data.updated_rows', 1)
            ->assertJsonPath('data.rows_became_valid', 1);

        $fresh = $row->fresh();
        $this->assertSame('bosp', $fresh->asset_source_override->value);
        $this->assertSame($user->id, $fresh->asset_source_resolved_by);
        $this->assertNotNull($fresh->asset_source_resolved_at);
        $this->assertSame('valid', $fresh->validation_status);
        $this->assertSame([], $this->codes($fresh));
        $this->assertSame('Dana Desa', $fresh->raw_payload['cells']['U'], 'raw workbook copy is never rewritten');

        $batch = ImportBatch::findOrFail($batchId);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(0, $batch->warning_rows);

        $this->getJson("/api/imports/{$batchId}/rows")->assertOk()
            ->assertJsonPath('data.0.asset_source', 'bosp')
            ->assertJsonPath('data.0.asset_source_status', 'corrected')
            ->assertJsonPath('data.0.asset_source_resolution.resolved_by.id', $user->id);
        $this->getJson("/api/imports/{$batchId}")->assertJsonPath('data.unresolved_source_pending_count', 0);

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();
        $this->assertSame('bosp', $this->sourceOfAsset('631'));
    }

    public function test_belum_diberi_keterangan_can_be_chosen_explicitly_in_the_preview(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '632', 'U' => '???'])]);
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/imports/{$batchId}/asset-sources/resolve", [
            'row_ids' => [$this->rowOf($batchId)->id], 'asset_source' => 'belum_diberi_keterangan',
        ])->assertOk();
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $this->assertSame('belum_diberi_keterangan', $this->sourceOfAsset('632'));
    }

    public function test_a_correction_only_resolves_the_source_warning_not_others(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '633', 'U' => 'xyz', 'O' => 'RUANG TAK DIKENAL'])]);
        $row = $this->rowOf($batchId);
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/imports/{$batchId}/asset-sources/resolve", ['row_ids' => [$row->id], 'asset_source' => 'yayasan'])
            ->assertOk()
            ->assertJsonPath('data.rows_became_valid', 0);

        $this->assertSame('warning', $row->fresh()->validation_status);
        $this->assertSame(['room_unmapped'], $this->codes($row));
    }

    public function test_the_correction_endpoint_rejects_invalid_values_foreign_and_promoted_rows(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '641', 'U' => 'xyz'])]);
        $otherBatchId = $this->stage([$this->validImportRowCells(['E' => '642', 'U' => 'xyz'])]);
        $row = $this->rowOf($batchId);
        $foreignRow = $this->rowOf($otherBatchId);
        Sanctum::actingAs($this->operator());
        $url = "/api/imports/{$batchId}/asset-sources/resolve";

        $this->postJson($url, ['row_ids' => [$row->id], 'asset_source' => 'apbd'])->assertStatus(422)->assertJsonValidationErrors('asset_source');
        $this->postJson($url, ['row_ids' => [$row->id]])->assertStatus(422)->assertJsonValidationErrors('asset_source');
        $this->postJson($url, ['row_ids' => [], 'asset_source' => 'bosda'])->assertStatus(422)->assertJsonValidationErrors('row_ids');
        // a row of another batch fails the whole call — nothing is partially applied
        $this->postJson($url, ['row_ids' => [$row->id, $foreignRow->id], 'asset_source' => 'bosda'])
            ->assertStatus(422)->assertJsonValidationErrors('row_ids');
        $this->assertNull($row->fresh()->asset_source_override);
        $this->assertNull($foreignRow->fresh()->asset_source_override);

        // an already-promoted row can no longer be changed
        $this->postJson($url, ['row_ids' => [$row->id], 'asset_source' => 'yayasan'])->assertOk();
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();
        $this->postJson($url, ['row_ids' => [$row->id], 'asset_source' => 'bosda'])->assertStatus(422);
        $this->assertSame('yayasan', $this->sourceOfAsset('641'));
    }

    public function test_the_correction_endpoint_enforces_the_import_gate_and_location_scope(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '651', 'U' => 'xyz'])]); // location 01
        $row = $this->rowOf($batchId);
        $payload = ['row_ids' => [$row->id], 'asset_source' => 'bosda'];
        $url = "/api/imports/{$batchId}/asset-sources/resolve";

        $this->postJson($url, $payload)->assertUnauthorized();

        Sanctum::actingAs($this->viewer());
        $this->postJson($url, $payload)->assertForbidden();

        Location::query()->firstOrCreate(['code' => '02'], ['name' => 'Lokasi 02', 'is_active' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']));
        $this->postJson($url, $payload)->assertForbidden();

        $this->assertNull($row->fresh()->asset_source_override);
    }

    /* ================================================================== re-import / template / export */

    public function test_reimporting_an_existing_identity_never_changes_its_stored_source(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('700', 2020, ['asset_source' => 'bosda'], '01', '02', '001');
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '700', 'F' => '2020']), // duplicate identity, blank SUMBER
            $this->validImportRowCells(['E' => '701', 'F' => '2020', 'U' => 'Yayasan']),
        ]);

        $this->assertContains('duplicate_existing_asset', $this->codes($this->rowOf($batchId)));
        $this->promote($batchId);
        $this->assertSame('yayasan', $this->sourceOfAsset('701'));

        $this->assertSame(1, Asset::query()->where('sequence_no', '700')->count());
        $this->assertSame('bosda', $this->sourceOfAsset('700'));
    }

    public function test_the_template_has_the_sumber_column_dropdown_and_reference_list(): void
    {
        $this->scope('01', '02', '001');
        $spreadsheet = app(ImportTemplateService::class)->generate('02');
        $data = $spreadsheet->getSheetByName('DATA') ?? $spreadsheet->getSheet(0);

        $this->assertSame('SUMBER', $data->getCell('U6')->getValue());
        $formula = $data->getCell('U8')->getDataValidation()->getFormula1();
        foreach (['BOSDA', 'BOSP', 'BOSTK', 'Yayasan', 'Lainnya', 'Belum diberi keterangan'] as $label) {
            $this->assertStringContainsString($label, $formula);
        }

        $referensi = $spreadsheet->getSheetByName('REFERENSI');
        $values = [];
        foreach ($referensi->getRowIterator() as $r) {
            $values[] = (string) $referensi->getCell('A'.$r->getRowIndex())->getValue();
        }
        $this->assertContains('Belum diberi keterangan', $values);

        $path = tempnam(sys_get_temp_dir(), 'tpl-');
        @unlink($path); // tempnam()'s placeholder — only its unique name is used
        $path .= '.xlsx';

        try {
            (new Xlsx($spreadsheet))->save($path);
            $scanner = new SheetScanner($path);
            iterator_to_array($scanner->rows());
            $this->assertTrue($scanner->hasSourceColumn);
        } finally {
            @unlink($path);
        }
    }

    public function test_an_exported_workbook_reimports_its_source_labels(): void
    {
        $asset = $this->existingAsset('710', 2020, ['asset_source' => 'bostk'], '01', '02', '001');
        $spreadsheet = app(AssetExportService::class)->build(Asset::with(['location', 'category', 'room'])->get());
        $path = tempnam(sys_get_temp_dir(), 'exp-');
        @unlink($path); // tempnam()'s placeholder — only its unique name is used
        $path .= '.xlsx';

        try {
            (new Xlsx($spreadsheet))->save($path);
            $batchId = app(ImportManager::class)->stageFile($path)['batch_id'];
        } finally {
            @unlink($path);
        }

        $parsed = $this->rowOf($batchId)->raw_payload['parsed'];
        $this->assertSame('bostk', $parsed['asset_source']);
        $this->assertSame('recognized', $parsed['asset_source_status']);
        $this->assertSame('bostk', $asset->fresh()->asset_source->value);
    }

    /* ================================================================== correction vs. promotion race */

    /**
     * Runs `$sideEffect` exactly once, right after AssetPromoter::promoteBatch()
     * has read the batch's staged rows and before it promotes any of them — the
     * window in which a concurrent source correction used to be ignored.
     */
    private function afterPromotionReadsRows(int $batchId, \Closure $sideEffect): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $sideEffect): void {
            if ($fired
                || ! str_starts_with($query->sql, 'select * from `import_rows` where `import_batch_id` = ?')
                || ! str_contains($query->sql, 'order by `row_number`')) {
                return;
            }
            $fired = true;
            $sideEffect();
        });
    }

    public function test_promotion_uses_a_source_corrected_after_the_rows_were_read(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '801', 'U' => 'BOSDA']),
            $this->validImportRowCells(['E' => '802']), // blank -> belum unless corrected
        ]);
        $first = $this->rowOf($batchId);
        $second = $this->rowOf($batchId, 1);
        $actor = $this->operator();

        $this->afterPromotionReadsRows($batchId, function () use ($batchId, $first, $second, $actor): void {
            $resolver = app(AssetSourceResolver::class);
            $batch = ImportBatch::findOrFail($batchId);
            $resolver->resolve($batch, [$first->id], AssetSource::Bosp, $actor);
            $resolver->resolve($batch, [$second->id], AssetSource::Bostk, $actor);
        });

        $result = app(ImportManager::class)->promoteBatch($batchId, $actor);

        $this->assertSame(2, $result['promoted']);
        $this->assertSame('bosp', $this->sourceOfAsset('801'), 'the later correction wins, not the stale BOSDA');
        $this->assertSame('bostk', $this->sourceOfAsset('802'));

        // staged row and created asset agree, row by row
        foreach ([$first, $second] as $row) {
            $fresh = $row->fresh();
            $this->assertSame(
                $fresh->asset_source_override->value,
                Asset::findOrFail($fresh->promoted_asset_id)->asset_source->value,
            );
        }
    }

    public function test_a_row_promoted_after_the_rows_were_read_is_not_promoted_again(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '811', 'U' => 'Yayasan'])]);
        $row = $this->rowOf($batchId);
        $other = $this->existingAsset('899', 2020, [], '01', '02', '001');

        // simulates a concurrent promotion that claimed the row in between
        $this->afterPromotionReadsRows($batchId, function () use ($row, $other): void {
            DB::table('import_rows')->where('id', $row->id)->update(['promoted_asset_id' => $other->id, 'promoted_at' => now()]);
        });

        $result = app(ImportManager::class)->promoteBatch($batchId);

        $this->assertSame(0, $result['promoted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('import_row was promoted concurrently', $result['errors'][0]['message']);
        $this->assertSame(0, Asset::query()->where('sequence_no', '811')->count(), 'no second asset');
        $this->assertSame($other->id, $row->fresh()->promoted_asset_id);
    }
}
