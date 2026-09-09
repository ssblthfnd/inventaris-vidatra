<?php

namespace App\Services\Asset;

use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\User;

/**
 * Writes append-only `mutation_logs` rows (Tahap 5.4 §11, §33–§34).
 *
 * Tahap 5.4 records a single mutation type: **pindah_ruangan** — a change of the
 * asset's location and/or room. It is always called from inside the same DB
 * transaction as the asset change, so a failure here rolls the asset change back too.
 *
 * Room *labels* are snapshotted (`from_room_label` / `to_room_label`) because the
 * master room can later be renamed or disabled (schema_design.md §2.7, P8).
 */
class AssetMutationRecorder
{
    /**
     * @param  array{location_code:?string,room_id:?int,condition:mixed}  $before
     * @param  array{location_code:?string,room_id:?int,condition:mixed}  $after
     */
    public function recordRelocation(Asset $asset, array $before, array $after, User $actor, ?string $note = null): MutationLog
    {
        return MutationLog::create([
            'asset_id' => $asset->id,
            'type' => 'pindah_ruangan',
            'mutation_date' => now()->toDateString(),
            'from_location_code' => $before['location_code'],
            'to_location_code' => $after['location_code'],
            'from_room_id' => $before['room_id'],
            'to_room_id' => $after['room_id'],
            'from_room_label' => $this->roomLabel($before['room_id']),
            'to_room_label' => $this->roomLabel($after['room_id']),
            'condition_before' => $before['condition'],
            'condition_after' => $after['condition'],
            'notes' => $note,
            'performed_by' => $actor->id,
        ]);
    }

    private function roomLabel(?int $roomId): ?string
    {
        if ($roomId === null) {
            return null;
        }

        return Room::query()->whereKey($roomId)->value('name');
    }
}
