<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 3 — extends the starter `users` table with the domain columns required by
 * data/reference/schema_design.md §2.8 (role + is_active). The starter auth table
 * itself is left untouched; this migration only adds columns and an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // schema_design.md §2.8 — role ENUM('admin','operator','viewer') NN DEFAULT 'viewer'
            $table->enum('role', ['admin', 'operator', 'viewer'])->default('viewer')->after('password');
            // schema_design.md §2.8 — is_active TINYINT(1) NN DEFAULT 1
            $table->boolean('is_active')->default(true)->after('role');

            // schema_design.md §10.1 — ix_users_role (role)
            $table->index('role', 'ix_users_role');
        });

        // schema_design.md "Target DBMS" — semua tabel domain memakai utf8mb4_0900_ai_ci.
        // `users` is domain table #8; align its collation with the other 9 domain tables.
        // Infra tables (sessions, password_reset_tokens, cache*, jobs*) are left at the
        // connection default per schema_design.md §13.2 (non-domain, out of scope).
        DB::statement('ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('ix_users_role');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
