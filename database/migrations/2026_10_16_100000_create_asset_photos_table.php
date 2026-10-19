<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto Aset — metadata for up to three photos per asset. The JPEG files
 * themselves live on the private disk (`storage/app/private/asset-photos/
 * {asset_id}/{uuid}.jpg`); this table never holds image bytes.
 *
 *  - `slot` 1..3: the per-asset position. `UNIQUE (asset_id, slot)` plus the
 *    CHECK below make the database itself refuse a fourth photo or two photos
 *    in one slot, on top of the row lock AssetPhotoService takes on the asset.
 *    CHECK constraints are enforced by MySQL ≥ 8.0.16 and MariaDB ≥ 10.2
 *    (schema_design.md §14.1); this project already relies on them.
 *  - `path` is the RELATIVE path on the private disk, unique, never exposed by
 *    any API resource.
 *  - `mime` is always `image/jpeg` (every upload is re-encoded).
 *  - `asset_id` FK RESTRICT, like `mutation_logs`: assets are only ever
 *    soft-deleted, and a hard delete must not silently drop photo rows while
 *    leaving their files behind — so no cascade.
 *  - `uploaded_by` FK SET NULL, the same rule as every other actor column.
 *  - Explicit `utf8mb4_unicode_ci` (never `utf8mb4_0900_ai_ci`) so the table is
 *    identical on MySQL 8.4 and MariaDB 10.11.
 *
 * down() refuses to drop the table while it still holds rows: dropping it would
 * orphan every stored file and lose which asset each photo belongs to, silently.
 * Remove the photos (or back up storage/app/private/asset-photos and delete the
 * rows deliberately) before rolling back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_photos', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');

            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedTinyInteger('slot');
            $table->string('path', 255);
            $table->string('mime', 32)->default('image/jpeg');
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'slot'], 'uq_asset_photos_asset_slot');
            $table->unique('path', 'uq_asset_photos_path');
            $table->index('uploaded_by', 'ix_asset_photos_uploaded_by');

            $table->foreign('asset_id', 'fk_asset_photos_asset')
                ->references('id')->on('assets')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign('uploaded_by', 'fk_asset_photos_uploaded_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        DB::statement('ALTER TABLE `asset_photos` ADD CONSTRAINT `chk_asset_photos_slot` CHECK (`slot` BETWEEN 1 AND 3)');
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_photos') && DB::table('asset_photos')->exists()) {
            throw new RuntimeException(
                'Refusing to drop `asset_photos`: it still holds photo rows. Dropping it would orphan the '.
                'stored files in storage/app/private/asset-photos. Back up that folder and remove the '.
                'photos deliberately before rolling back.'
            );
        }

        Schema::dropIfExists('asset_photos');
    }
};
