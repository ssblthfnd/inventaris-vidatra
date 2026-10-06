<?php

namespace App\Http\Requests\Api;

use App\Http\Controllers\Api\RoomController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /api/rooms/entries` (Tahap 6.9 R10) — create 1..{@see MAX_ITEMS} rooms in
 * one atomic request: `{ "items": [ {location_code, name, pic, notes}, ... ] }`.
 *
 * Every item is validated with exactly the `POST /api/rooms` rules
 * ({@see StoreRoomRequest::roomFieldRules()}) against its OWN location — the
 * name stays unique per location, inactive rooms included (unchanged room
 * duplicate rules). Added here only: two items naming the same room in the same
 * location, compared case-insensitively like `uq_rooms_location_name`. Location
 * scope is checked afterward, for every row, in
 * {@see RoomController::storeEntries()}.
 *
 * Errors are keyed `items.N.field` (N = 0-based position in `items`).
 */
class StoreRoomEntriesRequest extends FormRequest
{
    public const MAX_ITEMS = 100;

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:rooms.manage; location scope checked in RoomController via RoomPolicy
    }

    /** Same trimming as {@see StoreRoomRequest}, per item (string values only). */
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['name']) && is_string($item['name'])) {
                $items[$index]['name'] = trim($item['name']);
            }
            foreach (['pic', 'notes'] as $key) {
                if (isset($item[$key]) && is_string($item[$key])) {
                    $value = trim($item[$key]);
                    $items[$index][$key] = $value === '' ? null : $value;
                }
            }
        }

        $this->merge(['items' => $items]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => Rule::forEach(function (mixed $item): array {
                if (! is_array($item)) {
                    return ['array'];
                }

                return StoreRoomRequest::roomFieldRules(
                    is_string($item['location_code'] ?? null) ? $item['location_code'] : null,
                );
            }),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Isi minimal satu ruangan.',
            'items.array' => 'Format daftar ruangan tidak valid.',
            'items.list' => 'Format daftar ruangan tidak valid.',
            'items.min' => 'Isi minimal satu ruangan.',
            'items.max' => 'Maksimal '.self::MAX_ITEMS.' ruangan per penyimpanan.',
            'items.*.array' => 'Format baris ruangan tidak valid.',
            'items.*.location_code.required' => 'Lokasi wajib dipilih.',
            'items.*.location_code.exists' => 'Lokasi tidak ditemukan atau tidak aktif.',
            'items.*.name.required' => 'Nama ruangan wajib diisi.',
            'items.*.name.max' => 'Nama ruangan maksimal :max karakter.',
            'items.*.name.unique' => 'Ruangan dengan nama ini sudah ada di lokasi tersebut.',
            'items.*.pic.max' => 'PIC maksimal :max karakter.',
            'items.*.notes.max' => 'Catatan maksimal :max karakter.',
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $items = $this->input('items');
                if (! is_array($items) || ! array_is_list($items)) {
                    return;
                }

                $rowsByName = [];
                foreach ($items as $index => $item) {
                    if (is_array($item) && is_string($item['location_code'] ?? null) && is_string($item['name'] ?? null) && $item['name'] !== '') {
                        $rowsByName[$item['location_code']."\x1f".mb_strtolower($item['name'], 'UTF-8')][] = $index;
                    }
                }

                foreach ($rowsByName as $indexes) {
                    if (count($indexes) < 2) {
                        continue;
                    }
                    foreach ($indexes as $index) {
                        $others = array_map(fn (int $i): int => $i + 1, array_values(array_diff($indexes, [$index])));
                        $validator->errors()->add(
                            "items.{$index}.name",
                            'Nama ruangan yang sama di lokasi ini juga diisi pada baris '.implode(', ', $others).'.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * The validated items, in request order.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return array_values($this->validated('items'));
    }

    /**
     * @return list<string> every distinct `location_code` the request targets
     */
    public function locationCodes(): array
    {
        return array_values(array_unique(array_column($this->items(), 'location_code')));
    }
}
