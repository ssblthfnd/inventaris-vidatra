<?php

namespace App\Http\Requests\Api;

use App\Enums\AssetCondition;
use App\Rules\SubcategoryInCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/assets` (Tahap 5.4 §16–§18, §25).
 *
 * The client never sends `sequence_no` or `asset_code` — both are `prohibited`
 * (explicit rejection, not silent drop). The server allocates the sequence and the
 * database generates `asset_code`.
 */
class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:assets.create
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // server-controlled — never accepted from the client
            'sequence_no' => ['prohibited'],
            'asset_code' => ['prohibited'],

            ...self::assetFieldRules($this->input('location_code'), $this->input('category_code')),
        ];
    }

    /**
     * The per-asset field rules, shared with `POST /api/assets/entries` (Tahap 6.9
     * R10, {@see StoreAssetEntriesRequest}) so one asset is validated the same way
     * whichever endpoint creates it. `$locationCode` / `$categoryCode` are the
     * asset's OWN values (the room↔location and subcategory↔category checks
     * depend on them); `$fieldPrefix` is prepended to the one cross-field
     * reference (`required_if`) so it points at the same asset inside an array.
     *
     * @return array<string, mixed>
     */
    public static function assetFieldRules(mixed $locationCode, mixed $categoryCode, string $fieldPrefix = ''): array
    {
        $maxYear = (int) date('Y') + 1;

        return [
            'location_code' => ['required', 'string', Rule::exists('locations', 'code')->where('is_active', true)],
            'category_code' => ['required', 'string', Rule::exists('categories', 'code')->where('is_active', true)],
            'subcategory_code' => ['required', 'string', new SubcategoryInCategory($categoryCode)],

            'asset_year' => ['required', 'integer', "between:1980,{$maxYear}"],

            'room_id' => [
                'nullable', 'integer',
                Rule::exists('rooms', 'id')
                    ->where('is_active', true)
                    ->where('location_code', $locationCode),
            ],

            'condition' => ['nullable', Rule::enum(AssetCondition::class)],

            'is_written_off' => ['sometimes', 'boolean'],
            'written_off_on' => ['nullable', 'date', 'before_or_equal:today', "required_if:{$fieldPrefix}is_written_off,true,1"],
            'written_off_note' => ['nullable', 'string', 'max:255'],

            'quantity' => ['sometimes', 'integer', 'in:1'],

            'brand_model' => ['nullable', 'string', 'max:150'],
            'serial_no' => ['nullable', 'string', 'max:150'],
            'material' => ['nullable', 'string', 'max:80'],
            'purchase_date' => ['nullable', 'date'],
            'funding_source' => ['nullable', 'string', 'max:80'],
            'detail_type' => ['nullable', 'string', 'max:100'],
            'capacity_note' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.in' => 'Quantity harus selalu 1 (tidak ada grouped asset).',
            'sequence_no.prohibited' => 'sequence_no ditentukan oleh server, bukan client.',
            'asset_code.prohibited' => 'asset_code dihasilkan oleh database, bukan client.',
        ];
    }
}
