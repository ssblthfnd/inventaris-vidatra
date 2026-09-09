<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.1 `locations` — master lokasi struktural (01 YAYASAN/PH, 02 SD,
 * 03 SMP, 04 SMA). Natural PK CHAR(2); leading zero significant — never an integer (P1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('code', 2);                 // PK — '01'..'04', leading zero significant
            $table->string('name', 50);
            $table->string('alias', 50)->nullable(); // e.g. 'PH' for '01'
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->primary('code');
            $table->unique('name', 'uq_locations_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
