<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.10 `import_rows` — staging: setiap baris Excel mentah + hasil parse +
 * status validasi, SEBELUM dipromosikan ke `assets`.
 *
 *  - `raw_payload` JSON NN — seluruh baris Excel asli, dipertahankan untuk audit (F9).
 *  - `duplicate_of_asset_id` (baris GAGAL — bentrok) vs `promoted_asset_id` (baris SUKSES —
 *    aset hasil) adalah dua relationship berbeda dan tetap dipisah (F9).
 *  - FK ke `assets` (duplicate_of_asset_id, promoted_asset_id) DITAMBAHKAN di migration
 *    2026_09_09_100010 (deferred) karena circular dependency assets <-> import_rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('row_number');            // nomor baris di spreadsheet sumber
            $table->json('raw_payload');                      // NN — baris asli utuh

            // hasil parse (kandidat)
            $table->char('location_code', 2)->nullable();
            $table->char('category_code', 2)->nullable();
            $table->char('subcategory_code', 3)->nullable();
            $table->string('sequence_no', 10)->nullable();    // string apa adanya, suffix huruf tetap (B2)
            $table->unsignedSmallInteger('asset_year')->nullable();

            // room matching (scoped by location_code di application layer)
            $table->string('room_raw_value', 150)->nullable();
            $table->unsignedBigInteger('matched_room_id')->nullable();
            $table->enum('room_match_method', ['exact_name', 'alias', 'none'])->nullable();

            // condition
            $table->string('condition_raw', 50)->nullable();  // flag mentah Excel, tidak dinormalisasi
            $table->enum('condition_parsed', ['baik', 'kurang_baik', 'rusak_berat'])->nullable();

            // validation
            $table->enum('validation_status', ['pending', 'valid', 'warning', 'error'])->default('pending');
            $table->json('validation_messages')->nullable();

            // outcome references (FK to assets added later — see class docblock)
            $table->unsignedBigInteger('duplicate_of_asset_id')->nullable();
            $table->unsignedBigInteger('promoted_asset_id')->nullable();
            $table->timestamp('promoted_at')->nullable();

            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number'], 'uq_import_rows_batch_row');
            $table->index('validation_status', 'ix_import_rows_status');
            $table->index('matched_room_id', 'ix_import_rows_matched_room');
            $table->index('promoted_asset_id', 'ix_import_rows_promoted');

            $table->foreign('import_batch_id', 'fk_import_rows_batch')
                ->references('id')->on('import_batches')
                ->cascadeOnDelete()
                ->restrictOnUpdate();

            $table->foreign('matched_room_id', 'fk_import_rows_matched_room')
                ->references('id')->on('rooms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
