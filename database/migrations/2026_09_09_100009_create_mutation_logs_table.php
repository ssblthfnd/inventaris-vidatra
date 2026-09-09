<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.7 `mutation_logs` — histori perubahan aset. APPEND-ONLY:
 * hanya `created_at` (NN, CURRENT_TIMESTAMP), TIDAK ada `updated_at` (F8), tidak ada soft delete.
 *
 *  - Integritas room/location via composite FK (from_location_code, from_room_id) ->
 *    rooms(location_code, id) dan pasangan `to_` (MATCH SIMPLE — di-skip bila ada kolom NULL).
 *  - Snapshot `from_room_label` / `to_room_label` dipertahankan (P8 — master room bisa
 *    di-rename / dinonaktifkan).
 *  - `asset_id` FK ON DELETE RESTRICT — histori tidak boleh yatim, aset dgn log tak bisa
 *    hard-delete (§11 I13).
 *  - CHECK opsional (D16): bila `*_room_id` diisi maka `*_location_code` wajib diisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutation_logs', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->enum('type', [
                'pindah_ruangan', 'perbaikan', 'penghapusan', 'perubahan_kondisi', 'lainnya',
            ]);
            $table->date('mutation_date');                       // tanggal kejadian fisik

            $table->char('from_location_code', 2)->nullable();
            $table->char('to_location_code', 2)->nullable();
            $table->unsignedBigInteger('from_room_id')->nullable();
            $table->unsignedBigInteger('to_room_id')->nullable();
            $table->string('from_room_label', 150)->nullable();  // snapshot nama ruangan saat kejadian
            $table->string('to_room_label', 150)->nullable();

            $table->enum('condition_before', ['baik', 'kurang_baik', 'rusak_berat'])->nullable();
            $table->enum('condition_after', ['baik', 'kurang_baik', 'rusak_berat'])->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();

            // append-only: created_at only, NN, DEFAULT CURRENT_TIMESTAMP — no updated_at
            $table->timestamp('created_at')->useCurrent();

            // --- indexes (schema_design.md §10.1) ---
            $table->index(['asset_id', 'mutation_date'], 'ix_mutation_logs_asset');
            $table->index('type', 'ix_mutation_logs_type');
            $table->index('from_room_id', 'ix_mutation_logs_from_room');
            $table->index('to_room_id', 'ix_mutation_logs_to_room');
            $table->index('performed_by', 'ix_mutation_logs_performed_by');
            $table->index('mutation_date', 'ix_mutation_logs_date');

            // --- foreign keys ---
            $table->foreign('asset_id', 'fk_mutation_logs_asset')
                ->references('id')->on('assets')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // composite FK first so its index covers the single-column location FK too.
            $table->foreign(['from_location_code', 'from_room_id'], 'fk_mutation_logs_from_room_location')
                ->references(['location_code', 'id'])->on('rooms')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['to_location_code', 'to_room_id'], 'fk_mutation_logs_to_room_location')
                ->references(['location_code', 'id'])->on('rooms')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('from_location_code', 'fk_mutation_logs_from_location')
                ->references('code')->on('locations')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('to_location_code', 'fk_mutation_logs_to_location')
                ->references('code')->on('locations')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('performed_by', 'fk_mutation_logs_performed_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        // --- CHECK constraints (D16 — env mendukung; guard MATCH SIMPLE composite FK) ---
        DB::statement('ALTER TABLE `mutation_logs` ADD CONSTRAINT `chk_mutation_logs_from_room_location` CHECK (`from_room_id` IS NULL OR `from_location_code` IS NOT NULL)');
        DB::statement('ALTER TABLE `mutation_logs` ADD CONSTRAINT `chk_mutation_logs_to_room_location` CHECK (`to_room_id` IS NULL OR `to_location_code` IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mutation_logs');
    }
};
