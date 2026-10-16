<?php

namespace App\Services\Image;

/**
 * The result of {@see ImageSanitizer::sanitize()}: a freshly encoded baseline
 * JPEG (no EXIF/GPS or any other metadata of the original) and its real size.
 */
final class SanitizedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }

    public function mime(): string
    {
        return 'image/jpeg';
    }
}
