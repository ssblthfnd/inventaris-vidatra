<?php

namespace App\Http\Requests\Api;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT|PATCH /api/rooms/{room}` (Tahap 6.8.1, `can:admin`; Stage 6.9 R7
 * regated to `can:rooms.manage`).
 *
 * Partial-patch semantics (same `sometimes` idiom as {@see UpdateAssetRequest})
 * — a request can touch just `is_active` (deactivate/reactivate) without
 * resending `name`/`pic`/`notes`.
 *
 * `id` and `location_code` are `prohibited`, not just omitted: a room's
 * location is immutable through this API (the DB's own
 * `restrictOnUpdate()` FK would refuse it anyway once anything references
 * the room, but this gives a clean 422 instead of relying on that as the
 * only defense, and applies even to a brand-new, still-unreferenced room).
 * This also means a `unit_admin` can never use this field to transfer a room
 * to another unit — the field is rejected for every actor, not just them.
 *
 * Deactivating a room is NOT blocked by existing asset references — an
 * inactive room stays a valid, readable FK target for historical/active
 * assets (see RoomController's docblock); this request performs no
 * dependency check at all, by design.
 *
 * WHERE (is this room in the actor's own location) is checked in
 * `RoomController::update()` via `RoomPolicy`, not here — this class only
 * validates field shape, never authorization.
 */
class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:rooms.manage; location scope checked in RoomController via RoomPolicy
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        foreach (['pic', 'notes'] as $key) {
            if ($this->has($key)) {
                $value = trim((string) $this->input($key));
                $this->merge([$key => $value === '' ? null : $value]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Room $room */
        $room = $this->route('room');

        return [
            'id' => ['prohibited'],
            'location_code' => ['prohibited'],

            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('rooms', 'name')
                    ->where(fn ($query) => $query->where('location_code', $room->location_code))
                    ->ignore($room->id),
            ],
            'pic' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'location_code.prohibited' => 'Lokasi ruangan tidak dapat diubah.',
            'name.unique' => 'Ruangan dengan nama ini sudah ada di lokasi tersebut.',
        ];
    }
}
