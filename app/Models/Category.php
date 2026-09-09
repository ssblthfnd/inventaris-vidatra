<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master kategori barang (`01`..`06`). Natural string primary key `code`
 * — data/reference/schema_design.md §2.2, §13.
 */
#[Fillable(['code', 'name', 'is_active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Subcategory, $this> */
    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class, 'category_code', 'code');
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_code', 'code');
    }

    /** @return HasMany<ImportBatch, $this> */
    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'category_code', 'code');
    }
}
