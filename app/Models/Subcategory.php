<?php

namespace App\Models;

use Database\Factories\SubcategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master subkategori per kategori — data/reference/schema_design.md §2.3.
 *
 * Eloquent primary key is the surrogate `id`. The natural key `(category_code, code)`
 * is a DATABASE unique constraint (and the composite-FK target from `assets`), NOT an
 * Eloquent composite primary key. `code` alone is NOT unique across categories (P2).
 */
#[Fillable(['category_code', 'code', 'name', 'guide_name', 'is_active'])]
class Subcategory extends Model
{
    /** @use HasFactory<SubcategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_code', 'code');
    }

    /**
     * Assets that belong to THIS subcategory.
     *
     * Returns a query builder, NOT an Eloquent relation: an asset points at a
     * subcategory by the composite `(category_code, subcategory_code)` pair
     * (schema_design.md §3.2), which `hasMany` cannot express correctly — a
     * constrained `hasMany` would eager-load wrong results for a mixed collection.
     * Call it explicitly:  `$subcategory->assets()->get()` / `->count()` / `->paginate()`.
     *
     * @return Builder<Asset>
     */
    public function assets(): Builder
    {
        return Asset::query()
            ->where('category_code', $this->category_code)
            ->where('subcategory_code', $this->code);
    }
}
