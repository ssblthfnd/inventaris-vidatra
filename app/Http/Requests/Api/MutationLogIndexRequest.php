<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for `GET /api/assets/{asset}/mutations` (Tahap 5.5).
 *
 * Read-only. `mutation_type` is validated against the values the system actually
 * produces (Tahap 5.4 only ever writes `pindah_ruangan`). `per_page` is rejected
 * above 100 — never silently clamped, consistent with the asset list (§5.3).
 */
class MutationLogIndexRequest extends FormRequest
{
    /** Mutation types the system currently records — the filter whitelist. */
    public const KNOWN_TYPES = ['pindah_ruangan'];

    /** Columns a client may sort by (server-side whitelist). */
    public const SORTABLE = ['mutation_date', 'created_at', 'id'];

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:viewer
    }

    /**
     * Treat blank query params as absent so `?date_from=` is not a 422.
     */
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            'mutation_type' => ['nullable', 'string', Rule::in(self::KNOWN_TYPES)],
            'performed_by' => ['nullable', 'integer', Rule::exists('users', 'id')],

            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],

            'q' => ['nullable', 'string', 'max:100'],

            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['date_from', 'date_to'])) {
                return;
            }

            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if ($from !== null && $to !== null && $from > $to) {
                $validator->errors()->add('date_to', 'The date_to must be a date on or after date_from.');
            }
        });
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }

    public function sortColumn(): string
    {
        return $this->validated('sort') ?? 'mutation_date';
    }

    public function sortDirection(): string
    {
        return $this->validated('direction') ?? 'desc';
    }
}
