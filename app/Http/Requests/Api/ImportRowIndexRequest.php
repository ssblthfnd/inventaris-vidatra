<?php

namespace App\Http\Requests\Api;

use App\Import\Validation\RowValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query validation for `GET /api/imports/{batch}/rows` (Tahap 6.1).
 *
 * `status` mirrors `import_rows.validation_status` exactly (pending/valid/warning/
 * error) PLUS one UI-only convenience value, `duplicate` — the existing importer has
 * no separate "duplicate" status (a duplicate is an `error`-severity validation
 * message, {@see RowValidator}), so `duplicate` here filters
 * by the presence of a `duplicate_in_batch` / `duplicate_existing_asset` message
 * code rather than a different column. This does not change or add a status value
 * anywhere in the importer — see `ImportController::rows()`.
 */
class ImportRowIndexRequest extends FormRequest
{
    public const STATUSES = ['pending', 'valid', 'warning', 'error', 'duplicate'];

    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:operator
    }

    /**
     * Treat blank query params as absent, matching `MutationLogIndexRequest`'s
     * convention elsewhere in this API.
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
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?string
    {
        return $this->validated('status');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
