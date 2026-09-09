<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.2 `categories` — master kategori barang (01..06). Natural PK CHAR(2).
 * Nama final mengikuti ejaan data Excel aktual (mis. MEUBELAIR, bukan MEBEULAIR) — seeding
 * bukan bagian Tahap 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->char('code', 2);                 // PK — '01'..'06'
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->primary('code');
            $table->unique('name', 'uq_categories_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
