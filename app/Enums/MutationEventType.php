<?php

namespace App\Enums;

/**
 * Canonical audit/history event types (Tahap 5.8.8) — matches
 * `mutation_logs.event_type` exactly.
 *
 * This is the generic classification layered on top of the original (Tahap 5.4)
 * `mutation_logs.type` column, which stays exclusively a room-move marker
 * (`pindah_ruangan`, never anything else in practice). `event_type` is populated for
 * EVERY event the application records, room-moves included.
 *
 * BATCH_EDIT / BATCH_DELETE are used for every per-asset event a batch HTTP
 * operation produces — including a batch-driven room move, which would otherwise be
 * MOVE_ROOM individually. This lets a batch operation's events be told apart from
 * individual ones while `batch_operation_id` groups the ones from one request.
 */
enum MutationEventType: string
{
    case Create = 'CREATE';
    case Edit = 'EDIT';
    case MoveRoom = 'MOVE_ROOM';
    case WriteOff = 'WRITE_OFF';
    case UnwriteOff = 'UNWRITE_OFF';
    case SoftDelete = 'SOFT_DELETE';
    case Restore = 'RESTORE';
    case BatchEdit = 'BATCH_EDIT';
    case BatchDelete = 'BATCH_DELETE';

    /**
     * Tahap 6.5 — revert/undo. `Revert` for a single-mutation revert, `BatchRevert`
     * when reverting a whole batch-operation group at once (same "individual vs
     * batch" split as `Edit`/`BatchEdit`) — never the original event type again,
     * so a revert is never confused with the mutation it reverted.
     */
    case Revert = 'REVERT';
    case BatchRevert = 'BATCH_REVERT';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Dibuat',
            self::Edit => 'Diedit',
            self::MoveRoom => 'Pindah Ruangan',
            self::WriteOff => 'Write-off',
            self::UnwriteOff => 'Batal Write-off',
            self::SoftDelete => 'Dihapus (Trash)',
            self::Restore => 'Dipulihkan',
            self::BatchEdit => 'Edit Massal',
            self::BatchDelete => 'Hapus Massal',
            self::Revert => 'Revert',
            self::BatchRevert => 'Revert Massal',
        };
    }

    /**
     * Tahap 6.5 — whether Stage 6.5 implements a safe revert for this event type.
     * Deliberately conservative (per the stage's own instruction): `Create` is
     * excluded — undoing a creation isn't a field-level "restore old values"
     * operation (there IS no "before"), and forcing it through the generic
     * revert engine would be a poor, unrequested fit; `Revert`/`BatchRevert`
     * are excluded so a revert can never itself be reverted (no redo chain).
     * Every other event type has full before/after snapshots (Tahap 5.8.8) and
     * a well-defined inverse operation, so all of them are revertable.
     */
    public function isRevertable(): bool
    {
        return match ($this) {
            self::Edit, self::MoveRoom, self::WriteOff, self::UnwriteOff,
            self::SoftDelete, self::Restore, self::BatchEdit, self::BatchDelete => true,
            self::Create, self::Revert, self::BatchRevert => false,
        };
    }
}
