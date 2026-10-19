<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2 — Sumber Aset, import preview correction. When a staged row's
 * `SUMBER` cell is blank or not a recognised category, the user can pick one
 * of the six categories in the preview instead of editing the workbook and
 * re-importing. That choice is stored HERE, on the staged row — never only in
 * the frontend, and never by rewriting `raw_payload` (which stays the verbatim
 * audit copy of what the workbook contained):
 *
 *  - `asset_source_override` — the chosen category, same value list as
 *    `assets.asset_source`. NULL = no correction; AssetPromoter then uses the
 *    parsed value, or "belum_diberi_keterangan" when there is none.
 *  - `asset_source_resolved_by` / `asset_source_resolved_at` — who picked it,
 *    and when (same audit shape as the room-mapping attribution, R9.4-11).
 *
 * All nullable, no backfill: every existing staged row simply has no correction.
 * down() drops only these three columns (and the FK); corrections made after
 * deployment are lost on rollback, already-promoted assets are unaffected.
 */
return new class extends Migration
{
    private const SOURCES = ['bosda', 'bosp', 'bostk', 'yayasan', 'lainnya', 'belum_diberi_keterangan'];

    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->enum('asset_source_override', self::SOURCES)
                ->charset('utf8mb4')
                ->collation('utf8mb4_unicode_ci')
                ->nullable()
                ->after('condition_parsed');
            $table->unsignedBigInteger('asset_source_resolved_by')->nullable()->after('asset_source_override');
            $table->timestamp('asset_source_resolved_at')->nullable()->after('asset_source_resolved_by');

            $table->foreign('asset_source_resolved_by', 'fk_import_rows_asset_source_resolved_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropForeign('fk_import_rows_asset_source_resolved_by');
            $table->dropColumn(['asset_source_override', 'asset_source_resolved_by', 'asset_source_resolved_at']);
        });
    }
};
