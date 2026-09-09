<?php

namespace App\Models;

use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Excel import run — 1 file / 1 sheet / 1 category (schema_design.md §2.9).
 *
 * `category_code` is nullable (known only after PARSE). `status` is left as a plain
 * string cast — the DB ENUM already constrains it; the Tahap 4 pipeline writes it via
 * the query builder. The staging tables and their rows are NOT hard-coded anywhere.
 *
 * NOTE: the schema has NO `location_code` / `subcategory_code` FK on `import_batches`,
 * so there is intentionally no `location()` / `subcategory()` relation here (see report).
 */
#[Fillable([
    'source_filename',
    'source_sheet',
    'category_code',
    'status',
    'total_rows',
    'valid_rows',
    'warning_rows',
    'error_rows',
    'imported_rows',
    'uploaded_by',
    'notes',
    'imported_at',
])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'warning_rows' => 'integer',
            'error_rows' => 'integer',
            'imported_rows' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_code', 'code');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<ImportRow, $this> */
    public function importRows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_batch_id');
    }
}
