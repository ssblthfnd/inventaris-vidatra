<?php

use App\Enums\AssetSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2 — Sumber Aset. Adds `assets.asset_source`, a fixed category
 * ({@see AssetSource}) used for grouping, filtering and reporting.
 *
 *  - ENUM, the project's existing pattern for a fixed vocabulary (`condition`,
 *    `users.role`, `mutation_logs.event_type`). The value list is written out
 *    literally here (not read from the PHP enum) so this migration keeps
 *    describing exactly what it created even if the enum changes later.
 *  - `NOT NULL DEFAULT 'belum_diberi_keterangan'`: adding the column fills EVERY
 *    existing row — soft-deleted ones included — with "Belum diberi keterangan"
 *    in the same statement; no separate UPDATE, no history rows (this is not an
 *    asset mutation). The default is deliberately NOT `yayasan`: an asset whose
 *    source was never chosen must never silently read as Yayasan. Application
 *    validation requires an explicit choice on every create form; the default
 *    only covers rows written without one (pre-existing assets, raw inserts).
 *  - Explicit `utf8mb4_unicode_ci`: the dev DB's `assets` table predates PR #1
 *    and is still `utf8mb4_0900_ai_ci` (MySQL-only), while production (MariaDB
 *    10.11) is `utf8mb4_unicode_ci` — the column itself is the same on both.
 *  - `funding_source` (free text, "Keterangan Sumber") is not touched: not read,
 *    not copied, not cleared. `asset_code` (generated column) is not touched.
 *  - `ix_assets_asset_source` serves the `asset_source[]` filter and the
 *    per-source report/dashboard breakdowns, same as `ix_assets_condition`.
 *
 * down() drops only the index and the column added here. Rolling back DISCARDS
 * every source chosen after deployment (the column is gone; nothing else holds
 * that information) — never roll back without a backup and explicit approval.
 */
return new class extends Migration
{
    private const SOURCES = ['bosda', 'bosp', 'bostk', 'yayasan', 'lainnya', 'belum_diberi_keterangan'];

    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->enum('asset_source', self::SOURCES)
                ->charset('utf8mb4')
                ->collation('utf8mb4_unicode_ci')
                ->default('belum_diberi_keterangan')
                ->after('funding_source');

            $table->index('asset_source', 'ix_assets_asset_source');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('ix_assets_asset_source');
            $table->dropColumn('asset_source');
        });
    }
};
