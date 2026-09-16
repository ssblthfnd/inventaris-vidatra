<?php

namespace App\Enums;

/**
 * Application access roles (matches the `users.role` ENUM column and
 * data/reference/schema_design.md §2.8 / §9).
 *
 *   admin       = full access — LEGACY. Kept for backward compatibility while
 *                 Stage 6.9 is rolled out; not automatically treated as
 *                 equivalent to SuperAdmin (see isAdmin()/canWriteInventory()
 *                 below, both left untouched by the Stage 6.9 R1 addition).
 *   operator    = read + assets / mutations / imports / room-alias management,
 *                 global (not location-scoped) — unchanged by Stage 6.9 R1.
 *   viewer      = read-only, global — unchanged by Stage 6.9 R1.
 *   super_admin = Stage 6.9 — intended eventual replacement for `admin`.
 *                 Global scope (Yayasan-level): App\Support\LocationScope
 *                 treats it as global. Existing `admin` accounts are NOT
 *                 migrated to this automatically (business decision, later
 *                 phase) and this case is not yet wired into the `admin`/
 *                 `operator` Gates in App\Providers\AuthServiceProvider.
 *   unit_admin  = Stage 6.9 — location-scoped admin, exactly one location in
 *                 {02, 03, 04} (SD/SMP/SMA — never 01/Yayasan). Multiple
 *                 unit_admin users may share the same location.
 *                 App\Support\LocationScope enforces this scope; NOTHING in
 *                 the request/controller layer grants this role inventory
 *                 access yet — that is deferred to a later Stage 6.9 phase.
 *
 * This is deliberately a small, type-safe enum — not a permission system.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';
    case SuperAdmin = 'super_admin';
    case UnitAdmin = 'unit_admin';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
            self::SuperAdmin => 'Super Admin',
            self::UnitAdmin => 'Unit Admin',
        };
    }

    /** Roles allowed to create/modify inventory data (assets, mutations, imports, aliases). */
    public function canWriteInventory(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** Roles allowed to manage users and master data structurally. */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
