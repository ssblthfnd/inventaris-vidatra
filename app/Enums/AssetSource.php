<?php

namespace App\Enums;

/**
 * Sumber aset (Tahap 2 — Sumber Aset) — matches the `assets.asset_source` and
 * `import_rows.asset_source_override` ENUM columns exactly.
 *
 * A fixed category for grouping, filtering and reporting. Deliberately a
 * SEPARATE concept from the free-text `assets.funding_source` column (shown in
 * the UI as "Keterangan Sumber"): the two are never merged, copied or swapped.
 *
 * `BelumDiberiKeterangan` is a legitimate category, not an error and not a
 * synonym of `Yayasan` — an asset may be recorded before its funding source is
 * known. It is the database default and what every pre-existing asset received
 * when the column was added. Nothing in the application ever falls back to
 * `Yayasan`.
 *
 * The stored value (slug) is the source of truth; {@see label()} is display only.
 */
enum AssetSource: string
{
    case Bosda = 'bosda';
    case Bosp = 'bosp';
    case Bostk = 'bostk';
    case Yayasan = 'yayasan';
    case Lainnya = 'lainnya';
    case BelumDiberiKeterangan = 'belum_diberi_keterangan';

    /** The value every existing asset received and the DB default. */
    public const DEFAULT = self::BelumDiberiKeterangan;

    public function label(): string
    {
        return match ($this) {
            self::Bosda => 'BOSDA',
            self::Bosp => 'BOSP',
            self::Bostk => 'BOSTK',
            self::Yayasan => 'Yayasan',
            self::Lainnya => 'Lainnya',
            self::BelumDiberiKeterangan => 'Belum diberi keterangan',
        };
    }

    /**
     * Every stored value, in definition order (= the DB ENUM order, which is
     * also what `ORDER BY asset_source` sorts by on MySQL and MariaDB).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }

    /**
     * Lenient lookup for user/Excel input: trims, collapses internal whitespace
     * and compares case-insensitively against both the stored value and the UI
     * label, with `_` treated as a space — so `bosda`, ` BOSDA `, `Yayasan`,
     * `belum_diberi_keterangan` and `Belum  diberi KETERANGAN` all resolve.
     * Returns null for blank or unrecognised input; it never guesses a
     * "closest" category and never maps anything unknown to a default.
     */
    public static function fromInput(?string $input): ?self
    {
        $key = self::normalize($input);
        if ($key === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($key === self::normalize($case->value) || $key === self::normalize($case->label())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * For FormRequest `prepareForValidation()`: a recognisable string becomes its
     * canonical stored value; anything else (blank, unknown, non-string) is
     * returned unchanged so the request's own `required` / `Rule::enum` rules
     * reject it with a normal 422 — input is never coerced into a category.
     */
    public static function canonicalOrOriginal(mixed $input): mixed
    {
        if (! is_string($input)) {
            return $input;
        }

        return self::fromInput($input)?->value ?? $input;
    }

    private static function normalize(?string $input): string
    {
        $text = str_replace('_', ' ', (string) $input);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }
}
