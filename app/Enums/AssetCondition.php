<?php

namespace App\Enums;

/**
 * Physical condition of an asset — matches the `assets.condition`,
 * `mutation_logs.condition_before`, `mutation_logs.condition_after` and
 * `import_rows.condition_parsed` ENUM columns exactly.
 *
 * NULL is a valid stored value (condition unknown / not yet determined —
 * data/reference/schema_design.md §2.6, §12 D9) and is represented as a plain
 * `null`, not a case here.
 */
enum AssetCondition: string
{
    case Baik = 'baik';
    case KurangBaik = 'kurang_baik';
    case RusakBerat = 'rusak_berat';

    public function label(): string
    {
        return match ($this) {
            self::Baik => 'Baik',
            self::KurangBaik => 'Kurang Baik',
            self::RusakBerat => 'Rusak Berat',
        };
    }
}
