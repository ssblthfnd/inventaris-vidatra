<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 6.5 — Mutation Revert/Undo. Adds ONE nullable self-referencing column:
 * `reverted_mutation_id` (-> `mutation_logs.id`), and widens the `event_type`
 * ENUM (Tahap 5.8.8) with two new values: `REVERT`, `BATCH_REVERT` — the same
 * "individual vs batch" split every other event pair in that enum already
 * uses (EDIT/BATCH_EDIT, and BATCH_DELETE which has no individual counterpart
 * of its own name). A revert is a new, distinct kind of event — it must never
 * be confused with the event type it reverts, so reusing e.g. `EDIT` for a
 * revert-of-an-edit would misrepresent what actually happened.
 *
 * Why this is the minimal necessary schema change: the stage requires (a) a
 * revert to be identifiable as a revert and traceable to the mutation it
 * reverted, and (b) idempotency — a mutation must not be revertable twice.
 * `mutation_logs` has no generic metadata/context JSON column to piggy-back
 * on (`before_snapshot`/`after_snapshot` are specifically ASSET-state
 * snapshots — repurposing them to also carry mutation-log-about-itself
 * metadata would conflate two different concerns and break every existing
 * consumer of those two columns, e.g. `AssetHistorySection`'s diffing). A
 * plain nullable FK is the smallest change that satisfies both requirements
 * and follows the exact same pattern as every other FK already on this table.
 *
 * `UNIQUE` (not just an index): a revert-generated row's `reverted_mutation_id`
 * can point at a given original mutation AT MOST ONCE — this is a second,
 * DB-enforced line of defence for "a mutation must not be reverted repeatedly"
 * on top of the application-level row lock + idempotency check in
 * `MutationRevertService` (belt and braces: even a coding mistake or a future
 * direct-DB write cannot silently create two reverts of the same mutation).
 * `NULL` values are unrestricted by a MySQL UNIQUE index, so every ordinary
 * (non-revert) row — the overwhelming majority — is unaffected.
 *
 * `restrictOnDelete()`/`restrictOnUpdate()` matches every other FK on this
 * append-only table (e.g. `fk_mutation_logs_asset`) except `performed_by`
 * (`nullOnDelete`, since a user account can be deactivated/removed
 * independently of history) — a mutation log row is itself immutable and
 * never deleted, so this FK can never actually be exercised in practice, but
 * matching the table's own established constraint style is more consistent
 * than inventing a different rule for one column.
 */
return new class extends Migration
{
    private const EVENT_TYPES = [
        'CREATE', 'EDIT', 'MOVE_ROOM', 'WRITE_OFF', 'UNWRITE_OFF',
        'SOFT_DELETE', 'RESTORE', 'BATCH_EDIT', 'BATCH_DELETE',
        'REVERT', 'BATCH_REVERT',
    ];

    private const PREVIOUS_EVENT_TYPES = [
        'CREATE', 'EDIT', 'MOVE_ROOM', 'WRITE_OFF', 'UNWRITE_OFF',
        'SOFT_DELETE', 'RESTORE', 'BATCH_EDIT', 'BATCH_DELETE',
    ];

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `mutation_logs` MODIFY `event_type` '.
            "ENUM('".implode("','", self::EVENT_TYPES)."') NULL"
        );

        Schema::table('mutation_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('reverted_mutation_id')->nullable()->after('batch_operation_id');

            $table->unique('reverted_mutation_id', 'uq_mutation_logs_reverted_mutation_id');

            $table->foreign('reverted_mutation_id', 'fk_mutation_logs_reverted_mutation_id')
                ->references('id')->on('mutation_logs')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('mutation_logs', function (Blueprint $table) {
            $table->dropForeign('fk_mutation_logs_reverted_mutation_id');
            $table->dropUnique('uq_mutation_logs_reverted_mutation_id');
            $table->dropColumn('reverted_mutation_id');
        });

        DB::statement(
            'ALTER TABLE `mutation_logs` MODIFY `event_type` '.
            "ENUM('".implode("','", self::PREVIOUS_EVENT_TYPES)."') NULL"
        );
    }
};
