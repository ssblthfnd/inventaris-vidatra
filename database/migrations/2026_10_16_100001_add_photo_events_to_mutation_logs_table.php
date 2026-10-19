<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Foto Aset — widens `mutation_logs.event_type` with `PHOTO_ADDED` and
 * `PHOTO_DELETED`, the same raw `ALTER … MODIFY` pattern as the Tahap 6.5
 * migration (no doctrine/dbal; valid on MySQL 8.4 and MariaDB 10.11). The column
 * keeps its exact current definition otherwise: nullable, no default. Values are
 * appended at the END so every existing value keeps its ordinal position.
 *
 * A photo event is not an asset-field change: its before/after snapshots are the
 * same asset state, its `notes` carry a short metadata summary (slot, size,
 * dimensions — never image bytes, base64 or the private file path), and it is
 * never revertable (App\Enums\MutationEventType::isRevertable()).
 *
 * down() narrows the ENUM back ONLY when no row uses a photo event. Narrowing
 * with such rows present would make MySQL/MariaDB reject the ALTER in strict
 * mode — or, without strict mode, silently turn those values into '' — and
 * either way history would be damaged. Rather than rewrite or delete history,
 * the rollback stops with an explanation.
 */
return new class extends Migration
{
    private const EVENT_TYPES = [
        'CREATE', 'EDIT', 'MOVE_ROOM', 'WRITE_OFF', 'UNWRITE_OFF',
        'SOFT_DELETE', 'RESTORE', 'BATCH_EDIT', 'BATCH_DELETE',
        'REVERT', 'BATCH_REVERT',
        'PHOTO_ADDED', 'PHOTO_DELETED',
    ];

    private const PREVIOUS_EVENT_TYPES = [
        'CREATE', 'EDIT', 'MOVE_ROOM', 'WRITE_OFF', 'UNWRITE_OFF',
        'SOFT_DELETE', 'RESTORE', 'BATCH_EDIT', 'BATCH_DELETE',
        'REVERT', 'BATCH_REVERT',
    ];

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE `mutation_logs` MODIFY `event_type` '.
            "ENUM('".implode("','", self::EVENT_TYPES)."') NULL"
        );
    }

    public function down(): void
    {
        $photoEvents = DB::table('mutation_logs')
            ->whereIn('event_type', ['PHOTO_ADDED', 'PHOTO_DELETED'])
            ->count();

        if ($photoEvents > 0) {
            throw new RuntimeException(
                "Refusing to narrow `mutation_logs.event_type`: {$photoEvents} history row(s) use ".
                'PHOTO_ADDED/PHOTO_DELETED. Narrowing the ENUM would corrupt that history. '.
                'Keep this migration applied (rolling back the photo feature does not require it).'
            );
        }

        DB::statement(
            'ALTER TABLE `mutation_logs` MODIFY `event_type` '.
            "ENUM('".implode("','", self::PREVIOUS_EVENT_TYPES)."') NULL"
        );
    }
};
