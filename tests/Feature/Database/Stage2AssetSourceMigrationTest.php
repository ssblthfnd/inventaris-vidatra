<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tahap 2 — the two Sumber Aset migrations, run up and down for real against
 * the isolated test database (`inventaris_vidatra_test`, enforced by
 * {@see TestCase::refreshApplication()}; never the dev or production DB).
 *
 * Deliberately NOT RefreshDatabase: migrations are DDL, which MySQL commits
 * implicitly, so they cannot run inside RefreshDatabase's per-test transaction.
 * Instead this class rebuilds the schema with `migrate:fresh` before and after
 * each test, and leaves RefreshDatabaseState marked as migrated so later
 * RefreshDatabase tests start from the same clean, fully-migrated schema.
 */
class Stage2AssetSourceMigrationTest extends TestCase
{
    /** The two Tahap 2 migrations are the newest files, so `--step=2` targets exactly them. */
    private const TAHAP2_MIGRATIONS = [
        '2026_10_15_100000_add_asset_source_to_assets_table',
        '2026_10_15_100001_add_asset_source_override_to_import_rows_table',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh');
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
        parent::tearDown();
    }

    public function test_the_tahap2_migrations_are_the_latest_ones(): void
    {
        $latest = DB::table('migrations')->orderByDesc('id')->limit(2)->pluck('migration')->sort()->values()->all();

        $this->assertSame(self::TAHAP2_MIGRATIONS, $latest);
    }

    public function test_up_gives_every_existing_asset_including_trashed_belum_diberi_keterangan_and_keeps_funding_source(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2]);
        $this->assertFalse(Schema::hasColumn('assets', 'asset_source'));
        $this->assertFalse(Schema::hasColumn('import_rows', 'asset_source_override'));

        $this->seedLegacyRows();
        $before = $this->legacySnapshot();

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasColumn('assets', 'asset_source'));
        $rows = DB::table('assets')->orderBy('id')->get(['id', 'asset_source', 'deleted_at']);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('belum_diberi_keterangan', $row->asset_source, "asset {$row->id}");
        }
        $this->assertNotNull($rows[2]->deleted_at, 'the trashed asset was included');
        $this->assertSame(0, DB::table('assets')->where('asset_source', 'yayasan')->count());

        // funding_source and the printed inventory code are untouched
        $this->assertSame($before, $this->legacySnapshot());

        // new import correction columns exist, empty for existing staged rows
        $this->assertTrue(Schema::hasColumns('import_rows', ['asset_source_override', 'asset_source_resolved_by', 'asset_source_resolved_at']));
    }

    public function test_the_db_default_is_belum_diberi_keterangan_and_unknown_values_are_rejected(): void
    {
        $this->seedMasterData();
        DB::table('assets')->insert($this->assetRow('901', 'Dana BOS'));

        $this->assertSame('belum_diberi_keterangan', DB::table('assets')->where('sequence_no', '901')->value('asset_source'));

        $this->expectException(QueryException::class);
        DB::table('assets')->where('sequence_no', '901')->update(['asset_source' => 'apbd']);
    }

    public function test_down_drops_only_the_new_columns_and_keeps_funding_source_data(): void
    {
        $this->seedLegacyRows();
        DB::table('assets')->where('sequence_no', '801')->update(['asset_source' => 'bosda']);
        $before = $this->legacySnapshot();

        Artisan::call('migrate:rollback', ['--step' => 2]);

        $this->assertFalse(Schema::hasColumn('assets', 'asset_source'));
        $this->assertFalse(Schema::hasColumn('import_rows', 'asset_source_override'));
        $this->assertFalse(Schema::hasColumn('import_rows', 'asset_source_resolved_by'));
        $this->assertTrue(Schema::hasColumn('assets', 'funding_source'));
        $this->assertSame($before, $this->legacySnapshot(), 'rollback never touches funding_source or asset_code');

        // and up again restores the column (the chosen BOSDA is gone — documented rollback consequence)
        Artisan::call('migrate');
        $this->assertSame('belum_diberi_keterangan', DB::table('assets')->where('sequence_no', '801')->value('asset_source'));
    }

    /* ------------------------------------------------------------------ fixtures */

    private function seedMasterData(): void
    {
        DB::table('locations')->insertOrIgnore(['code' => '01', 'name' => 'YAYASAN', 'is_active' => 1]);
        DB::table('categories')->insertOrIgnore(['code' => '02', 'name' => 'MEUBELAIR', 'is_active' => 1]);
        DB::table('subcategories')->insertOrIgnore(['category_code' => '02', 'code' => '001', 'name' => 'MEJA', 'is_active' => 1]);
    }

    /** @return array<string, mixed> */
    private function assetRow(string $seq, ?string $funding, bool $trashed = false): array
    {
        return [
            'location_code' => '01', 'category_code' => '02', 'subcategory_code' => '001',
            'sequence_no' => $seq, 'asset_year' => 2020, 'quantity' => 1, 'is_written_off' => 0,
            'funding_source' => $funding, 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $trashed ? now() : null,
        ];
    }

    /** Pre-Tahap-2 rows (no `asset_source` key at all), incl. a soft-deleted one. */
    private function seedLegacyRows(): void
    {
        $this->seedMasterData();
        DB::table('assets')->insert([
            $this->assetRow('801', 'Yayasan'),
            $this->assetRow('802', null),
            $this->assetRow('803', '  BOSDA 2019  ', trashed: true),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function legacySnapshot(): array
    {
        return DB::table('assets')->orderBy('id')->get(['id', 'asset_code', 'funding_source', 'deleted_at'])
            ->map(fn ($r) => (array) $r)->all();
    }
}
