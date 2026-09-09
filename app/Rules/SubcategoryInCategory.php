<?php

namespace App\Rules;

use App\Models\Subcategory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a `subcategory_code` against the COMPOSITE natural key
 * `(category_code, code)` — never a bare `exists:subcategories,code`, because the
 * code repeats across categories (schema_design.md §2.3, Tahap 5.4 §9).
 *
 * The subcategory must also be active. If the category context itself is missing or
 * invalid, this rule stays silent and lets the `category_code` rule report that.
 */
class SubcategoryInCategory implements ValidationRule
{
    public function __construct(private readonly ?string $categoryCode) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->categoryCode === null || $this->categoryCode === '') {
            return;
        }

        $exists = Subcategory::query()
            ->where('category_code', $this->categoryCode)
            ->where('code', $value)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            $fail("Subkategori [{$value}] tidak valid untuk kategori [{$this->categoryCode}] atau sedang non-aktif.");
        }
    }
}
