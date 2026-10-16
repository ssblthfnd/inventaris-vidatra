<?php

namespace App\Services\Image;

use RuntimeException;

/**
 * An uploaded image the server will not store. The message is user-facing
 * (Indonesian) and safe to return as a 422 validation error: it never contains
 * a file path, a GD/libjpeg diagnostic or anything the client sent.
 * `reason` is a stable machine code for tests and logs.
 */
class ImageRejectedException extends RuntimeException
{
    public const NOT_AN_IMAGE = 'not_an_image';

    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    public const HEIC = 'heic';

    public const FILE_TOO_LARGE = 'file_too_large';

    public const DIMENSIONS_TOO_LARGE = 'dimensions_too_large';

    public const TOO_MANY_PIXELS = 'too_many_pixels';

    public const INSUFFICIENT_MEMORY = 'insufficient_memory';

    public const DECODE_FAILED = 'decode_failed';

    public const PROCESSING_FAILED = 'processing_failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
