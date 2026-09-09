<?php

namespace App\Http\Requests\Api;

use App\Enums\AssetCondition;
use App\Models\Asset;
use App\Rules\SubcategoryInCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT|PATCH /api/assets/{asset}` (Tahap 5.4 §19).
 *
 * Partial update semantics (PATCH-style) for both verbs. `sequence_no` and
 * `asset_code` can never change. Written-off status changes go through the
 * dedicated `write-off` / `unwrite-off` endpoints, so those fields are `prohibited`
 * here (§8, §24).
 *
 * `prepareForValidation()` backfills the placement keys from the current asset so
 * the composite-subcategory and room↔location rules always see a complete picture.
 */
class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    protected function prepareForValidation(): void
    {
        $asset = $this->route('asset');
        if (! $asset instanceof Asset) {
            return;
        }

        $backfill = [];
        foreach (['location_code', 'category_code', 'subcategory_code'] as $key) {
            if (! $this->has($key)) {
                $backfill[$key] = $asset->{$key};
            }
        }
        if (! $this->has('room_id')) {
            $backfill['room_id'] = $asset->room_id;
        }

        $this->merge($backfill);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxYear = (int) date('Y') + 1;

        return [
            'sequence_no' => ['prohibited'],
            'asset_code' => ['prohibited'],
            'is_written_off' => ['prohibited'],
            'written_off_on' => ['prohibited'],
            'written_off_note' => ['prohibited'],

            'location_code' => ['required', 'string', Rule::exists('locations', 'code')->where('is_active', true)],
            'category_code' => ['required', 'string', Rule::exists('categories', 'code')->where('is_active', true)],
            'subcategory_code' => ['required', 'string', new SubcategoryInCategory($this->input('category_code'))],

            'asset_year' => ['sometimes', 'integer', "between:1980,{$maxYear}"],

            'room_id' => [
                'nullable', 'integer',
                Rule::exists('rooms', 'id')
                    ->where('is_active', true)
                    ->where('location_code', $this->input('location_code')),
            ],

            'condition' => ['sometimes', 'nullable', Rule::enum(AssetCondition::class)],

            'quantity' => ['sometimes', 'integer', 'in:1'],

            'brand_model' => ['sometimes', 'nullable', 'string', 'max:150'],
            'serial_no' => ['sometimes', 'nullable', 'string', 'max:150'],
            'material' => ['sometimes', 'nullable', 'string', 'max:80'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'funding_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'detail_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'capacity_note' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],

            // optional free-text attached to the mutation log when a relocation happens
            'mutation_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.in' => 'Quantity harus selalu 1 (tidak ada grouped asset).',
            'is_written_off.prohibited' => 'Gunakan endpoint write-off / unwrite-off untuk mengubah status penghapusan.',
            'written_off_on.prohibited' => 'Gunakan endpoint write-off / unwrite-off untuk mengubah status penghapusan.',
            'written_off_note.prohibited' => 'Gunakan endpoint write-off / unwrite-off untuk mengubah status penghapusan.',
        ];
    }
}
