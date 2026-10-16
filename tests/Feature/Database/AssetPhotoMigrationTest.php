<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Foto Aset — the two photo migrations, up and down for real against the
 * isolated test database (`inventaris_vidatra_test`, enforced by
 * {@see TestCase::refreshApplication()}; never the dev or production DB).
 *
 * Same approach as Stage2AssetSourceMigrationTest: NOT RefreshDatabase and NO
 * `migrate:fresh` — only `migrate` (non-destructive), a rollback of exactly the
 * photo migrations (and anything newer), and own rows under dedicated codes
 * (`ZF`) that tearDown removes, always finishing fully migrated.
 *
 * Verified here on MySQL 8.4 only; MariaDB 10.11 is tested separately. The
 * information_schema assertions are written to hold on both servers: MariaDB
 * reports integer display widths (`bigint(20) unsigned`), a NULL default as the
 * string `NULL` and string defaults quoted (`'image/jpeg'`) — see
 * {@see self::normalizeType()} / {@see self::normalizeDefault()}, which remove
 * exactly those presentation differences and nothing else.
 */
class AssetPhotoMigrationTest extends TestCase
{
    private const PHOTO_MIGRATIONS = [
        '2026_10_16_100000_create_asset_photos_table',
        '2026_10_16_100001_add_photo_events_to_mutation_logs_table',
    ];

    private const LOC = 'ZF';

    private const CAT = 'ZF';

    private const SUB = '902';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
        $this->deleteOwnRows();
    }

    protected function tearDown(): void
    {
        $this->deleteOwnRows();
        Artisan::call('migrate', ['--force' => true]); // never leave the schema rolled back
        parent::tearDown();
    }

    /** Rolls back the photo migrations and every migration applied after them. */
    private function rollbackPhotoMigrations(): void
    {
        $firstId = DB::table('migrations')->where('migration', self::PHOTO_MIGRATIONS[0])->value('id');
        $steps = DB::table('migrations')->where('id', '>=', $firstId)->count();

        Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]);
    }

    /* ================================================================== schema */

    public function test_asset_photos_columns_types_and_collation(): void
    {
        $columns = collect(DB::select(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS def,
                    COLLATION_NAME AS coll, EXTRA AS extra
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            ['asset_photos'],
        ))->keyBy('name');

        $this->assertSame(['id', 'asset_id', 'slot', 'path', 'mime', 'size_bytes', 'width', 'height', 'uploaded_by', 'created_at', 'updated_at'], $columns->keys()->all());

        // [type, nullable, default] per column — type without display width, default unquoted
        $expected = [
            'id' => ['bigint unsigned', 'NO', null],
            'asset_id' => ['bigint unsigned', 'NO', null],
            'slot' => ['tinyint unsigned', 'NO', null],
            'path' => ['varchar(255)', 'NO', null],
            'mime' => ['varchar(32)', 'NO', 'image/jpeg'],
            'size_bytes' => ['int unsigned', 'NO', null],
            'width' => ['smallint unsigned', 'NO', null],
            'height' => ['smallint unsigned', 'NO', null],
            'uploaded_by' => ['bigint unsigned', 'YES', null],
            'created_at' => ['timestamp', 'YES', null],
            'updated_at' => ['timestamp', 'YES', null],
        ];
        foreach ($expected as $name => [$type, $nullable, $default]) {
            $this->assertSame($type, self::normalizeType($columns[$name]->type), "{$name} type");
            $this->assertSame($nullable, $columns[$name]->nullable, "{$name} nullability");
            $this->assertSame($default, self::normalizeDefault($columns[$name]->def), "{$name} default");
        }
        $this->assertStringContainsString('auto_increment', strtolower((string) $columns['id']->extra));
        $this->assertSame('utf8mb4_unicode_ci', $columns['path']->coll);
        $this->assertSame('utf8mb4_unicode_ci', $columns['mime']->coll);

        $table = DB::selectOne('SELECT TABLE_COLLATION AS coll, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['asset_photos']);
        $this->assertSame('utf8mb4_unicode_ci', $table->coll);
        $this->assertSame('InnoDB', $table->engine);
    }

    public function test_indexes_foreign_keys_and_check_constraint(): void
    {
        $unique = collect(DB::select(
            'SELECT INDEX_NAME AS name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 GROUP BY INDEX_NAME',
            ['asset_photos'],
        ))->pluck('cols', 'name');
        $this->assertSame('asset_id,slot', $unique['uq_asset_photos_asset_slot']);
        $this->assertSame('path', $unique['uq_asset_photos_path']);

        $fks = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name, DELETE_RULE AS on_delete, REFERENCED_TABLE_NAME AS ref FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['asset_photos'],
        ))->keyBy('name');
        $this->assertSame('assets', $fks['fk_asset_photos_asset']->ref);
        $this->assertSame('RESTRICT', $fks['fk_asset_photos_asset']->on_delete, 'no cascade: files must be cleaned up deliberately');
        $this->assertSame('users', $fks['fk_asset_photos_uploaded_by']->ref);
        $this->assertSame('SET NULL', $fks['fk_asset_photos_uploaded_by']->on_delete);

        $check = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'asset_photos' AND CONSTRAINT_NAME = 'chk_asset_photos_slot' AND CONSTRAINT_TYPE = 'CHECK'"
        );
        $this->assertSame(1, (int) $check->n);
    }

    public function test_event_type_enum_gains_the_photo_events_and_keeps_its_definition(): void
    {
        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS def FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutation_logs' AND COLUMN_NAME = 'event_type'"
        );

        $this->assertSame(
            "enum('CREATE','EDIT','MOVE_ROOM','WRITE_OFF','UNWRITE_OFF','SOFT_DELETE','RESTORE','BATCH_EDIT','BATCH_DELETE','REVERT','BATCH_REVERT','PHOTO_ADDED','PHOTO_DELETED')",
            self::normalizeType($column->type),
        );
        $this->assertSame('YES', $column->nullable);
        $this->assertNull(self::normalizeDefault($column->def), 'still no default (MySQL: null, MariaDB: the string NULL)');
    }

    /**
     * The normalizers only remove MariaDB/MySQL presentation differences: they
     * keep the base type, `unsigned`, varchar length and enum values, and a real
     * string default stays a string (even the literal text 'NULL').
     */
    public function test_schema_normalizers_remove_only_presentation_differences(): void
    {
        $this->assertSame('bigint unsigned', self::normalizeType('bigint(20) unsigned'));    // MariaDB
        $this->assertSame('bigint unsigned', self::normalizeType('bigint unsigned'));        // MySQL 8
        $this->assertSame('tinyint unsigned', self::normalizeType('tinyint(3) unsigned'));
        $this->assertSame('smallint', self::normalizeType('smallint(6)'));
        $this->assertSame('int unsigned', self::normalizeType('int(10) unsigned'));
        $this->assertSame('varchar(255)', self::normalizeType('varchar(255)'));
        $this->assertSame("enum('A','b')", self::normalizeType("enum('A','b')")); // enum values keep their case
        $this->assertNotSame(self::normalizeType('bigint unsigned'), self::normalizeType('bigint'));
        $this->assertNotSame(self::normalizeType('tinyint(3) unsigned'), self::normalizeType('smallint(5) unsigned'));

        $this->assertNull(self::normalizeDefault(null));                         // MySQL
        $this->assertNull(self::normalizeDefault('NULL'));                       // MariaDB
        $this->assertSame('image/jpeg', self::normalizeDefault("'image/jpeg'")); // MariaDB
        $this->assertSame('image/jpeg', self::normalizeDefault('image/jpeg'));   // MySQL
        $this->assertSame('NULL', self::normalizeDefault("'NULL'"));             // a real string default
        $this->assertSame("it's", self::normalizeDefault("'it''s'"));
    }

    /** Drops integer display widths (`bigint(20)` → `bigint`); nothing else — not even letter case. */
    private static function normalizeType(string $type): string
    {
        return (string) preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/i', '$1', trim($type));
    }

    /** NULL default: MySQL `null`, MariaDB the string `NULL`; string defaults: MariaDB quotes them. */
    private static function normalizeDefault(mixed $default): ?string
    {
        if ($default === null || $default === 'NULL') {
            return null;
        }

        $default = (string) $default;
        if (strlen($default) >= 2 && str_starts_with($default, "'") && str_ends_with($default, "'")) {
            return str_replace("''", "'", substr($default, 1, -1));
        }

        return $default;
    }

    /* ================================================================== constraints in action (rolled back) */

    public function test_constraints_reject_a_duplicate_slot_a_fourth_slot_and_a_duplicate_path(): void
    {
        DB::beginTransaction();
        try {
            $assetId = $this->seedAsset('9901');
            DB::table('asset_photos')->insert($this->photoRow($assetId, 1, 'a'));

            foreach ([
                'duplicate slot' => $this->photoRow($assetId, 1, 'b'),
                'slot 4' => $this->photoRow($assetId, 4, 'c'),
                'slot 0' => $this->photoRow($assetId, 0, 'd'),
                'duplicate path' => $this->photoRow($assetId, 2, 'a'),
            ] as $case => $row) {
                try {
                    DB::statement('SAVEPOINT photo_case');
                    DB::table('asset_photos')->insert($row);
                    $this->fail("{$case} was accepted");
                } catch (QueryException) {
                    DB::statement('ROLLBACK TO SAVEPOINT photo_case');
                }
            }

            $this->assertSame(1, DB::table('asset_photos')->where('asset_id', $assetId)->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_an_asset_with_photos_cannot_be_hard_deleted_and_user_removal_keeps_the_photo(): void
    {
        DB::beginTransaction();
        try {
            $assetId = $this->seedAsset('9902');
            $userId = DB::table('users')->insertGetId([
                'name' => 'Uji Foto', 'email' => 'uji-foto-'.uniqid().'@example.test', 'password' => 'x',
                'role' => 'operator', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('asset_photos')->insert($this->photoRow($assetId, 1, 'e') + ['uploaded_by' => $userId]);

            try {
                DB::statement('SAVEPOINT hard_delete');
                DB::table('assets')->where('id', $assetId)->delete();
                $this->fail('hard delete of an asset with photos was accepted');
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT hard_delete');
            }

            DB::table('users')->where('id', $userId)->delete();
            $this->assertNull(DB::table('asset_photos')->where('asset_id', $assetId)->value('uploaded_by'));
            $this->assertSame(1, DB::table('asset_photos')->where('asset_id', $assetId)->count());
        } finally {
            DB::rollBack();
        }
    }

    /* ================================================================== down / up */

    public function test_down_removes_only_what_up_added_and_up_restores_it(): void
    {
        $assetsColumns = Schema::getColumnListing('assets');
        $mutationColumns = Schema::getColumnListing('mutation_logs');

        $this->rollbackPhotoMigrations();

        $this->assertFalse(Schema::hasTable('asset_photos'));
        $type = DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutation_logs' AND COLUMN_NAME = 'event_type'")->t;
        $this->assertSame("enum('CREATE','EDIT','MOVE_ROOM','WRITE_OFF','UNWRITE_OFF','SOFT_DELETE','RESTORE','BATCH_EDIT','BATCH_DELETE','REVERT','BATCH_REVERT')", self::normalizeType($type));
        $this->assertSame($assetsColumns, Schema::getColumnListing('assets'));
        $this->assertSame($mutationColumns, Schema::getColumnListing('mutation_logs'));

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasTable('asset_photos'));
        $this->assertSame(
            self::PHOTO_MIGRATIONS,
            DB::table('migrations')->whereIn('migration', self::PHOTO_MIGRATIONS)->orderBy('id')->pluck('migration')->all(),
        );
    }

    public function test_down_refuses_to_narrow_the_enum_while_photo_history_exists(): void
    {
        $assetId = $this->seedAsset('9903'); // committed — the rollback runs outside any transaction
        DB::table('mutation_logs')->insert([
            'asset_id' => $assetId, 'type' => null, 'mutation_date' => now()->toDateString(),
            'event_type' => 'PHOTO_ADDED', 'notes' => 'Foto slot 1 ditambahkan (1×1, 1 KB)',
        ]);

        try {
            $this->rollbackPhotoMigrations();
            $this->fail('the rollback should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to narrow `mutation_logs.event_type`', $e->getMessage());
        }

        $this->assertSame('PHOTO_ADDED', DB::table('mutation_logs')->where('asset_id', $assetId)->value('event_type'), 'history untouched');
        $this->assertTrue(Schema::hasTable('asset_photos'));
    }

    public function test_down_refuses_to_drop_asset_photos_while_it_holds_rows(): void
    {
        $assetId = $this->seedAsset('9904');
        DB::table('asset_photos')->insert($this->photoRow($assetId, 1, 'f'));

        try {
            $this->rollbackPhotoMigrations();
            $this->fail('the rollback should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop `asset_photos`', $e->getMessage());
        }

        $this->assertSame(1, DB::table('asset_photos')->where('asset_id', $assetId)->count());
    }

    /* ================================================================== fixtures */

    private function seedAsset(string $seq): int
    {
        DB::table('locations')->insertOrIgnore(['code' => self::LOC, 'name' => 'Lokasi Uji Foto', 'is_active' => 1]);
        DB::table('categories')->insertOrIgnore(['code' => self::CAT, 'name' => 'Kategori Uji Foto', 'is_active' => 1]);
        DB::table('subcategories')->insertOrIgnore(['category_code' => self::CAT, 'code' => self::SUB, 'name' => 'Subkategori Uji Foto', 'is_active' => 1]);

        return DB::table('assets')->insertGetId([
            'location_code' => self::LOC, 'category_code' => self::CAT, 'subcategory_code' => self::SUB,
            'sequence_no' => $seq, 'asset_year' => 2024, 'quantity' => 1, 'is_written_off' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function photoRow(int $assetId, int $slot, string $name): array
    {
        return [
            'asset_id' => $assetId, 'slot' => $slot, 'path' => "asset-photos/{$assetId}/{$name}.jpg", 'mime' => 'image/jpeg',
            'size_bytes' => 1, 'width' => 1, 'height' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function deleteOwnRows(): void
    {
        if (Schema::hasTable('asset_photos')) {
            DB::table('asset_photos')->whereIn('asset_id', DB::table('assets')->where('location_code', self::LOC)->select('id'))->delete();
        }
        DB::table('mutation_logs')->whereIn('asset_id', DB::table('assets')->where('location_code', self::LOC)->select('id'))->delete();
        DB::table('assets')->where('location_code', self::LOC)->delete();
        DB::table('subcategories')->where('category_code', self::CAT)->delete();
        DB::table('categories')->where('code', self::CAT)->delete();
        DB::table('locations')->where('code', self::LOC)->delete();
    }
}
