<?php

namespace App\Enums;

/**
 * Application access roles (matches the `users.role` ENUM column and
 * data/reference/schema_design.md §2.8 / §9).
 *
 *   admin    = full access
 *   operator = read + assets / mutations / imports / room-alias management
 *   viewer   = read-only
 *
 * This is deliberately a small, type-safe enum — not a permission system.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
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
