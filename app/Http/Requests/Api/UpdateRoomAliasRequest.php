<?php

namespace App\Http\Requests\Api;

use App\Import\Parsing\ValueNormalizer;
use App\Models\RoomAlias;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT|PATCH /api/room-aliases/{roomAlias}` (Tahap 6.8.2), `can:operator`.
 *
 * Partial-patch semantics, same `sometimes` idiom as
 * {@see UpdateRoomRequest}. `location_code` is
 * `prohibited` — an alias's location is immutable (the DB's composite FK
 * to `rooms(location_code, id)` requires it, same reasoning as a room's own
 * immutable location). `room_id`, when supplied, must belong to the alias's
 * EXISTING location — never the other way around, since `location_code`
 * itself can never change here.
 */
class UpdateRoomAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('raw_value')) {
            $this->merge(['raw_value' => trim((string) $this->input('raw_value'))]);
        }
        if ($this->has('notes')) {
            $notes = trim((string) $this->input('notes'));
            $this->merge(['notes' => $notes === '' ? null : $notes]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var RoomAlias $alias */
        $alias = $this->route('roomAlias');

        return [
            'id' => ['prohibited'],
            'location_code' => ['prohibited'],
            'match_key' => ['prohibited'],
            'created_by' => ['prohibited'],

            'raw_value' => [
                'sometimes', 'required', 'string', 'max:150',
                function ($attribute, $value, $fail) use ($alias) {
                    $key = ValueNormalizer::roomMatchKey($value);
                    $exists = RoomAlias::query()
                        ->where('location_code', $alias->location_code)
                        ->where('match_key', $key)
                        ->where('id', '!=', $alias->id)
                        ->exists();
                    if ($exists) {
                        $fail('Alias dengan nilai ini sudah ada di lokasi tersebut.');
                    }
                },
            ],

            // Same "may point to an inactive room" intent as StoreRoomAliasRequest —
            // scoped to the alias's own (immutable) location, never a different one.
            'room_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('rooms', 'id')->where('location_code', $alias->location_code),
            ],

            'source' => ['sometimes', Rule::in(['tahap1_seed', 'manual'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_code.prohibited' => 'Lokasi alias tidak dapat diubah.',
            'room_id.exists' => 'Ruangan tidak ditemukan di lokasi alias ini.',
        ];
    }
}
