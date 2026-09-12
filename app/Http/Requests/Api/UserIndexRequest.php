<?php

namespace App\Http\Requests\Api;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /api/users` (Tahap 6.4), `can:admin`.
 *
 * Deliberately small: the user table is expected to stay tiny (a handful of
 * staff accounts), so this is a plain `q` / `role` / `is_active` / pagination
 * filter set — no multi-value OR-within-filter machinery like
 * {@see AssetIndexRequest} (that complexity exists there because inventory
 * filters combine; nothing here needs to).
 */
class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
