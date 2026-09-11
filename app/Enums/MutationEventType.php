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
        };
    }
}
