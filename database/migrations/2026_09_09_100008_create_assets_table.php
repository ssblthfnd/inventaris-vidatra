<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.6 `assets` — tabel inti. 1 baris = 1 physical asset (P3, B3).
 *
 *  - Nomor aset disimpan sebagai 5 komponen terpisah (authoritative) + `asset_code`
 *    GENERATED ALWAYS AS (...) STORED (Opsi C, §5.2, F4). asset_code dihasilkan DB —
 *    tidak dibuat di application layer.
 *  - `sequence_no` VARCHAR(10) — string apa adanya, tanpa padding/cast/normalisasi (B2).
 *  - `asset_year` SMALLINT UNSIGNED — bagian identitas unik, BUKAN bagian scope generator (B1).
 *  - Identitas bisnis: UNIQUE(location_code, category_code, subcategory_code, sequence_no, asset_year).
 *  - Penempatan ruangan location-aware via composite FK (location_code, room_id) ->
 *    rooms(location_code, id). `room_id` NULL = belum terpetakan. Tidak ada `room_status` (F6).
 *  - Lifecycle: `deleted_at` (SoftDeletes) untuk koreksi/error record; `is_written_off` untuk
 *    penghapusan bisnis (§11.1). Nomor aset tidak di-reuse walau soft-deleted (UNIQUE tetap).
 *  - CHECK: sequence_no <> '' ; asset_year BETWEEN 1980 AND 2100 ; quantity = 1  (I5, I6, I19).
 *
 * FK ke `import_rows.id` dibuat di sini; FK balik dari import_rows -> assets dibuat di
 * migration 2026_09_09_100010 (deferred, circular dependency).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();

            // --- komponen nomor aset (authoritative) ---
            $table->char('location_code', 2);
            $table->char('category_code', 2);
            $table->char('subcategory_code', 3);
            $table->string('sequence_no', 10);              // string apa adanya (B2)
            $table->unsignedSmallInteger('asset_year');

            // --- turunan: rekonstruksi kanonik, separator titik (dihasilkan DB) ---
            $table->string('asset_code', 40)
                ->storedAs("concat_ws('.', location_code, category_code, subcategory_code, sequence_no, asset_year)");

            // --- penempatan ruangan (P6, P7, P10) ---
            $table->unsignedBigInteger('room_id')->nullable();      // NULL = belum terpetakan
            $table->string('room_raw_value', 150)->nullable();      // string Ruangan Excel apa adanya

            // --- kondisi & penghapusan bisnis ---
            $table->enum('condition', ['baik', 'kurang_baik', 'rusak_berat'])->nullable();
            $table->boolean('is_written_off')->default(false);
            $table->date('written_off_on')->nullable();
            $table->string('written_off_note', 255)->nullable();

            // --- atribut deskriptif (nullable, lintas kategori) ---
            $table->string('brand_model', 150)->nullable();
            $table->string('serial_no', 150)->nullable();
            $table->string('material', 80)->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('funding_source', 80)->nullable();
            $table->string('detail_type', 100)->nullable();        // khusus Elektronik
            $table->string('capacity_note', 100)->nullable();      // khusus Alat Kebersihan
            $table->unsignedInteger('quantity')->default(1);       // selalu 1 (B6) — CHECK di bawah
            $table->text('notes')->nullable();

            // --- audit & lifecycle ---
            $table->unsignedBigInteger('import_row_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // --- unique constraints ---
            $table->unique(
                ['location_code', 'category_code', 'subcategory_code', 'sequence_no', 'asset_year'],
                'uq_assets_number'
            );
            $table->unique('asset_code', 'uq_assets_asset_code');

            // --- indexes (schema_design.md §10.1) ---
            // ix_assets_location dihilangkan: location_code sudah leftmost prefix uq_assets_number
            // (Design §10.2 + Task §22 — hindari index redundan yang tercakup UNIQUE).
            $table->index('room_id', 'ix_assets_room_id');
            $table->index(['category_code', 'subcategory_code'], 'ix_assets_category_sub');
            $table->index('condition', 'ix_assets_condition');
            $table->index('is_written_off', 'ix_assets_written_off');
            $table->index('asset_year', 'ix_assets_asset_year');
            $table->index('deleted_at', 'ix_assets_deleted_at');

            // --- foreign keys ---
            // composite FK first so its (location_code, room_id) index also covers the
            // single-column location FK.
            $table->foreign(['location_code', 'room_id'], 'fk_assets_room')
                ->references(['location_code', 'id'])->on('rooms')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('location_code', 'fk_assets_location')
                ->references('code')->on('locations')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['category_code', 'subcategory_code'], 'fk_assets_subcategory')
                ->references(['category_code', 'code'])->on('subcategories')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('import_row_id', 'fk_assets_import_row')
                ->references('id')->on('import_rows')
                ->nullOnDelete();

            $table->foreign('created_by', 'fk_assets_created_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreign('updated_by', 'fk_assets_updated_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        // --- CHECK constraints (DB-enforced) — schema_design.md §2.6, §11 I5/I6/I19 ---
        DB::statement("ALTER TABLE `assets` ADD CONSTRAINT `chk_assets_sequence_no_not_empty` CHECK (`sequence_no` <> '')");
        DB::statement('ALTER TABLE `assets` ADD CONSTRAINT `chk_assets_asset_year_range` CHECK (`asset_year` BETWEEN 1980 AND 2100)');
        DB::statement('ALTER TABLE `assets` ADD CONSTRAINT `chk_assets_quantity_one` CHECK (`quantity` = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
