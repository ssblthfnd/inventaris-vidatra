<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_values_match_the_database_enum(): void
    {
        // Stage 6.9 R1 appends `super_admin`/`unit_admin` after the original
        // three — the migration's ENUM column lists them in this exact order
        // so no existing stored row's value is disturbed.
        $this->assertSame(['admin', 'operator', 'viewer', 'super_admin', 'unit_admin'], array_map(
            fn (UserRole $r) => $r->value,
            UserRole::cases(),
        ));
    }

    public function test_stage_6_9_roles_exist_alongside_legacy_roles(): void
    {
        $this->assertInstanceOf(UserRole::class, UserRole::SuperAdmin);
        $this->assertInstanceOf(UserRole::class, UserRole::UnitAdmin);
        $this->assertInstanceOf(UserRole::class, UserRole::Admin);
        $this->assertInstanceOf(UserRole::class, UserRole::Operator);
        $this->assertInstanceOf(UserRole::class, UserRole::Viewer);

        $this->assertSame('super_admin', UserRole::SuperAdmin->value);
        $this->assertSame('unit_admin', UserRole::UnitAdmin->value);
    }

    /**
     * Stage 6.9 R1 critical invariant: adding the two new cases must not
     * silently make them satisfy the legacy admin/write checks that the
     * existing `admin`/`operator` Gates rely on.
     */
    public function test_new_roles_do_not_satisfy_legacy_admin_or_write_checks(): void
    {
        $this->assertFalse(UserRole::SuperAdmin->isAdmin());
        $this->assertFalse(UserRole::UnitAdmin->isAdmin());
        $this->assertFalse(UserRole::SuperAdmin->canWriteInventory());
        $this->assertFalse(UserRole::UnitAdmin->canWriteInventory());
    }

    public function test_can_write_inventory(): void
    {
        $this->assertTrue(UserRole::Admin->canWriteInventory());
        $this->assertTrue(UserRole::Operator->canWriteInventory());
        $this->assertFalse(UserRole::Viewer->canWriteInventory());
    }

    public function test_is_admin(): void
    {
        $this->assertTrue(UserRole::Admin->isAdmin());
        $this->assertFalse(UserRole::Operator->isAdmin());
        $this->assertFalse(UserRole::Viewer->isAdmin());
    }

    public function test_labels(): void
    {
        $this->assertSame('Administrator', UserRole::Admin->label());
        $this->assertSame('Operator', UserRole::Operator->label());
        $this->assertSame('Viewer', UserRole::Viewer->label());
        $this->assertSame('Super Admin', UserRole::SuperAdmin->label());
        $this->assertSame('Unit Admin', UserRole::UnitAdmin->label());
    }
}
