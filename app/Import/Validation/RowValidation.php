<?php

namespace App\Import\Validation;

/**
 * Outcome of validating one staged row.
 *
 * @property-read list<array{code:string,severity:string,field:?string,message:string}> $messages
 */
final class RowValidation
{
    public const PENDING = 'pending';
    public const VALID = 'valid';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** @param list<array{code:string,severity:string,field:?string,message:string}> $messages */
    public function __construct(
        public readonly string $status,
        public readonly array $messages,
        public readonly ?int $duplicateOfAssetId = null,
    ) {
    }

    public function isPromotable(): bool
    {
        return $this->status === self::VALID || $this->status === self::WARNING;
    }

    /** @param list<array{code:string,severity:string,field:?string,message:string}> $messages */
    public static function fromMessages(array $messages, ?int $duplicateOfAssetId = null): self
    {
        $hasError = false;
        $hasWarning = false;
        foreach ($messages as $m) {
            if ($m['severity'] === 'error') {
                $hasError = true;
            } elseif ($m['severity'] === 'warning') {
                $hasWarning = true;
            }
        }

        $status = $hasError ? self::ERROR : ($hasWarning ? self::WARNING : self::VALID);

        return new self($status, array_values($messages), $duplicateOfAssetId);
    }
}
