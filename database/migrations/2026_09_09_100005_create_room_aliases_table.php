<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.5 `room_aliases` — kamus "raw string Excel -> rooms.id",
 * SELALU scoped per lokasi (B7, P10).
 *
 *  - `location_code` NOT NULL.
 *  - UNIQUE(location_code, match_key)  — BUKAN UNIQUE(match_key). match_key tidak unik global.
 *  - composite FK (location_code, room_id) -> rooms(location_code, id) menjamin alias & room
 *    yang ditunjuknya berada di lokasi yang sama.
 *
 * Tidak ada auto-create room saat import; raw yang tak dikenal -> assets.room_id = NULL (B5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_aliases', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->char('location_code', 2);                 // NN — alias selalu milik satu lokasi
            $table->string('raw_value', 150);                 // string Excel apa adanya (audit)
            $table->string('match_key', 150);                 // UPPER(TRIM(collapse_ws(raw_value)))
            $table->unsignedBigInteger('room_id');
            $table->enum('source', ['tahap1_seed', 'manual'])->default('manual');
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // scoped-by-location uniqueness (B7) + lookup index for import matching
            $table->unique(['location_code', 'match_key'], 'uq_room_aliases_location_match_key');
            $table->index('room_id', 'ix_room_aliases_room_id');

            // composite FK first so its (location_code, room_id) index also serves the
            // single-column location FK below.
            $table->foreign(['location_code', 'room_id'], 'fk_room_aliases_room_location')
                ->references(['location_code', 'id'])->on('rooms')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('location_code', 'fk_room_aliases_location')
                ->references('code')->on('locations')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('room_id', 'fk_room_aliases_room')
                ->references('id')->on('rooms')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('created_by', 'fk_room_aliases_created_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_aliases');
    }
};
