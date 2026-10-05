<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 6.9 R9.4-11 — who resolved an import room mapping, and when.
 *
 * One row = one SUCCESSFUL `RoomMappingResolver::resolve()` call that mapped at
 * least one staged row. A single call maps a whole (location, match_key) group,
 * and one batch can hold several groups resolved by different users, so the
 * actor lives here once per action instead of being copied onto every row.
 * Written in the same transaction as the row updates — a denied or failed
 * resolve leaves nothing behind. Never updated afterwards.
 *
 *  - `method` mirrors the `import_rows.room_match_method` value the action wrote.
 *  - `room_alias_id` is the permanent alias backing a `manual_alias` action
 *    (created by it when `alias_created`, otherwise already existing).
 *  - `resolved_by` follows `import_batches.uploaded_by`: nullable, NULL on user
 *    delete, so history survives account removal.
 *
 * `import_rows.room_mapping_resolution_id` points each mapped row at the action
 * that mapped it (NULL for automatic matches and unresolved rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_room_mapping_resolutions', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');

            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->char('location_code', 2);
            $table->string('raw_value', 150);
            $table->string('match_key', 150);
            $table->unsignedBigInteger('room_id')->nullable();
            $table->enum('method', ['manual', 'manual_alias']);
            $table->unsignedBigInteger('room_alias_id')->nullable();
            $table->boolean('alias_created')->default(false);
            $table->unsignedInteger('affected_rows');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at');

            $table->index('import_batch_id', 'ix_import_room_mapping_resolutions_batch');

            $table->foreign('import_batch_id', 'fk_import_room_mapping_resolutions_batch')
                ->references('id')->on('import_batches')
                ->cascadeOnDelete()
                ->restrictOnUpdate();

            $table->foreign('room_id', 'fk_import_room_mapping_resolutions_room')
                ->references('id')->on('rooms')
                ->nullOnDelete();

            $table->foreign('room_alias_id', 'fk_import_room_mapping_resolutions_alias')
                ->references('id')->on('room_aliases')
                ->nullOnDelete();

            $table->foreign('resolved_by', 'fk_import_room_mapping_resolutions_resolved_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('room_mapping_resolution_id')->nullable()->after('room_match_method');

            $table->foreign('room_mapping_resolution_id', 'fk_import_rows_room_mapping_resolution')
                ->references('id')->on('import_room_mapping_resolutions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropForeign('fk_import_rows_room_mapping_resolution');
            $table->dropColumn('room_mapping_resolution_id');
        });

        Schema::dropIfExists('import_room_mapping_resolutions');
    }
};
