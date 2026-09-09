<?php

namespace App\Models;

use App\Enums\AssetCondition;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Inventory asset — one row = one physical unit (schema_design.md §2.6, Tahap 4 B3).
 *
 * Key rules honoured here:
 *  - `asset_code` is a DB STORED GENERATED column
 *    (location.category.subcategory.sequence.year). It is NOT fillable, has no mutator,
 *    and is never assigned by application code — the database produces it.
 *  - `sequence_no` stays a STRING verbatim ('001', '0001', '005A', '0017B'); never cast
 *    to int (Tahap 4 B2).
 *  - `quantity` is fixed at 1 (DB `CHECK (quantity = 1)`); grouped assets are unsupported.
 *  - The FK to `subcategories` is composite `(category_code, subcategory_code)`; see the
 *    `subcategory` accessor below.
 *  - `deleted_at` (SoftDeletes) = an erroneous record. Business disposal uses
 *    `is_written_off` — the row stays queryable.
 */
#[Fillable([
    'location_code',
    'category_code',
    'subcategory_code',
    'sequence_no',
    'asset_year',
    'room_id',
    'room_raw_value',
    'condition',
    'is_written_off',
    'written_off_on',
    'written_off_note',
    'brand_model',
    'serial_no',
    'material',
    'purchase_date',
    'funding_source',
    'detail_type',
    'capacity_note',
    'quantity',
    'notes',
    'import_row_id',
    'created_by',
    'updated_by',
])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'is_written_off' => false,
    ];

    protected function casts(): array
    {
        return [
            // sequence_no: intentionally NOT cast — must stay a string verbatim.
            // asset_code: intentionally NOT cast — read-only DB generated column.
            'asset_year' => 'integer',
            'quantity' => 'integer',
            'is_written_off' => 'boolean',
            'condition' => AssetCondition::class,
            'purchase_date' => 'date',
            'written_off_on' => 'date',
        ];
    }

    /* ------------------------------------------------------------------ relations */

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_code', 'code');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_code', 'code');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /** @return BelongsTo<ImportRow, $this> */
    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'import_row_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<MutationLog, $this> */
    public function mutationLogs(): HasMany
    {
        return $this->hasMany(MutationLog::class, 'asset_id');
    }

    /**
     * The subcategory this asset belongs to.
     *
     * NOT a `belongsTo` relation: the DB FK is composite
     * `(category_code, subcategory_code) -> subcategories(category_code, code)` and
     * `subcategory_code` alone is not unique across categories (schema_design.md §3.2).
     * This accessor resolves the exact subcategory; it is cached per instance but is
     * NOT eager-loadable (`with('subcategory')` will not work — the service layer joins
     * explicitly when it needs to). Property access works: `$asset->subcategory`.
     *
     * @return Attribute<?Subcategory, never>
     */
    protected function subcategory(): Attribute
    {
        return Attribute::get(fn (): ?Subcategory => Subcategory::query()
            ->where('category_code', $this->category_code)
            ->where('code', $this->subcategory_code)
            ->first())->shouldCache();
    }
}
