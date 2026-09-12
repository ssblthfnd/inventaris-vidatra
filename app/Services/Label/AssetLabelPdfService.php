<?php

namespace App\Services\Label;

use App\Exceptions\QrBaseUrlNotConfiguredException;
use App\Models\Asset;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;

/**
 * Renders printable asset-label PDFs.
 *
 *  - {@see renderSingle()}: one PDF page, exactly the chosen physical label
 *    size (config('inventory.label.sizes'), Tahap 6.0.2 — 'small'/'medium'/
 *    'large', default 'small'), border flush against the page edge — no outer
 *    whitespace at all (Tahap 6.0.1).
 *  - {@see renderBatch()}: one or more A4 portrait pages, each holding a
 *    deterministic, explicitly-positioned grid of labels (2 columns) with
 *    cutting spacing around them — the physical label size itself never
 *    changes; only the sheet it's printed on does (Tahap 6.0.1).
 *
 * Strictly READ-ONLY with respect to the asset domain: this class never writes
 * to `assets` (it only ever reads `id` / `asset_code`) and never persists a QR
 * image anywhere. Each QR is rendered in memory (GD, via endroid/qr-code) and
 * embedded straight into the PDF as a base64 data URI — no temp file, no DB
 * column, nothing left behind after the response is sent.
 *
 * Layout approach: every box (label border, header row, logo/title/QR, code
 * character cells, and — in batch — every label's position on the sheet) is
 * given explicit `position: absolute` coordinates in mm, computed here in PHP
 * rather than left to normal document flow. This isn't a style preference:
 * Tahap 6.0 found dompdf's page-break logic reliably miscounts ordinary
 * stacked block boxes at this label's physical scale — a normal-flow child's
 * margin/border/padding can push a "should-fit-exactly" box a fraction of a
 * millimeter past one page and silently produce a spurious blank (or
 * content-losing) extra page. Absolute positioning removes every box from
 * that flow/collapse calculation entirely. Tahap 6.0.1 keeps this approach for
 * the batch A4 grid too, computing every label's page/row/column/x/y itself
 * rather than relying on `page-break-inside: avoid` as the only safeguard.
 * Tahap 6.0.2 parametrizes every one of these computations by `$size` — the
 * geometry formulas themselves are unchanged, they just no longer read a
 * single flat `width_mm`/`height_mm` pair from config.
 */
class AssetLabelPdfService
{
    /* ------------------------------------------------------------------ print sizes (Tahap 6.0.2) */

    public const SIZE_SMALL = 'small';

    public const SIZE_MEDIUM = 'medium';

    public const SIZE_LARGE = 'large';

    /** Allowed values for the `size` request parameter, in menu-display order. */
    public const SIZES = [self::SIZE_SMALL, self::SIZE_MEDIUM, self::SIZE_LARGE];

    public const DEFAULT_SIZE = self::SIZE_SMALL;

    /* ------------------------------------------------------------------ single-label internal layout */

    /** Fraction of the label's inner content height given to the logo/title/QR row. */
    private const HEADER_HEIGHT_RATIO = 0.6;

    /** The label's own border + inner padding, inset from its box edge. */
    private const BORDER_MM = 0.8;

    private const INNER_PAD_MM = 0.6;

    /** Vertical gap between the header row and the code-cell row. */
    private const ROW_GAP_MM = 0.4;

    /** Code-cell font size never exceeds this, however few characters there are. */
    private const CODE_FONT_MAX_PT = 11.0;

    private const TITLE_TEXT = 'YAYASAN VIDATRA';

    private const TITLE_FONT_MAX_PT = 12.0;

    /** Rough average glyph width, as a fraction of font-size, for bold Helvetica —
     *  used only to pick a font size that fits; not a typographic measurement.
     *  Deliberately generous (dompdf renders noticeably wider than a naive ~0.6em
     *  estimate) so "YAYASAN VIDATRA" never overlaps the QR box next to it. */
    private const TITLE_CHAR_WIDTH_FACTOR = 0.82;

    private const MM_TO_PT = 2.83465;

    /* ------------------------------------------------------------------ batch A4 grid */

    private const A4_WIDTH_MM = 210.0;

    private const A4_HEIGHT_MM = 297.0;

    /** Outer sheet margin — cutting/handling allowance, batch-only (Tahap 6.0.1 §9). */
    private const PAGE_MARGIN_MM = 10.0;

    /** Gap between columns / rows — cutting allowance, batch-only. Never part of the
     *  label's own physical size. */
    private const COLUMN_GAP_MM = 5.0;

    private const ROW_GAP_BATCH_MM = 5.0;

    private const COLUMNS = 2;

    /* ------------------------------------------------------------------ QR raster */

    /** Raster size (px) the QR is generated at — comfortably above print resolution
     *  for even the largest configured label size, so scaling down never blurs it. */
    private const QR_RASTER_SIZE = 600;

    private const QR_RASTER_MARGIN = 20;

    private ?string $logoDataUriCache = null;

    /**
     * The stable QR target URL for one asset: {qr_base_url}/a/{asset->id}.
     * Deliberately keyed on the database id — never asset_code/sequence_no/room —
     * so a printed tag keeps resolving to the same record across room moves,
     * write-offs, or any other lifecycle change (Tahap 6.0, "Strategi B").
     */
    public function qrTargetUrl(Asset $asset): string
    {
        $base = config('inventory.qr_base_url');

        if (blank($base)) {
            throw new QrBaseUrlNotConfiguredException;
        }

        return rtrim((string) $base, '/').'/a/'.$asset->id;
    }

    /** `01.02.001.0004.2019` (canonical, DB) -> `01 - 02 - 001 - 0004 - 2019` (human-readable reference only — the label itself renders {@see codeCells()}, not this string). */
    public function displayCode(Asset $asset): string
    {
        return str_replace('.', ' - ', $asset->asset_code);
    }

    /**
     * The asset code as individual label characters — one per printed cell.
     * `01.02.001.0004.2019` -> ['0','1','-','0','2','-','0','0','1','-','0','0','0','4','-','2','0','1','9']
     * (19 cells for a canonical 5-component code). Dashes get their own cell;
     * the formatting SPACES in {@see displayCode()} are not part of this at all
     * (they only ever existed for that spaced human-readable string).
     *
     * @return array<int, string>
     */
    public function codeCells(Asset $asset): array
    {
        return str_split(str_replace('.', '-', $asset->asset_code));
    }

    /** One PDF page, exactly the chosen size, border flush with the page edge. */
    public function renderSingle(Asset $asset, string $size = self::DEFAULT_SIZE): PdfDocument
    {
        $layout = $this->labelLayout($size);

        return Pdf::loadView('labels.single', [
            'label' => $this->buildLabel($asset, $layout),
            'logoDataUri' => $this->logoDataUri(),
            'pageWidthMm' => $layout['boxWidthMm'],
            'pageHeightMm' => $layout['boxHeightMm'],
            ...$layout,
        ]);
    }

    /**
     * One or more A4 portrait pages, a deterministic {@see labelsPerPage()} labels
     * per page in a fixed 2-column grid, in the exact order `$assets` is given.
     *
     * @param  Collection<int, Asset>  $assets  in the exact order labels must appear
     */
    public function renderBatch(Collection $assets, string $size = self::DEFAULT_SIZE): PdfDocument
    {
        $layout = $this->labelLayout($size);
        $capacity = $this->labelsPerPage($size);

        $pages = $assets->values()->chunk($capacity)->values()->map(
            fn (Collection $pageAssets): Collection => $pageAssets->values()->map(
                fn (Asset $asset, int $index): array => [
                    'label' => $this->buildLabel($asset, $layout),
                    'position' => $this->gridPosition($index, $size),
                ]
            )
        );

        return Pdf::loadView('labels.batch', [
            'pages' => $pages,
            'logoDataUri' => $this->logoDataUri(),
            'a4WidthMm' => self::A4_WIDTH_MM,
            'a4HeightMm' => self::A4_HEIGHT_MM,
            'labelWidthMm' => $layout['boxWidthMm'],
            'labelHeightMm' => $layout['boxHeightMm'],
            ...$layout,
        ]);
    }

    /**
     * How many labels deterministically fit one A4 sheet for the given `$size`,
     * given {@see COLUMNS} columns and the page-margin/gap constants above.
     * `renderBatch()` chunks assets by exactly this number per page — dompdf's own
     * page-breaking is never asked to decide this (Tahap 6.0.1 §10). Capacity is
     * computed, never hard-coded per size (Tahap 6.0.2 §4) — it naturally differs
     * across small/medium/large because the label footprint differs.
     */
    public function labelsPerPage(string $size = self::DEFAULT_SIZE): int
    {
        $dimensions = $this->dimensions($size);
        $labelWidthMm = $dimensions['width_mm'];
        $labelHeightMm = $dimensions['height_mm'];

        $usableWidthMm = self::A4_WIDTH_MM - (2 * self::PAGE_MARGIN_MM);
        $usableHeightMm = self::A4_HEIGHT_MM - (2 * self::PAGE_MARGIN_MM);

        $neededWidthMm = (self::COLUMNS * $labelWidthMm) + ((self::COLUMNS - 1) * self::COLUMN_GAP_MM);
        abort_if(
            $neededWidthMm > $usableWidthMm,
            500,
            "Batch label grid for size '{$size}' (columns x label width + gaps) does not fit the configured A4 page width."
        );

        // N rows of height H with (N-1) gaps of G fit iff N*H + (N-1)*G <= usable,
        // i.e. N <= (usable + G) / (H + G).
        $rows = (int) floor(($usableHeightMm + self::ROW_GAP_BATCH_MM) / ($labelHeightMm + self::ROW_GAP_BATCH_MM));

        return max(1, $rows) * self::COLUMNS;
    }

    /**
     * The physical width/height (mm) for a predefined size key. The backend is the
     * sole source of truth for these values (Tahap 6.0.2 §7) — callers never accept
     * arbitrary width/height from a client, only one of {@see SIZES}.
     *
     * @return array{width_mm: float, height_mm: float}
     */
    public function dimensions(string $size): array
    {
        $config = config("inventory.label.sizes.{$size}");

        abort_if($config === null, 500, "Unknown label size '{$size}'.");

        return [
            'width_mm' => (float) $config['width_mm'],
            'height_mm' => (float) $config['height_mm'],
        ];
    }

    /**
     * Top-left (mm, from the A4 page's own top-left corner) for the label at
     * `$indexOnPage` (0-based) within its page — see Tahap 6.0.1 §13's formula.
     *
     * @return array{xMm: float, yMm: float}
     */
    private function gridPosition(int $indexOnPage, string $size = self::DEFAULT_SIZE): array
    {
        $row = intdiv($indexOnPage, self::COLUMNS);
        $col = $indexOnPage % self::COLUMNS;

        $dimensions = $this->dimensions($size);
        $labelWidthMm = $dimensions['width_mm'];
        $labelHeightMm = $dimensions['height_mm'];

        return [
            'xMm' => self::PAGE_MARGIN_MM + ($col * ($labelWidthMm + self::COLUMN_GAP_MM)),
            'yMm' => self::PAGE_MARGIN_MM + ($row * ($labelHeightMm + self::ROW_GAP_BATCH_MM)),
        ];
    }

    /**
     * @param  array<string, float>  $layout  from {@see labelLayout()}
     * @return array<string, mixed>
     */
    private function buildLabel(Asset $asset, array $layout): array
    {
        $cells = $this->codeCells($asset);
        $cellWidthMm = round($layout['codeWidthMm'] / count($cells), 4);

        return [
            'qrDataUri' => $this->qrDataUri($this->qrTargetUrl($asset)),
            'cells' => $cells,
            'cellWidthMm' => $cellWidthMm,
            // Recomputed from the actual cell width so a longer/shorter code (a
            // different sequence_no length) still fits one row, never wraps or
            // overflows (Tahap 6.0.1 §5).
            'cellFontSizePt' => round(min(self::CODE_FONT_MAX_PT, $cellWidthMm * 2.6), 1),
        ];
    }

    /**
     * The label's fixed internal geometry for `$size` — the same proportional
     * design (Tahap 6.0.1) scaled to whichever of {@see SIZES} is requested.
     * Identical for every label, single or batch. Every box is
     * `position: absolute`, in mm, relative to the label's own top-left corner
     * (0,0) — see this class's docblock for why.
     *
     * @return array<string, float|string>
     */
    private function labelLayout(string $size = self::DEFAULT_SIZE): array
    {
        $dimensions = $this->dimensions($size);
        $boxWidthMm = $dimensions['width_mm'];
        $boxHeightMm = $dimensions['height_mm'];

        // Header/code rows, inset from the box edge by the border + inner padding.
        // The label's own border sits ON the box edge — for the single-label PDF
        // that edge IS the physical page edge (no separate page margin exists).
        $inset = self::BORDER_MM + self::INNER_PAD_MM;
        $rowLeftMm = $inset;
        $rowWidthMm = $boxWidthMm - (2 * $inset);
        $contentHeightMm = $boxHeightMm - (2 * $inset) - self::ROW_GAP_MM;

        $headerHeightMm = round($contentHeightMm * self::HEADER_HEIGHT_RATIO, 3);
        $codeHeightMm = round($contentHeightMm - $headerHeightMm, 3);

        // Title font size: fit "YAYASAN VIDATRA" in the space left of the header
        // row once the logo and QR (each a headerHeightMm-wide square) take their
        // corners — same "recompute from available width" spirit as codeCells().
        $titleWidthMm = $rowWidthMm - (2 * $headerHeightMm);
        $titleCharCount = mb_strlen(self::TITLE_TEXT);
        $titleFontSizePt = round(min(
            self::TITLE_FONT_MAX_PT,
            ($titleWidthMm / ($titleCharCount * self::TITLE_CHAR_WIDTH_FACTOR)) * self::MM_TO_PT
        ), 1);

        return [
            'boxWidthMm' => $boxWidthMm,
            'boxHeightMm' => $boxHeightMm,
            'headerTopMm' => $inset,
            'headerLeftMm' => $rowLeftMm,
            'headerWidthMm' => $rowWidthMm,
            'headerHeightMm' => $headerHeightMm,
            'titleText' => self::TITLE_TEXT,
            'titleFontSizePt' => $titleFontSizePt,
            'codeTopMm' => $inset + $headerHeightMm + self::ROW_GAP_MM,
            'codeLeftMm' => $rowLeftMm,
            'codeWidthMm' => $rowWidthMm,
            'codeHeightMm' => $codeHeightMm,
        ];
    }

    private function qrDataUri(string $url): string
    {
        $qrCode = new QrCode(
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: self::QR_RASTER_SIZE,
            margin: self::QR_RASTER_MARGIN,
        );

        return (new PngWriter)->write($qrCode)->getDataUri();
    }

    private function logoDataUri(): string
    {
        return $this->logoDataUriCache ??= 'data:image/png;base64,'.base64_encode(
            file_get_contents(resource_path('images/yayasan-logo.png'))
        );
    }
}
