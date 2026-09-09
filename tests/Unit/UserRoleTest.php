<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_values_match_the_database_enum(): void
    {
        $this->assertSame(['admin', 'operator', 'viewer'], array_map(
            fn (UserRole $r) => $r->value,
            UserRole::cases(),
        ));
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
    }
}
