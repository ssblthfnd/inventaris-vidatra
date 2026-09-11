<?php

namespace App\Services\Asset;

use App\Enums\MutationEventType;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\User;

/**
 * The single writer of append-only `mutation_logs` rows (Tahap 5.4 §11, §33–§34;
 * generic audit foundation Tahap 5.8.8). Every mutation path in
 * {@see AssetWriteService} goes through here — never `MutationLog::create()`
 * directly from a controller or elsewhere — so history stays in one consistent
 * shape. Always called from inside the same DB transaction as the asset change
 * (see each `AssetWriteService` method), so a recorder failure rolls the asset
 * change back too.
 *
 * Two public entry points:
 *  - {@see self::recordRelocation()} — the original (Tahap 5.4) room/location-move
 *    recorder. Keeps writing the legacy `type='pindah_ruangan'` + dedicated
 *    from/to room-and-location columns exactly as before (§ backward compatibility),
 *    and additionally populates the generic `event_type`/`before_snapshot`/
 *    `after_snapshot`/`batch_operation_id` columns added in 5.8.8.
 *  - {@see self::record()} — generic recorder for every other canonical event
 *    (CREATE, EDIT, WRITE_OFF, UNWRITE_OFF, SOFT_DELETE, RESTORE, BATCH_DELETE).
 *    Leaves the legacy room/location columns NULL — those never applied outside an
 *    actual relocation, and fabricating values for them would misrepresent history.
 *
 * Both write the SAME full-relevant-asset-state JSON into `before_snapshot` /
 * `after_snapshot` via {@see self::snapshot()}, so every event type can answer
 * "what did this asset look like before/after" with one consistent shape — not just
 * the fields that happened to change.
 */
class AssetMutationRecorder
{
    /**
     * Full relevant-asset-state snapshot, used as both `before_snapshot` and
     * `after_snapshot` (captured at different points in time by the caller).
     * `room_name` is a historical label, not a live lookup — resolved from the
     * already-loaded `room` relation when present (bulk/batch callers preload it to
     * avoid N+1), otherwise a single fresh query.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'location_code' => $asset->location_code,
            'category_code' => $asset->category_code,
            'subcategory_code' => $asset->subcategory_code,
            'sequence_no' => $asset->sequence_no,
            'asset_year' => $asset->asset_year,
            'room_id' => $asset->room_id === null ? null : (int) $asset->room_id,
            'room_name' => $this->resolveRoomName($asset),
            'room_raw_value' => $asset->room_raw_value,
            'brand_model' => $asset->brand_model,
            'detail_type' => $asset->detail_type,
            'serial_no' => $asset->serial_no,
            'material' => $asset->material,
            'capacity_note' => $asset->capacity_note,
            'quantity' => $asset->quantity,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'funding_source' => $asset->funding_source,
            'condition' => $asset->condition?->value,
            'notes' => $asset->notes,
            'is_written_off' => $asset->is_written_off,
            'written_off_on' => $asset->written_off_on?->toDateString(),
            'written_off_note' => $asset->written_off_note,
            'deleted_at' => $asset->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Room/location relocation — event_type is MOVE_ROOM for an individual change,
     * or BATCH_EDIT when `$batchOperationId` is given (a batch-driven room move is
     * still, structurally, a batch edit — see the enum's own doc block).
     *
     * @param  array<string, mixed>  $before  full snapshot from {@see self::snapshot()}
     * @param  array<string, mixed>  $after  full snapshot from {@see self::snapshot()}
     */
    public function recordRelocation(
        Asset $asset,
        array $before,
        array $after,
        User $actor,
        ?string $note = null,
        ?string $batchOperationId = null,
    ): MutationLog {
        return $this->write([
            'asset_id' => $asset->id,
            'type' => 'pindah_ruangan',
            'mutation_date' => now()->toDateString(),
            'from_location_code' => $before['location_code'],
            'to_location_code' => $after['location_code'],
            'from_room_id' => $before['room_id'],
            'to_room_id' => $after['room_id'],
            'from_room_label' => $before['room_name'],
            'to_room_label' => $after['room_name'],
            'condition_before' => $before['condition'],
            'condition_after' => $after['condition'],
            'notes' => $note,
            'performed_by' => $actor->id,
            'event_type' => $batchOperationId !== null ? MutationEventType::BatchEdit : MutationEventType::MoveRoom,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'batch_operation_id' => $batchOperationId,
        ]);
    }

    /**
     * Generic event recorder for everything that isn't a room/location move: CREATE,
     * EDIT, WRITE_OFF, UNWRITE_OFF, SOFT_DELETE, RESTORE, BATCH_DELETE (and
     * BATCH_EDIT for a non-room-changing batch edit). Never touches the legacy
     * `type` / from-to room columns — those stay NULL for these event types.
     *
     * @param  ?array<string, mixed>  $before  null only for CREATE (no prior state)
     * @param  array<string, mixed>  $after
     */
    public function record(
        Asset $asset,
        MutationEventType $eventType,
        ?array $before,
        array $after,
        User $actor,
        ?string $batchOperationId = null,
        ?string $note = null,
    ): MutationLog {
        return $this->write([
            'asset_id' => $asset->id,
            'type' => null,
            'mutation_date' => now()->toDateString(),
            'condition_before' => $before['condition'] ?? null,
            'condition_after' => $after['condition'],
            'notes' => $note,
            'performed_by' => $actor->id,
            'event_type' => $eventType,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'batch_operation_id' => $batchOperationId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): MutationLog
    {
        return MutationLog::create($attributes);
    }

    private function resolveRoomName(Asset $asset): ?string
    {
        if ($asset->room_id === null) {
            return null;
        }

        if ($asset->relationLoaded('room')) {
            return $asset->room?->name;
        }

        return Room::query()->whereKey($asset->room_id)->value('name');
    }
}
