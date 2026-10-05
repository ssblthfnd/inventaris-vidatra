<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 6.9 R9.4-11 — who promoted, next to the existing "when" columns.
 *
 *  - `import_batches.imported_by` pairs with `imported_at` (R9.4-09: the FIRST
 *    promotion that actually imported something, write-once). Both are written by
 *    the same guarded UPDATE in AssetPromoter, so a later idempotent promote never
 *    replaces either. `imported_at` keeps its meaning unchanged.
 *  - `import_rows.promoted_by` pairs with the existing per-row `promoted_at` — a
 *    batch can gain assets in a later promote (e.g. after a failed row's room is
 *    reactivated), possibly by another user. Written in the same UPDATE that
 *    claims the row, inside the asset-insert transaction.
 *
 * Both nullable, NULL on user delete (same as `uploaded_by`). NULL also means
 * "promoted by the CLI" (`inventory:promote` has no user) or "promoted before
 * this migration" — existing rows are not backfilled: `uploaded_by` is not
 * necessarily who promoted, so no actor is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('imported_by')->nullable()->after('imported_at');

            $table->foreign('imported_by', 'fk_import_batches_imported_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('promoted_by')->nullable()->after('promoted_at');

            $table->foreign('promoted_by', 'fk_import_rows_promoted_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropForeign('fk_import_rows_promoted_by');
            $table->dropColumn('promoted_by');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropForeign('fk_import_batches_imported_by');
            $table->dropColumn('imported_by');
        });
    }
};
