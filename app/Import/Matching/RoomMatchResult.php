<?php

namespace App\Import\Matching;

final class RoomMatchResult
{
    public const METHOD_EXACT_NAME = 'exact_name';
    public const METHOD_ALIAS = 'alias';
    public const METHOD_NONE = 'none';

    public function __construct(
        public readonly ?int $roomId,
        public readonly string $method,
        public readonly string $matchKey,
        public readonly ?string $rawValue,
    ) {
    }

    public function isMapped(): bool
    {
        return $this->roomId !== null;
    }
}
