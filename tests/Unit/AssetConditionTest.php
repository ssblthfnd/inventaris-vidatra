<?php

namespace Tests\Unit;

use App\Enums\AssetCondition;
use PHPUnit\Framework\TestCase;

class AssetConditionTest extends TestCase
{
    public function test_values_match_the_database_enum_exactly(): void
    {
        $this->assertSame(
            ['baik', 'kurang_baik', 'rusak_berat'],
            array_map(fn (AssetCondition $c) => $c->value, AssetCondition::cases()),
        );
    }

    public function test_from_string(): void
    {
        $this->assertSame(AssetCondition::Baik, AssetCondition::from('baik'));
        $this->assertSame(AssetCondition::KurangBaik, AssetCondition::from('kurang_baik'));
        $this->assertSame(AssetCondition::RusakBerat, AssetCondition::from('rusak_berat'));
        $this->assertNull(AssetCondition::tryFrom('unknown'));
    }

    public function test_labels(): void
    {
        $this->assertSame('Baik', AssetCondition::Baik->label());
        $this->assertSame('Kurang Baik', AssetCondition::KurangBaik->label());
        $this->assertSame('Rusak Berat', AssetCondition::RusakBerat->label());
    }
}
