<?php

namespace App\Import\Excel;

/**
 * One physical row read from a worksheet.
 *
 * @property-read array<string,string> $cells      calculated values keyed by column letter (A..V)
 * @property-read array<string,string> $formulas   raw formula text for cells that are formulas
 */
final class ScannedRow
{
    public const TYPE_DATA = 'data';
    public const TYPE_BLOCK_HEADER = 'block_header';
    public const TYPE_SKIPPED = 'skipped';

    /**
     * @param array<string,string> $cells
     * @param array<string,string> $formulas
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $type,
        public readonly array $cells,
        public readonly array $formulas,
        public readonly ?string $blockSubcategoryCode = null,
        public readonly ?string $blockSubcategoryName = null,
    ) {
    }

    public function cell(string $col): string
    {
        return $this->cells[$col] ?? '';
    }
}
