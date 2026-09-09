<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schema_design.md §2.9 `import_batches` — staging: 1 baris = 1 proses import 1 file/sheet
 * = 1 kategori aset. `category_code` sengaja NULLABLE (§2.9, F10): batch dibuat saat UPLOAD
 * sebelum PARSE memastikan kategori; aturan "1 batch = 1 kategori" ditegakkan di layer
 * validasi (bukan NOT NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_ai_ci');

            $table->id();
            $table->string('source_filename', 255);
            $table->string('source_sheet', 100)->nullable();
            $table->char('category_code', 2)->nullable();
            $table->enum('status', [
                'uploaded', 'validating', 'validated',
                'partially_imported', 'imported', 'failed', 'rolled_back',
            ])->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('status', 'ix_import_batches_status');

            $table->foreign('category_code', 'fk_import_batches_category')
                ->references('code')->on('categories')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('uploaded_by', 'fk_import_batches_uploaded_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
