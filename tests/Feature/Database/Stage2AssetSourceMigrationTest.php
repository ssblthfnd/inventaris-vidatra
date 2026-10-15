<?php

namespace Tests\Feature\Database;

use App\Models\Asset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tahap 2 — the two Sumber Aset migrations, run up and down for real against
 * the isolated test database (`inventaris_vidatra_test`, enforced by
 * {@see TestCase::refreshApplication()}; never the dev or production DB).
 *
 * Deliberately NOT RefreshDatabase and NO `migrate:fresh`: migrations are DDL
 * (implicitly committed by MySQL/MariaDB, so they cannot run inside a test
 * transaction), and the schema is never wiped. Each test only:
 *   - brings the schema up to date with `migrate` (non-destructive),
 *   - rolls back exactly the two Tahap 2 migrations (`--step=2`) and re-applies
 *     them, and
 *   - inserts its own rows under dedicated master codes (`ZM`), which tearDown
 *     deletes again and finishes with `migrate` so the schema is always left
 *     fully migrated, even after a failure.
 *
 * Note: re-applying the asset migration backfills EVERY row in the `assets`
 * table of the test database to `yayasan` — that is exactly the behaviour under
 * test, and the test DB is the only database this ever runs against.
 */
class Stage2AssetSourceMigrationTest extends TestCase
{
    /** The two Tahap 2 migrations are the newest files, so `--step=2` targets exactly them. */
    private const TAHAP2_MIGRATIONS = [
        '2026_10_15_100000_add_asset_source_to_assets_table',
        '2026_10_15_100001_add_asset_source_override_to_import_rows_table',
    ];

    /** Dedicated master codes (letters, so they never collide with real or factory codes). */
    private const LOC = 'ZM';

    private const CAT = 'ZM';

    private const SUB = '901';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
        $this->deleteOwnRows();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate', ['--force' => true]); // never leave the schema rolled back
        $this->deleteOwnRows();
        parent::tearDown();
    }

    public function test_the_tahap2_migrations_are_the_latest_ones(): void
    {
        $latest = DB::table('migrations')->orderByDesc('id')->limit(2)->pluck('migration')->sort()->values()->all();

        $this->assertSame(self::TAHAP2_MIGRATIONS, $latest);
    }

    public function test_up_sets_every_existing_asset_active_and_trashed_to_yayasan_and_changes_nothing_else(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('assets', 'asset_source'));
        $this->assertFalse(Schema::hasColumn('import_rows', 'asset_source_override'));

        $this->seedLegacyRows();
        $before = $this->ownRowsSnapshot();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasColumn('assets', 'asset_source'));
        $rows = $this->ownRows(['sequence_no', 'asset_source', 'deleted_at']);
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('yayasan', $row->asset_source, "asset {$row->sequence_no}");
        }
        $this->assertNotNull($rows->firstWhere('sequence_no', '9803')->deleted_at, 'the soft-deleted asset was included');
        // not inferred from funding_source: "BOSDA 2019" / "Hibah" / NULL all became yayasan
        $this->assertSame('yayasan', $rows->firstWhere('sequence_no', '9803')->asset_source);

        // every row of the table, not only ours
        $this->assertSame(0, DB::table('assets')->where('asset_source', '<>', 'yayasan')->count());

        // funding_source, asset_code, updated_at and the other columns are untouched
        $this->assertSame($before, $this->ownRowsSnapshot());

        // the import correction columns exist, empty for existing staged rows
        $this->assertTrue(Schema::hasColumns('import_rows', ['asset_source_override', 'asset_source_resolved_by', 'asset_source_resolved_at']));
        $this->assertSame(0, DB::table('import_rows')->whereNotNull('asset_source_override')->count());
    }

    public function test_assets_created_after_the_migration_without_a_source_get_belum_diberi_keterangan(): void
    {
        $this->seedMasterData();

        // raw insert without asset_source -> column default
        DB::table('assets')->insert($this->assetRow('9811', 'Dana BOS'));
        $this->assertSame('belum_diberi_keterangan', DB::table('assets')->where('location_code', self::LOC)->where('sequence_no', '9811')->value('asset_source'));

        // Eloquent create without asset_source -> model default, same value
        $asset = Asset::query()->create([
            'location_code' => self::LOC, 'category_code' => self::CAT, 'subcategory_code' => self::SUB,
            'sequence_no' => '9812', 'asset_year' => 2024,
        ]);
        $this->assertSame('belum_diberi_keterangan', $asset->fresh()->asset_source->value);

        // the ENUM still rejects anything outside the six values
        $this->expectException(QueryException::class);
        DB::table('assets')->where('location_code', self::LOC)->where('sequence_no', '9811')->update(['asset_source' => 'apbd']);
    }

    public function test_down_drops_only_the_new_columns_and_keeps_funding_source_data(): void
    {
        $this->seedLegacyRows(); // schema is migrated here, so these get the column default
        DB::table('assets')->where('location_code', self::LOC)->where('sequence_no', '9801')->update(['asset_source' => 'bosda']);
        $before = $this->ownRowsSnapshot();

        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

        $this->assertFalse(Schema::hasColumn('assets', 'asset_source'));
        $this->assertFalse(Schema::hasColumn('import_rows', 'asset_source_override'));
        $this->assertFalse(Schema::hasColumn('import_rows', 'asset_source_resolved_by'));
        $this->assertTrue(Schema::hasColumn('assets', 'funding_source'));
        $this->assertSame($before, $this->ownRowsSnapshot(), 'rollback never touches funding_source or asset_code');

        // up again re-applies the backfill: the chosen BOSDA is gone (documented
        // rollback consequence) and every then-existing asset is yayasan again
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(['yayasan'], $this->ownRows(['asset_source'])->pluck('asset_source')->unique()->values()->all());
    }

    /* ------------------------------------------------------------------ fixtures */

    private function seedMasterData(): void
    {
        DB::table('locations')->insertOrIgnore(['code' => self::LOC, 'name' => 'Lokasi Uji Migrasi', 'is_active' => 1]);
        DB::table('categories')->insertOrIgnore(['code' => self::CAT, 'name' => 'Kategori Uji Migrasi', 'is_active' => 1]);
        DB::table('subcategories')->insertOrIgnore(['category_code' => self::CAT, 'code' => self::SUB, 'name' => 'Subkategori Uji Migrasi', 'is_active' => 1]);
    }

    /** @return array<string, mixed> */
    private function assetRow(string $seq, ?string $funding, bool $trashed = false): array
    {
        return [
            'location_code' => self::LOC, 'category_code' => self::CAT, 'subcategory_code' => self::SUB,
            'sequence_no' => $seq, 'asset_year' => 2020, 'quantity' => 1, 'is_written_off' => 0,
            'brand_model' => "Merek {$seq}", 'funding_source' => $funding,
            'created_at' => '2025-01-02 03:04:05', 'updated_at' => '2025-01-02 03:04:05',
            'deleted_at' => $trashed ? '2025-02-03 04:05:06' : null,
        ];
    }

    /** Pre-Tahap-2 style rows (no `asset_source` key), incl. a soft-deleted one. */
    private function seedLegacyRows(): void
    {
        $this->seedMasterData();
        DB::table('assets')->insert([
            $this->assetRow('9801', 'Yayasan'),
            $this->assetRow('9802', null),
            $this->assetRow('9803', '  BOSDA 2019  ', trashed: true),
            $this->assetRow('9804', 'Hibah alumni'),
        ]);
    }

    private function ownRows(array $columns): Collection
    {
        return DB::table('assets')->where('location_code', self::LOC)->orderBy('sequence_no')->get($columns);
    }

    /**
     * Everything about our rows except `asset_source` itself.
     *
     * @return list<array<string, mixed>>
     */
    private function ownRowsSnapshot(): array
    {
        return $this->ownRows([
            'id', 'asset_code', 'sequence_no', 'brand_model', 'funding_source',
            'is_written_off', 'created_at', 'updated_at', 'deleted_at',
        ])->map(fn ($r) => (array) $r)->all();
    }

    private function deleteOwnRows(): void
    {
        DB::table('assets')->where('location_code', self::LOC)->delete();
        DB::table('subcategories')->where('category_code', self::CAT)->delete();
        DB::table('categories')->where('code', self::CAT)->delete();
        DB::table('locations')->where('code', self::LOC)->delete();
    }
}
