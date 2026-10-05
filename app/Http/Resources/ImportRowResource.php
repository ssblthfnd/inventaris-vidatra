<?php

namespace App\Http\Resources;

use App\Models\ImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ImportRow $resource
 *
 * Read shape for one staged row (Tahap 6.1 preview). `validation_status` and
 * `validation_messages` are emitted verbatim from `import_rows` — this class never
 * recomputes or reinterprets them (see `App\Import\Validation\RowValidation` for
 * what the three real statuses mean).
 *
 * `is_duplicate` is a presentation-only convenience derived from the existing
 * message codes (`duplicate_in_batch` / `duplicate_existing_asset`) — the importer
 * itself has no separate "duplicate" status; a duplicate is always also an `error`.
 */
class ImportRowResource extends JsonResource
{
    private const DUPLICATE_CODES = ['duplicate_in_batch', 'duplicate_existing_asset'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $parsed = (array) ($row->raw_payload['parsed'] ?? []);
        $messages = (array) ($row->validation_messages ?? []);

        return [
            'id' => $row->id,
            'import_batch_id' => $row->import_batch_id,
            'row_number' => $row->row_number,

            'identity' => sprintf(
                '%s.%s.%s.%s.%s',
                $row->location_code ?? '??',
                $row->category_code ?? '??',
                $row->subcategory_code ?? '???',
                $row->sequence_no ?? '?',
                $row->asset_year ?? '????',
            ),
            'location_code' => $row->location_code,
            'category_code' => $row->category_code,
            'subcategory_code' => $row->subcategory_code,
            'sequence_no' => $row->sequence_no,
            'asset_year' => $row->asset_year,
            'brand_model' => $parsed['brand_model'] ?? null,
            'notes' => $parsed['notes'] ?? null,

            'room_raw_value' => $row->room_raw_value,
            'matched_room_id' => $row->matched_room_id,
            'matched_room_name' => $row->relationLoaded('matchedRoom') ? $row->matchedRoom?->name : null,
            'room_match_method' => $row->room_match_method,
            // Tahap 6.9 R9.4-11 — the manual mapping action behind matched_room_id
            // (manual / manual_alias only; NULL for automatic matches and unresolved rows).
            'room_mapping_resolution' => $this->roomMappingResolution($row),

            'condition_raw' => $row->condition_raw,
            'condition_parsed' => $row->condition_parsed?->value,

            'validation_status' => $row->validation_status,
            'validation_messages' => $messages,
            'is_duplicate' => collect($messages)->contains(fn (array $m) => in_array($m['code'], self::DUPLICATE_CODES, true)),
            'duplicate_of_asset_id' => $row->duplicate_of_asset_id,

            'promoted_asset_id' => $row->promoted_asset_id,
            'promoted_at' => $row->promoted_at?->toIso8601String(),
            'promoted_by' => $row->relationLoaded('promotedBy') && $row->promotedBy !== null
                ? ['id' => $row->promotedBy->id, 'name' => $row->promotedBy->name]
                : null,
        ];
    }

    /** @return array{id:int,method:string,resolved_by:array{id:int,name:string}|null,resolved_at:?string}|null */
    private function roomMappingResolution(ImportRow $row): ?array
    {
        if (! $row->relationLoaded('roomMappingResolution') || $row->roomMappingResolution === null) {
            return null;
        }

        $resolution = $row->roomMappingResolution;
        $resolvedBy = $resolution->relationLoaded('resolvedBy') ? $resolution->resolvedBy : null;

        return [
            'id' => $resolution->id,
            'method' => $resolution->method,
            'resolved_by' => $resolvedBy !== null ? ['id' => $resolvedBy->id, 'name' => $resolvedBy->name] : null,
            'resolved_at' => $resolution->resolved_at?->toIso8601String(),
        ];
    }
}
