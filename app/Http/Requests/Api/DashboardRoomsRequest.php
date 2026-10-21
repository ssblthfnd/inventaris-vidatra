<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for `GET /api/dashboard/rooms` — the Dashboard's "Aset per
 * Ruangan" table (search, location filter, sort, pagination done server-side).
 *
 *   q             room-name search (case-insensitive substring), max 100
 *   location_code one location code (must exist); still narrowed by LocationScope
 *   sort          `count` (most assets first, default) | `name` (A–Z)
 *   per_page      10 (default) | 25 — the two page sizes the UI offers
 *   page          1-based
 *
 * Blank params are treated as absent, so `?q=&location_code=` is not a 422.
 */
class DashboardRoomsRequest extends FormRequest
{
    public const SORTS = ['count', 'name'];

    public const PAGE_SIZES = [10, 25];

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:viewer
    }

    protected function prepareForValidation(): void
    {
        $nulled = [];
        foreach ($this->keys() as $key) {
            if ($this->input($key) === '') {
                $nulled[$key] = null;
            }
        }
        if ($nulled !== []) {
            $this->merge($nulled);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'location_code' => ['nullable', 'string', Rule::exists('locations', 'code')],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function search(): ?string
    {
        $q = trim((string) $this->validated('q'));

        return $q === '' ? null : $q;
    }

    public function locationCode(): ?string
    {
        return $this->validated('location_code');
    }

    public function sort(): string
    {
        return $this->validated('sort') ?? 'count';
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::PAGE_SIZES[0]);
    }
}
