<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tahap 6.9 R9.4-02 — `import_rows.room_match_method` must say where the CURRENT
 * `matched_room_id` came from. Two values are appended to the existing enum:
 *
 *  - `exact_name`   automatic exact-name match (RoomMatcher, unchanged)
 *  - `alias`        automatic `room_aliases` match (RoomMatcher, unchanged)
 *  - `none`         unresolved (unchanged)
 *  - `manual`       room picked by a user in the import room-mapping flow, for
 *                   this batch only (RoomMappingResolver, save_as_alias=false)
 *  - `manual_alias` room picked by a user AND backed by a permanent room alias
 *                   (RoomMappingResolver, save_as_alias=true)
 *
 * Raw `MODIFY` for the same reason as 2026_09_16_100000 (no doctrine/dbal, no
 * fluent "add enum value"). The new values are APPENDED so no stored value or its
 * internal index changes. No existing row is touched: automatic matches stay
 * `exact_name` / `alias`. Rows resolved manually BEFORE this migration were
 * written as `alias` by the old resolver and cannot be told apart reliably, so
 * they are left as-is rather than guessed.
 *
 * down(): the old resolver wrote `alias` for every manual mapping, so both new
 * values collapse back to `alias` before the enum shrinks.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `import_rows` MODIFY `room_match_method` '.
            "ENUM('exact_name','alias','none','manual','manual_alias') NULL"
        );
    }

    public function down(): void
    {
        DB::table('import_rows')
            ->whereIn('room_match_method', ['manual', 'manual_alias'])
            ->update(['room_match_method' => 'alias']);

        DB::statement(
            'ALTER TABLE `import_rows` MODIFY `room_match_method` '.
            "ENUM('exact_name','alias','none') NULL"
        );
    }
};
