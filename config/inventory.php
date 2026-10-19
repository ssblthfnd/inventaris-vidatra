<?php

return [

    /*
    |--------------------------------------------------------------------------
    | QR base URL (Tahap 6.0)
    |--------------------------------------------------------------------------
    |
    | The stable public base URL that printed asset-label QR codes point to:
    |   {INVENTORY_QR_BASE_URL}/a/{asset->id}
    |
    | A stable production domain does not exist yet. This is intentionally NOT
    | hard-coded and NOT defaulted to "http://localhost" — a printed label is a
    | physical, long-lived artifact, so generating one against a placeholder
    | domain would silently produce a QR code that can never resolve correctly.
    | Label generation must fail with a clear error instead (see
    | App\Exceptions\QrBaseUrlNotConfiguredException) until this is set.
    |
    | Local/automated testing uses its own non-localhost placeholder value set in
    | phpunit.xml (INVENTORY_QR_BASE_URL) — never printed on a production label.
    |
    */
    'qr_base_url' => env('INVENTORY_QR_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Label physical dimensions (Tahap 6.0, finalized in Tahap 6.0.1, print-size
    | options added in Tahap 6.0.2)
    |--------------------------------------------------------------------------
    |
    | Millimeters. Three PREDEFINED physical print sizes — 'small' is the exact
    | Tahap 6.0.1 55x15mm design, 'medium'/'large' scale the same layout up. The
    | frontend only ever sends one of these three keys (never arbitrary width/
    | height) — the backend is the source of truth for physical size (§6.0.2 §7).
    |
    | The single-label PDF page is exactly the chosen size with NO outer margin:
    | the label's border sits flush against the physical page edge (see
    | AssetLabelPdfService::labelLayout() — there is no page-level margin
    | constant for the single-label case). Batch A4 layout has its own, separate
    | page-margin/gap constants (AssetLabelPdfService::PAGE_MARGIN_MM etc.) —
    | those never change the label's own physical size.
    |
    */
    'label' => [
        'default_size' => 'small',

        'sizes' => [
            'small' => ['width_mm' => 55.0, 'height_mm' => 15.0],
            'medium' => ['width_mm' => 70.0, 'height_mm' => 20.0],
            'large' => ['width_mm' => 90.0, 'height_mm' => 25.0],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset photos (Foto Aset)
    |--------------------------------------------------------------------------
    |
    | Up to `max_per_asset` photos per asset, managed from the asset detail page
    | only. Files live on the PRIVATE `disk` (storage/app/private) under
    | `{directory}/{asset_id}/{uuid}.jpg` and are only ever served through the
    | authenticated, policy-checked `GET /api/assets/{asset}/photos/{photo}`.
    |
    | Every upload is decoded and re-encoded server-side with GD
    | (App\Services\Image\ImageSanitizer) — the browser-side compression is a
    | convenience, never a security boundary. The input limits are checked from
    | the image HEADER before anything is decoded:
    |
    |  - `max_upload_kb`: application limit (15 MB). PHP's own
    |    `upload_max_filesize` / `post_max_size` still apply and may be lower on
    |    the hosting server.
    |  - `max_input_side` / `max_input_pixels`: hard caps on the declared
    |    dimensions (also the only guard when `memory_limit = -1`).
    |  - `bytes_per_input_pixel` (per format) + `memory_headroom_mb`: decoding
    |    needs about `width × height × factor` bytes plus the `output_max_side`²
    |    × 4 destination canvas; that must fit in what is left of `memory_limit`
    |    after the memory already in use and a reserve of `memory_headroom_mb`.
    |    Factors come from measuring ImageSanitizer's peak memory (PHP 8.3,
    |    bundled GD, 4000×3000): JPEG ≈ 4.9, WebP ≈ 4.9, palette PNG ≈ 5.2 and
    |    truecolor/alpha PNG ≈ 8.2 B/px — GD's PNG reader decodes into a full
    |    intermediate buffer first. The values below keep a margin above those
    |    measurements; they are NOT verified on the production host. A format
    |    missing from the map uses the largest factor.
    |
    | `prune_min_age_minutes` is the default `--older-than` of
    | `php artisan assets:photos:prune-orphans` — a file younger than this is
    | never treated as an orphan (it may belong to an upload still in flight).
    |
    */
    'photos' => [
        'disk' => 'local',
        'directory' => 'asset-photos',
        'max_per_asset' => 3,

        'max_upload_kb' => 15360,
        'max_input_side' => 12000,
        'max_input_pixels' => 50_000_000,
        'bytes_per_input_pixel' => ['jpeg' => 6, 'png' => 9, 'webp' => 6],
        'memory_headroom_mb' => 16,

        'output_max_side' => 1600,
        'jpeg_quality' => 80,

        'upload_per_minute' => 30,
        'prune_min_age_minutes' => 60,
    ],

];
