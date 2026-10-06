<?php

namespace App\Http\Requests\Api;

use App\Services\Asset\AssetWriteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /api/assets/entries` (Tahap 6.9 R10) — create 1..{@see MAX_ITEMS}
 * independent assets in one atomic request: `{ "items": [ {...}, ... ] }`.
 *
 * Every item is validated with exactly the `POST /api/assets` field rules
 * ({@see StoreAssetRequest::assetFieldRules()}), evaluated against the item's
 * OWN location/category. The one intentional difference is `sequence_no`:
 *
 *   - empty / null -> the server allocates the number (AssetNumberGenerator);
 *   - supplied     -> stored verbatim (trimmed only, the importer's rule), so an
 *                     existing inventory number can be entered. It must fit the
 *                     column (max 10) and may not contain `.`: `asset_code` joins
 *                     the identity with `.`, so a dot inside the sequence would make
 *                     the code ambiguous.
 *
 * `POST /api/assets` and `POST /api/assets/batch` keep rejecting `sequence_no`.
 *
 * Checked here: field rules and manual numbers repeated INSIDE the request.
 * Checked later, after authorization and under the numbering lock
 * ({@see AssetWriteService::createEntries()}): manual numbers that already
 * exist (active or soft-deleted) — so an actor never learns about numbers in a
 * location it may not create in.
 *
 * Errors are keyed `items.N.field` (N = 0-based position in `items`).
 */
class StoreAssetEntriesRequest extends FormRequest
{
    public const MAX_ITEMS = 100;

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:assets.create; location scope checked in AssetController
    }

    /**
     * Manual numbers are trimmed (as the importer does); an empty one means
     * "generate it". Nothing else is normalized.
     */
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (is_array($item) && array_key_exists('sequence_no', $item) && is_string($item['sequence_no'])) {
                $sequence = trim($item['sequence_no']);
                $items[$index]['sequence_no'] = $sequence === '' ? null : $sequence;
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
            'items.*' => Rule::forEach(function (mixed $item, string $attribute): array {
                if (! is_array($item)) {
                    return ['array'];
                }

                return [
                    'asset_code' => ['prohibited'],
                    'sequence_no' => ['nullable', 'string', 'max:10', 'not_regex:/\./'],
                    ...StoreAssetRequest::assetFieldRules(
                        self::stringOrNull($item['location_code'] ?? null),
                        self::stringOrNull($item['category_code'] ?? null),
                        $attribute.'.',
                    ),
                ];
            }),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Isi minimal satu aset.',
            'items.array' => 'Format daftar aset tidak valid.',
            'items.list' => 'Format daftar aset tidak valid.',
            'items.min' => 'Isi minimal satu aset.',
            'items.max' => 'Maksimal '.self::MAX_ITEMS.' aset per penyimpanan.',
            'items.*.array' => 'Format baris aset tidak valid.',
            'items.*.asset_code.prohibited' => 'asset_code dihasilkan oleh database, bukan client.',
            'items.*.sequence_no.string' => 'Nomor urut tidak valid.',
            'items.*.sequence_no.max' => 'Nomor urut maksimal 10 karakter.',
            'items.*.sequence_no.not_regex' => 'Nomor urut tidak boleh mengandung titik (.).',
            'items.*.location_code.required' => 'Lokasi wajib dipilih.',
            'items.*.location_code.exists' => 'Lokasi tidak ditemukan atau tidak aktif.',
            'items.*.category_code.required' => 'Kategori wajib dipilih.',
            'items.*.category_code.exists' => 'Kategori tidak ditemukan atau tidak aktif.',
            'items.*.subcategory_code.required' => 'Subkategori wajib dipilih.',
            'items.*.asset_year.required' => 'Tahun aset wajib diisi.',
            'items.*.asset_year.integer' => 'Tahun aset harus berupa angka.',
            'items.*.asset_year.between' => 'Tahun aset harus antara :min dan :max.',
            'items.*.room_id.exists' => 'Ruangan tidak valid, tidak aktif, atau bukan di lokasi aset ini.',
            'items.*.quantity.in' => 'Quantity harus selalu 1 (tidak ada grouped asset).',
        ];
    }

    /**
     * Two items naming the same identity (location, category, subcategory,
     * sequence, year) can never both be created. Compared case-insensitively,
     * as the `uq_assets_number` index (utf8mb4 *_ci collation) compares them.
     * Every conflicting row is flagged, with the other row numbers (1-based).
     *
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

                $rowsByIdentity = [];
                foreach ($items as $index => $item) {
                    $key = is_array($item) ? self::manualIdentityKey($item) : null;
                    if ($key !== null) {
                        $rowsByIdentity[$key][] = $index;
                    }
                }

                foreach ($rowsByIdentity as $indexes) {
                    if (count($indexes) < 2) {
                        continue;
                    }
                    foreach ($indexes as $index) {
                        $others = array_map(fn (int $i): int => $i + 1, array_values(array_diff($indexes, [$index])));
                        $validator->errors()->add(
                            "items.{$index}.sequence_no",
                            'Nomor inventaris yang sama juga diisi pada baris '.implode(', ', $others).'.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * The validated items, in request order (index = position in `items`).
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

    /** @param  array<string, mixed>  $item */
    private static function manualIdentityKey(array $item): ?string
    {
        $parts = [
            $item['location_code'] ?? null,
            $item['category_code'] ?? null,
            $item['subcategory_code'] ?? null,
            $item['sequence_no'] ?? null,
            $item['asset_year'] ?? null,
        ];

        foreach ($parts as $part) {
            if (! is_string($part) && ! is_int($part)) {
                return null;
            }
        }

        return mb_strtolower(implode("\x1f", array_map('strval', $parts)), 'UTF-8');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
