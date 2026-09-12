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

];
