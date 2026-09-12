<?php

namespace App\Http\Resources;

use App\Models\MutationLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read MutationLog $resource
 *
 * Read shape for one mutation-history row (Tahap 5.5).
 *
 * HISTORICAL SNAPSHOT — the `from` / `to` room *labels* are the values captured
 * when the mutation happened and are emitted verbatim. They are NEVER re-resolved
 * from the current `rooms` master (a room can be renamed or disabled afterwards).
 * Likewise `condition_before` / `condition_after` are the event's own snapshots,
 * not the asset's current condition.
 *
 * The performer is the only *live* reference (current identity). Only `id` + `name`
 * are exposed — never email / role / `is_active`, and a since-deactivated performer
 * is still shown (history stays historical).
 *
 * Tahap 5.8.8 adds `event_type` / `before_snapshot` / `after_snapshot` /
 * `batch_operation_id` — purely additive, on top of the Tahap 5.5 shape above.
 * `mutation_type` keeps meaning exactly what it always did (only ever
 * `pindah_ruangan`, null for every other event); `event_type` is the generic
 * classification populated for every event, room moves included.
 */
class MutationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $log = $this->resource;

        return [
            'id' => $log->id,
            'mutation_type' => $log->type,
            'mutation_date' => $log->mutation_date?->toDateString(),

            'from' => [
                'location_code' => $log->from_location_code,
                'room_id' => $log->from_room_id,
                'room_label' => $log->from_room_label,
            ],
            'to' => [
                'location_code' => $log->to_location_code,
                'room_id' => $log->to_room_id,
                'room_label' => $log->to_room_label,
            ],

            'condition_before' => $log->condition_before?->value,
            'condition_after' => $log->condition_after?->value,

            'mutation_note' => $log->notes,

            'performed_by' => $this->performer($log->createdBy),

            'created_at' => $log->created_at?->toIso8601String(),

            // Tahap 5.8.8 — generic audit foundation, additive fields.
            'event_type' => $log->event_type?->value,
            'batch_operation_id' => $log->batch_operation_id,
            'before_snapshot' => $log->before_snapshot,
            'after_snapshot' => $log->after_snapshot,

            // Tahap 6.5 — revert/undo, additive fields.
            // `reverted_mutation_id`: set only on a REVERT/BATCH_REVERT row itself —
            // which original mutation it undid.
            'reverted_mutation_id' => $log->reverted_mutation_id,
            // `is_revertable`: structural only (supported event type + snapshots
            // present) — does NOT mean "not already reverted", see below.
            'is_revertable' => $log->isRevertable(),
            // `already_reverted`: sourced from the transient attribute
            // AssetMutationController::index() bulk-preloads onto each row to avoid
            // an N+1 query; conservatively `false` (not "unknown") for any OTHER
            // consumer of this resource (e.g. the dashboard's recent-mutations list)
            // that never preloads it — those callers show no revert action anyway.
            'already_reverted' => (bool) ($log->already_reverted ?? false),
            // `can_revert`: the single field the UI actually needs to decide whether
            // to show a "Revert" button at all.
            'can_revert' => $log->isRevertable() && ! ($log->already_reverted ?? false),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function performer(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name];
    }
}
