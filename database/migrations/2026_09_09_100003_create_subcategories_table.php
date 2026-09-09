<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.3 `subcategories` — surrogate BIGINT PK + natural key
 * (category_code, code). Kode subkategori TIDAK unik lintas kategori (P2); pasangan
 * (category_code, code) adalah unique key & target composite FK dari `assets`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcategories', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->char('category_code', 2);
            $table->char('code', 3);                    // '001'.. — not globally unique (P2)
            $table->string('name', 120);               // dari header blok Excel (source of truth)
            $table->string('guide_name', 120)->nullable(); // nama versi Panduan (historis)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // natural key (P2) + referenced unique key for assets composite FK
            $table->unique(['category_code', 'code'], 'uq_subcategories_natural');
            // cegah dua subkategori bernama sama dalam satu kategori
            $table->unique(['category_code', 'name'], 'uq_subcategories_cat_name');

            $table->foreign('category_code', 'fk_subcategories_category')
                ->references('code')->on('categories')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcategories');
    }
};
