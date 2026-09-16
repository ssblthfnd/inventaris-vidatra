<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 6.9 R1 — authorization foundation (audit-approved design, not yet enforced
 * anywhere). Adds the two pieces the rest of Stage 6.9 will build on:
 *
 *  1. `users.role` ENUM gains `super_admin` (future global-scope replacement for
 *     `admin`) and `unit_admin` (location-scoped) as two NEW values, appended after
 *     the existing three so no existing row's stored value is disturbed. `admin`,
 *     `operator`, `viewer` are kept exactly as-is — this is additive only.
 *  2. `users.location_code` (nullable, FK -> locations.code, RESTRICT on
 *     update/delete) — WHERE a user is scoped to. NULL means "global" for roles
 *     that are global (admin/super_admin/operator/viewer); `unit_admin` will
 *     require it non-null and in {02,03,04}, but that constraint is enforced in
 *     application code (App\Support\LocationScope / future request validation),
 *     not a DB CHECK — consistent with how this schema already validates
 *     everything else (see rooms/room_aliases FK+app-validation pattern).
 *
 * No existing row is touched: every current user keeps their existing role value
 * and gets location_code = NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL ENUM columns are altered in place via MODIFY — Schema::table()'s
        // fluent builder has no portable "add enum value" operation, and this repo
        // already uses a raw DB::statement() in the sibling migration that first
        // created this column (2026_09_09_100000) for the same reason (charset
        // conversion). Existing values keep their exact spelling and order; the
        // two new values are appended, so no stored row needs to change.
        DB::statement(
            'ALTER TABLE `users` MODIFY `role` '.
            "ENUM('admin','operator','viewer','super_admin','unit_admin') ".
            "NOT NULL DEFAULT 'viewer'"
        );

        Schema::table('users', function (Blueprint $table) {
            $table->char('location_code', 2)->nullable()->after('role');

            $table->foreign('location_code', 'fk_users_location')
                ->references('code')->on('locations')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('fk_users_location');
            $table->dropColumn('location_code');
        });

        DB::statement(
            'ALTER TABLE `users` MODIFY `role` '.
            "ENUM('admin','operator','viewer') ".
            "NOT NULL DEFAULT 'viewer'"
        );
    }
};
