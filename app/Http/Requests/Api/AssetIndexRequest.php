<?php

namespace App\Http\Requests\Api;

use App\Enums\AssetCondition;
use App\Models\Room;
use App\Models\Subcategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Query validation for `GET /api/assets` (Tahap 5.3, multi-value filters Tahap 5.8.1).
 *
 * ## Filter contract
 *
 * Seven filters accept **multiple values** using the natural Laravel array syntax
 * (`?category_code[]=02&category_code[]=03`):
 *
 *   location_code  category_code  subcategory_code  room_id
 *   condition      is_written_off  asset_year
 *
 * A bare scalar (`?category_code=02`) is still accepted for backward compatibility —
 * it is normalised to a one-element array in {@see prepareForValidation()}, so the
 * rest of the pipeline only ever sees arrays.
 *
 * Semantics: **OR within one filter, AND between filters.**
 *   ?category_code[]=02&category_code[]=03&condition[]=baik
 *     => (category 02 OR 03) AND (condition baik)
 *
 * ## Per-filter notes
 *
 *  - Duplicate values in one filter are rejected `422` (`distinct`) — the contract is
 *    explicit, not silently de-duplicated.
 *  - `condition[]` additionally accepts the sentinel `unknown` = `condition IS NULL`
 *    (the DB stores NULL for "condition not determined"; `unknown` is the same term the
 *    dashboard API already uses). `condition[]=baik&condition[]=unknown` =>
 *    `condition = 'baik' OR condition IS NULL`.
 *  - `is_written_off[]` accepts `1`/`0` (booleans). Passing both is valid and, after
 *    validation, is a **no-op** (equivalent to omitting the filter).
 *  - `subcategory_code[]` stays composite-aware — see {@see subcategoryPairs()}. Each
 *    value is either a bare code (`001`, resolved against `category_code[]` when given,
 *    otherwise against every category that owns that code) or a category-qualified
 *    `CC.SSS` pair (`02.001`). A bare `exists:subcategories,code` is never used —
 *    `code` is not globally unique (schema_design.md §3.2).
 *  - `room_id[]` combined with `location_code[]`: every room must belong to one of the
 *    selected locations, otherwise `422` (mirrors the write API's room↔location rule
 *    and the composite FK defence-in-depth).
 *
 * `per_page` is REJECTED above 100 (never silently clamped). `asset_year` accepts
 * 1980..currentYear+1.
 */
class AssetIndexRequest extends FormRequest
{
    /** Filters that accept multiple values. */
    private const MULTI = [
        'location_code', 'category_code', 'subcategory_code', 'room_id',
        'condition', 'is_written_off', 'asset_year',
    ];

    public function authorize(): bool
    {
        return true; // route middleware (auth:sanctum, auth.active, can:viewer) handles access
    }

    /**
     * Normalise every multi-value filter to a clean list before validation, so both
     * `?x=1` and `?x[]=1&x[]=2` reach the rules as an array, and blank entries
     * (`?x=`) are dropped.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (self::MULTI as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);
            $list = is_array($value) ? array_values($value) : [$value];
            $list = array_values(array_filter(
                $list,
                fn ($v): bool => $v !== null && $v !== '' && ! is_array($v),
            ));

            $merge[$key] = $list;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxYear = (int) date('Y') + 1;
        $conditions = array_map(fn (AssetCondition $c): string => $c->value, AssetCondition::cases());
        $conditions[] = 'unknown';

        return [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],

            'location_code' => ['array', $this->noDuplicates(), $this->allExist('locations', 'code')],
            'category_code' => ['array', $this->noDuplicates(), $this->allExist('categories', 'code')],

            'subcategory_code' => ['array', $this->noDuplicates(), $this->validSubcategories()],

            'room_id' => ['array', $this->noDuplicates(), $this->validRooms()],

            'condition' => ['array', $this->noDuplicates(), $this->allIn($conditions, 'condition')],

            'is_written_off' => ['array', $this->noDuplicates(), $this->allBoolean()],

            'asset_year' => ['array', $this->noDuplicates(), $this->allYears($maxYear)],

            'q' => ['string', 'max:100'],

            'sort' => ['string', Rule::in([
                'asset_code', 'asset_year', 'sequence_no', 'brand_model', 'serial_no', 'purchase_date', 'created_at',
            ])],
            'direction' => ['string', Rule::in(['asc', 'desc'])],
        ];
    }

    /* ------------------------------------------------------------------ rule closures
     * All closures fail on the *base* attribute (`condition`, not `condition.0`) so the
     * 422 `errors` shape stays identical to the single-value Tahap 5.3 contract.
     */

    private function noDuplicates(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $scalars = array_map(fn ($v): string => (string) $v, $value);
            if (count($scalars) !== count(array_unique($scalars))) {
                $fail("The {$attribute} filter must not contain duplicate values.");
            }
        };
    }

    private function allExist(string $table, string $column): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($table, $column): void {
            $values = array_filter((array) $value, fn ($v): bool => is_string($v) || is_int($v));
            if ($values === []) {
                return;
            }

            $found = DB::table($table)->whereIn($column, $values)->pluck($column)
                ->map(fn ($v): string => (string) $v)->all();

            foreach ($values as $v) {
                if (! in_array((string) $v, $found, true)) {
                    $fail("The selected {$attribute} is invalid.");

                    return;
                }
            }
        };
    }

    private function allIn(array $allowed, string $label): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($allowed): void {
            foreach ((array) $value as $v) {
                if (! in_array($v, $allowed, true)) {
                    $fail("The selected {$attribute} is invalid.");

                    return;
                }
            }
        };
    }

    private function allBoolean(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $valid = [true, false, 1, 0, '1', '0', 'true', 'false'];
            foreach ((array) $value as $v) {
                if (! in_array($v, $valid, true)) {
                    $fail("The {$attribute} filter only accepts 1 or 0.");

                    return;
                }
            }
        };
    }

    private function allYears(int $maxYear): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($maxYear): void {
            foreach ((array) $value as $v) {
                if (! is_numeric($v) || (int) $v != $v || (int) $v < 1980 || (int) $v > $maxYear) {
                    $fail("The {$attribute} filter must be between 1980 and {$maxYear}.");

                    return;
                }
            }
        };
    }

    private function validRooms(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $ids = array_values((array) $value);
            if ($ids === []) {
                return;
            }

            foreach ($ids as $id) {
                if (! is_numeric($id) || (int) $id != $id || (int) $id < 1) {
                    $fail('The selected room_id is invalid.');

                    return;
                }
            }

            $rooms = Room::query()->whereIn('id', $ids)->get(['id', 'name', 'location_code']);
            if ($rooms->count() !== count(array_unique(array_map('intval', $ids)))) {
                $fail('The selected room_id is invalid.');

                return;
            }

            $locations = $this->filterArray('location_code');
            if ($locations === []) {
                return;
            }

            foreach ($rooms as $room) {
                if (! in_array($room->location_code, $locations, true)) {
                    $fail("Room [{$room->name}] is not in the selected location.");

                    return;
                }
            }
        };
    }

    /**
     * Each entry is a bare subcategory `code` or a category-qualified `CC.SSS` pair.
     * A bare code must exist within `category_code[]` (when that filter is present) or
     * within any category otherwise; a qualified pair must exist exactly, and its
     * category must be among `category_code[]` when that filter is present.
     */
    private function validSubcategories(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $entries = array_values((array) $value);
            if ($entries === []) {
                return;
            }

            $categories = $this->filterArray('category_code');

            foreach ($entries as $entry) {
                if (! is_string($entry) && ! is_int($entry)) {
                    $fail('The selected subcategory_code is invalid.');

                    return;
                }

                [$categoryCode, $code] = $this->splitSubcategory((string) $entry);

                if ($categoryCode !== null) {
                    if ($categories !== [] && ! in_array($categoryCode, $categories, true)) {
                        $fail("Subcategory [{$entry}] is not in the selected category.");

                        return;
                    }

                    $exists = Subcategory::query()
                        ->where('category_code', $categoryCode)
                        ->where('code', $code)
                        ->exists();
                } else {
                    $exists = Subcategory::query()
                        ->where('code', $code)
                        ->when($categories !== [], fn ($q) => $q->whereIn('category_code', $categories))
                        ->exists();
                }

                if (! $exists) {
                    $fail($categories !== []
                        ? "No subcategory [{$entry}] exists in the selected category."
                        : "No subcategory with code [{$entry}] exists.");

                    return;
                }
            }
        };
    }

    /* ------------------------------------------------------------------ typed accessors */

    /**
     * A validated multi-value filter as a plain list of strings (empty when absent).
     *
     * @return array<int, string>
     */
    public function filterArray(string $key): array
    {
        if (! in_array($key, self::MULTI, true)) {
            return [];
        }

        return array_values(array_map(
            fn ($v): string => (string) $v,
            array_filter((array) $this->input($key, []), fn ($v): bool => $v !== null && $v !== ''),
        ));
    }

    /** @return array<int, string> */
    public function locationCodes(): array
    {
        return $this->filterArray('location_code');
    }

    /** @return array<int, string> */
    public function categoryCodes(): array
    {
        return $this->filterArray('category_code');
    }

    /** @return array<int, int> */
    public function roomIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->filterArray('room_id'))));
    }

    /** @return array<int, int> */
    public function assetYears(): array
    {
        return array_values(array_unique(array_map('intval', $this->filterArray('asset_year'))));
    }

    /**
     * The condition filter split into concrete enum values + whether NULL is wanted.
     *
     * @return array{values: array<int, string>, includeNull: bool}|null null when the filter is absent
     */
    public function conditionFilter(): ?array
    {
        $raw = $this->filterArray('condition');
        if ($raw === []) {
            return null;
        }

        return [
            'values' => array_values(array_filter($raw, fn (string $v): bool => $v !== 'unknown')),
            'includeNull' => in_array('unknown', $raw, true),
        ];
    }

    /**
     * `true` / `false` when exactly one status is selected; `null` when the filter is
     * absent OR both statuses are selected (which matches "all", i.e. a no-op).
     */
    public function writtenOffValue(): ?bool
    {
        $raw = $this->filterArray('is_written_off');
        if ($raw === []) {
            return null;
        }

        $bools = array_values(array_unique(array_map(
            fn (string $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            $raw,
        )));

        return count($bools) === 1 ? $bools[0] : null;
    }

    /**
     * The subcategory filter resolved to concrete `[category_code, subcategory_code]`
     * pairs — always category-aware, never a bare `WHERE subcategory_code IN (...)`.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function subcategoryPairs(): array
    {
        $entries = $this->filterArray('subcategory_code');
        if ($entries === []) {
            return [];
        }

        $categories = $this->categoryCodes();
        $bareCodes = [];
        $pairs = [];

        foreach ($entries as $entry) {
            [$categoryCode, $code] = $this->splitSubcategory($entry);

            if ($categoryCode !== null) {
                $pairs[$categoryCode.'|'.$code] = [$categoryCode, $code];
            } else {
                $bareCodes[] = $code;
            }
        }

        if ($bareCodes !== []) {
            Subcategory::query()
                ->whereIn('code', $bareCodes)
                ->when($categories !== [], fn ($q) => $q->whereIn('category_code', $categories))
                ->get(['category_code', 'code'])
                ->each(function (Subcategory $s) use (&$pairs): void {
                    $pairs[$s->category_code.'|'.$s->code] = [$s->category_code, $s->code];
                });
        }

        return array_values($pairs);
    }

    /**
     * `"02.001"` -> `['02', '001']`; `"001"` -> `[null, '001']`.
     *
     * @return array{0: string|null, 1: string}
     */
    private function splitSubcategory(string $entry): array
    {
        // A category-qualified pair is `<2-char category>.<up-to-3-char subcategory>`.
        // Bare subcategory codes never contain a dot (they are `char(3)` numeric codes).
        if (preg_match('/^([A-Za-z0-9]{2})\.([A-Za-z0-9]{1,3})$/', $entry, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [null, $entry];
    }

    /* ------------------------------------------------------------------ sort / paging */

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
