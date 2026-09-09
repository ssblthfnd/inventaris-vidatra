<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.4 `rooms` — master ruangan aplikasi (bukan hard-code, P6).
 * Setiap ruangan milik satu lokasi. Nama sama di lokasi berbeda = master room berbeda (P10).
 * Lifecycle memakai `is_active` (soft-disable), BUKAN soft delete (§11.1).
 * Tidak ada room 'Lainnya' (B5) — seeding bukan bagian Tahap 3.
 *
 * `uq_rooms_location_id (location_code, id)` diperlukan sebagai target semua composite FK
 * yang masuk ke `rooms` (dari assets, room_aliases, mutation_logs) — §11 I3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->char('location_code', 2);
            $table->string('name', 100);
            $table->string('pic', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_code', 'name'], 'uq_rooms_location_name');
            $table->unique(['location_code', 'id'], 'uq_rooms_location_id');
            $table->index('is_active', 'ix_rooms_is_active');

            $table->foreign('location_code', 'fk_rooms_location')
                ->references('code')->on('locations')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
