<?php

namespace App\Import\Parsing;

/**
 * Deterministic parse result for one Excel data row.
 *
 * Identity components are kept EXACTLY as read (sequence_no is a string — never cast,
 * never zero-padded/stripped, Tahap 4 §7/§8). Descriptive fields keep raw values
 * (Tahap 4 §20 — the importer is not a data-cleaning tool).
 */
final class ParsedRow
{
    /** @param list<array{code:string,severity:string,field:?string,message:string}> $notes */
    public function __construct(
        public readonly int $rowNumber,
        public readonly ?string $locationCode,
        public readonly ?string $categoryCode,
        public readonly ?string $subcategoryCode,
        public readonly ?string $sequenceNo,
        public readonly ?int $assetYear,
        public readonly ?string $roomRawValue,
        public readonly string $conditionRaw,
        public readonly ?string $conditionParsed,
        public readonly bool $isWrittenOff,
        public readonly ?string $writtenOffOn,
        public readonly ?string $writtenOffNote,
        public readonly ?string $brandModel,
        public readonly ?string $serialNo,
        public readonly ?string $material,
        public readonly ?string $purchaseDate,
        public readonly ?string $fundingSource,
        public readonly ?string $detailType,
        public readonly ?string $capacityNote,
        public readonly int $quantity,
        public readonly ?string $notes,
        public readonly ?string $blockSubcategoryCode,
        public readonly array $parseNotes = [],
    ) {
    }

    /** Business identity string used for duplicate detection & reporting. */
    public function identityKey(): string
    {
        return implode('|', [
            (string) $this->locationCode,
            (string) $this->categoryCode,
            (string) $this->subcategoryCode,
            (string) $this->sequenceNo,
            (string) $this->assetYear,
        ]);
    }

    public function identityLabel(): string
    {
        return sprintf(
            '%s.%s.%s.%s.%s',
            $this->locationCode ?? '??',
            $this->categoryCode ?? '??',
            $this->subcategoryCode ?? '???',
            $this->sequenceNo ?? '?',
            $this->assetYear ?? '????',
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'location_code' => $this->locationCode,
            'category_code' => $this->categoryCode,
            'subcategory_code' => $this->subcategoryCode,
            'sequence_no' => $this->sequenceNo,
            'asset_year' => $this->assetYear,
            'room_raw_value' => $this->roomRawValue,
            'condition_raw' => $this->conditionRaw,
            'condition_parsed' => $this->conditionParsed,
            'is_written_off' => $this->isWrittenOff,
            'written_off_on' => $this->writtenOffOn,
            'written_off_note' => $this->writtenOffNote,
            'brand_model' => $this->brandModel,
            'serial_no' => $this->serialNo,
            'material' => $this->material,
            'purchase_date' => $this->purchaseDate,
            'funding_source' => $this->fundingSource,
            'detail_type' => $this->detailType,
            'capacity_note' => $this->capacityNote,
            'quantity' => $this->quantity,
            'notes' => $this->notes,
            'block_subcategory_code' => $this->blockSubcategoryCode,
            'parse_notes' => $this->parseNotes,
        ];
    }
}
