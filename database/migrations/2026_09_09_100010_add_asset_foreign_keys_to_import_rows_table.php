<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.10 / §20 — deferred FKs.
 *
 * `import_rows` dan `assets` saling merujuk:
 *   assets.import_row_id           -> import_rows.id   (dibuat di migration assets)
 *   import_rows.duplicate_of_asset_id -> assets.id     (di sini)
 *   import_rows.promoted_asset_id     -> assets.id     (di sini)
 *
 * Kedua FK ke `assets` ini ditambahkan setelah tabel `assets` tersedia, agar tidak ada
 * circular dependency saat CREATE TABLE. Relationship tidak dihilangkan (§20).
 * Kolom-nya sudah dibuat di migration 2026_09_09_100007; indexnya:
 *   promoted_asset_id  -> ix_import_rows_promoted (eksplisit)
 *   duplicate_of_asset_id -> index auto dari FK (schema_design.md §10.2)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->foreign('duplicate_of_asset_id', 'fk_import_rows_duplicate_asset')
                ->references('id')->on('assets')
                ->nullOnDelete();

            $table->foreign('promoted_asset_id', 'fk_import_rows_promoted_asset')
                ->references('id')->on('assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropForeign('fk_import_rows_duplicate_asset');
            $table->dropForeign('fk_import_rows_promoted_asset');
        });
    }
};
