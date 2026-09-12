<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when asset-label generation is attempted before `INVENTORY_QR_BASE_URL`
 * is configured (Tahap 6.0). Renders its own clear JSON error rather than falling
 * through to the framework's generic 500 handler — printing a label with a fake or
 * localhost QR target is worse than refusing to generate one at all.
 */
class QrBaseUrlNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('URL QR inventaris belum dikonfigurasi.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
