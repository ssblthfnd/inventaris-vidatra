<?php

namespace App\Http\Requests\Api;

use App\Enums\AssetCondition;
use App\Models\Subcategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for `GET /api/assets` (Tahap 5.3).
 *
 * `per_page` is REJECTED above 100 (never silently clamped). `asset_year` accepts
 * 1980..currentYear+1 — the DB `CHECK` allows up to 2100 so the importer has a wide
 * accept range, but a *query* filter beyond next year is meaningless.
 */
class AssetIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware (auth:sanctum, auth.active, can:viewer) handles access
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxYear = (int) date('Y') + 1;

        return [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],

            'location_code' => ['string', Rule::exists('locations', 'code')],
            'category_code' => ['string', Rule::exists('categories', 'code')],
            'subcategory_code' => [
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // NOT `exists:subcategories,code` — `code` is not globally unique.
                    $categoryCode = $this->input('category_code');

                    $exists = Subcategory::query()
                        ->where('code', $value)
                        ->when($categoryCode, fn ($q) => $q->where('category_code', $categoryCode))
                        ->exists();

                    if (! $exists) {
                        $fail($categoryCode
                            ? "No subcategory [{$value}] exists in category [{$categoryCode}]."
                            : "No subcategory with code [{$value}] exists.");
                    }
                },
            ],
            'room_id' => ['integer', Rule::exists('rooms', 'id')],
            'condition' => [Rule::enum(AssetCondition::class)],
            'is_written_off' => ['boolean'],
            'asset_year' => ['integer', "between:1980,{$maxYear}"],

            'q' => ['string', 'max:100'],

            'sort' => ['string', Rule::in([
                'asset_code', 'asset_year', 'sequence_no', 'brand_model', 'serial_no', 'purchase_date', 'created_at',
            ])],
            'direction' => ['string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }

    public function sortColumn(): string
    {
        return $this->validated('sort') ?? 'asset_code';
    }

    public function sortDirection(): string
    {
        return $this->validated('direction') ?? 'asc';
    }
}
