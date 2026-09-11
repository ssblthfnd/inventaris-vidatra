<?php

use App\Enums\MutationEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 5.8.8 — generic audit/history foundation on top of the existing (5.4)
 * `mutation_logs` table. Extends rather than replaces it (docs/api_convention.md,
 * schema_design.md §2.7): existing rows, columns, indexes and FKs are untouched.
 *
 *  - `type` (the legacy 5-value enum, only ever written as `pindah_ruangan`) becomes
 *    NULLABLE. It keeps its exact original meaning and values — going forward it is
 *    populated ONLY for events that are genuinely a room/location relocation (still
 *    `pindah_ruangan`, whether triggered individually or via a batch edit), exactly
 *    as before. New non-relocation event types (CREATE, EDIT, WRITE_OFF, ...) leave
 *    it NULL rather than being force-fit into a legacy category that doesn't
 *    describe them (e.g. `lainnya`) — that would fabricate meaning the column never
 *    had. `mutation_type` stays the public API's room-move filter, unchanged.
 *  - `event_type`: the new canonical, generic classification (CREATE / EDIT /
 *    MOVE_ROOM / WRITE_OFF / UNWRITE_OFF / SOFT_DELETE / RESTORE / BATCH_EDIT /
 *    BATCH_DELETE — {@see MutationEventType}). Nullable so hand-built
 *    test fixtures using an arbitrary legacy `type` aren't forced to supply one;
 *    every row the application itself writes always sets it.
 *  - `before_snapshot` / `after_snapshot`: full relevant-asset-state JSON (not just
 *    the fields that happened to change) so history can answer "what did this asset
 *    look like before/after" for ANY event type with one consistent shape.
 *  - `batch_operation_id`: shares one UUID across every per-asset event produced by
 *    a single batch HTTP request (batch create/edit/delete). NULL for individual
 *    operations. A UUID (not an incrementing int) so it can never collide across
 *    concurrent requests.
 *  - Backfill: every existing row has `type = 'pindah_ruangan'` (verified — recorder
 *    has only ever written that one value) and IS a room move, so `event_type =
 *    'MOVE_ROOM'` is backfilled deterministically for those rows. Nothing else about
 *    existing rows (timestamps, actors, room labels) is touched.
 */
return new class extends Migration
{
    private const EVENT_TYPES = [
        'CREATE', 'EDIT', 'MOVE_ROOM', 'WRITE_OFF', 'UNWRITE_OFF',
        'SOFT_DELETE', 'RESTORE', 'BATCH_EDIT', 'BATCH_DELETE',
    ];

    public function up(): void
    {
        // `type` NOT NULL -> NULLABLE. Raw SQL: no doctrine/dbal in this project, and
        // Blueprint::change() can't be used to widen an enum's nullability without it.
        DB::statement(
            'ALTER TABLE `mutation_logs` MODIFY `type` '.
            "ENUM('pindah_ruangan','perbaikan','penghapusan','perubahan_kondisi','lainnya') NULL"
        );

        Schema::table('mutation_logs', function (Blueprint $table) {
            $table->enum('event_type', self::EVENT_TYPES)->nullable()->after('type');
            $table->json('before_snapshot')->nullable()->after('notes');
            $table->json('after_snapshot')->nullable()->after('before_snapshot');
            $table->uuid('batch_operation_id')->nullable()->after('after_snapshot');

            $table->index('event_type', 'ix_mutation_logs_event_type');
            $table->index('batch_operation_id', 'ix_mutation_logs_batch_operation');
        });

        DB::table('mutation_logs')->where('type', 'pindah_ruangan')->update(['event_type' => 'MOVE_ROOM']);
    }

    public function down(): void
    {
        Schema::table('mutation_logs', function (Blueprint $table) {
            $table->dropIndex('ix_mutation_logs_event_type');
            $table->dropIndex('ix_mutation_logs_batch_operation');
            $table->dropColumn(['event_type', 'before_snapshot', 'after_snapshot', 'batch_operation_id']);
        });

        DB::statement(
            'ALTER TABLE `mutation_logs` MODIFY `type` '.
            "ENUM('pindah_ruangan','perbaikan','penghapusan','perubahan_kondisi','lainnya') NOT NULL"
        );
    }
};
