<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/imports/{batch}/room-mappings/resolve` (Tahap 6.9 R9.2).
 *
 * Only SHAPE validation lives here (required/type). The business rules that
 * actually matter — actor's location scope, target room's own location and
 * active status, alias-conflict detection — all depend on live DB state at
 * the moment of the transaction (Bagian 5, concurrency), so they are
 * deliberately NOT re-derived here and instead live entirely in
 * {@see \App\Import\RoomMapping\RoomMappingResolver::resolve()}, exactly the
 * same split {@see StoreImportRequest} / {@see ImportManager} already use.
 *
 * `match_key` is never accepted from the client, same convention as
 * {@see StoreRoomAliasRequest} — it is always derived server-side from
 * `raw_value` via `ValueNormalizer::roomMatchKey()`.
 */
class ResolveRoomMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:assets.import; location scope checked in RoomMappingResolver
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('raw_value')) {
            $this->merge(['raw_value' => trim((string) $this->input('raw_value'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'match_key' => ['prohibited'],

            'location_code' => ['required', 'string', 'size:2'],
            'raw_value' => ['required', 'string', 'max:150'],
            'room_id' => ['required', 'integer'],
            'save_as_alias' => ['sometimes', 'boolean'],
        ];
    }

    public function saveAsAlias(): bool
    {
        return $this->boolean('save_as_alias');
    }
}
