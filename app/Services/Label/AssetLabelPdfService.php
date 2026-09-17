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
 *    whitespace at all (Tahap 6.0.1; clear-space/centering revised R8, see
 *    {@see CLEAR_SPACE_TARGET_MM}).
 *  - {@see renderBatch()}: one or more A4 portrait pages, each holding a
 *    deterministic, explicitly-positioned grid of labels (2 columns) with
 *    cutting spacing around them — the physical label size itself never
 *    changes; only the sheet it's printed on does (Tahap 6.0.1).
 *  - {@see renderIndividualMulti()} (R8): one PDF, N pages, each page
 *    exactly the chosen label size (NOT an A4 sheet) — R8's "Individual"
 *    print mode for more than one asset. A single asset in Individual mode
 *    skips this entirely and gets {@see renderSingle()}'s PDF straight back.
 *    (An earlier revision of this stage returned a ZIP of N separate PDFs
 *    instead — replaced outright, not kept alongside this, per explicit
 *    instruction that one multi-page PDF is more practical to print.)
 *
 * Strictly READ-ONLY with respect to the asset domain: this class never writes
 * to `assets` (it only ever reads `id` / `asset_code`) and never persists a QR
 * image anywhere. Each QR is rendered in memory (GD, via endroid/qr-code) and
 * embedded straight into the PDF as a base64 data URI — no temp file, no DB
 * column, nothing left behind after the response is sent. Every render method
 * here, including {@see renderIndividualMulti()}, is purely in-memory — there
 * is nothing to clean up.
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

    /**
     * Layout revision — target clear space from the label's content to its
     * physical edge, on every side. Achieved exactly on the horizontal axis
     * for every size (width is never the tight dimension at 55/70/90mm) and
     * on both axes for medium/large. On the vertical axis of the smallest
     * label (55x15mm) it is deliberately NOT applied literally — see
     * {@see MIN_HEADER_HEIGHT_MM}'s own docblock for why — this is the one
     * place the previous 1.4mm inset (border + inner padding) came from;
     * that fixed pair is gone, replaced by this single target-driven value.
     */
    private const CLEAR_SPACE_TARGET_MM = 5.0;

    /**
     * Floor on the header row's (logo/title/QR) own rendered height —
     * anchored here, not on a fraction of the box height, because the QR
     * code is the one element on the label where "too small to be legible"
     * has a real practical consequence (a tag nobody can scan), and it scales
     * directly with header height. Exists solely to stop
     * {@see CLEAR_SPACE_TARGET_MM} from being applied so literally on a
     * 15mm-tall label that almost nothing would be left to render into (15mm
     * - 5mm - 5mm = 5mm total for BOTH rows plus the gap between them) — the
     * explicit "don't break the label just to hit 5mm" requirement this
     * exists to satisfy. Chosen equal to the clear-space target itself (5mm)
     * — a QR/logo square shouldn't render smaller than the margin around it.
     * At this value the vertical inset actually used comes out to ~3.18mm
     * for 55x15mm (visibly more than the old 1.4mm, short of the full 5mm
     * target) while medium/large both still land on exactly 5mm — this floor
     * never engages for either of them, since they have height to spare.
     */
    private const MIN_HEADER_HEIGHT_MM = 5.0;

    /** Vertical gap between the header row and the code-cell row. */
    private const ROW_GAP_MM = 0.4;

    /** Code-cell font size never exceeds this, however few characters there are. */
    private const CODE_FONT_MAX_PT = 11.0;

    /**
     * Layout revision — a code cell's own "natural" width when NOT forced to
     * stretch to fill the available row: the width at which
     * {@see CODE_FONT_MAX_PT}'s own cap already kicks in under the existing
     * `cellFontSizePt = min(CODE_FONT_MAX_PT, cellWidthMm * 2.6)` formula
     * (i.e. `11.0 / 2.6`). A short code's cell group is centered at this
     * natural width instead of stretched edge-to-edge (see
     * {@see buildLabel()}); a long code that needs more than the available
     * row width still shrinks below this exactly as before — this constant
     * only ever LOWERS the ceiling a cell can grow to, never forces overflow.
     */
    private const CODE_NATURAL_CELL_WIDTH_MM = self::CODE_FONT_MAX_PT / 2.6;

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
     * R8 revision — Individual mode for MORE THAN ONE asset: one PDF, N
     * pages, every page exactly the chosen label size — replaces an earlier
     * ZIP-of-N-PDFs approach entirely (removed, not kept alongside this).
     * This is deliberately NOT an A4 sheet: {@see renderBatch()}'s A4 grid
     * stays completely untouched and is not reused here.
     *
     * Every page shares the SAME `@page` size, because Individual mode
     * always renders one `$size` for the whole request — this sidesteps
     * whether Dompdf can vary page size WITHIN one document (it cannot
     * reliably; confirmed experimentally against this exact codebase's own
     * dompdf version before choosing this design — a later `@page` rule does
     * not override an earlier one per-page, only globally), since nothing
     * here ever needs it to. Mirrors {@see renderBatch()}'s own
     * `page-break-after: always` mechanism exactly (see `labels.batch.blade.php`),
     * just one label per page instead of a grid of them — the same
     * dompdf-reliable technique this class already trusted for multi-page
     * output, applied to a new page shape.
     *
     * Reuses {@see buildLabel()} per asset — the exact same per-label
     * geometry `renderSingle()`/`renderBatch()` already compute. No new
     * label content/layout/QR/logo code exists for this method; it only
     * arranges N already-built labels one per page. Purely in-memory, same
     * as every other render method here — no temp file, nothing to clean up.
     *
     * @param  Collection<int, Asset>  $assets  in the exact order pages must appear
     */
    public function renderIndividualMulti(Collection $assets, string $size = self::DEFAULT_SIZE): PdfDocument
    {
        $layout = $this->labelLayout($size);

        return Pdf::loadView('labels.individual-multi', [
            'labels' => $assets->map(fn (Asset $asset) => $this->buildLabel($asset, $layout)),
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

        // Layout revision — a cell never grows past its "natural" width just
        // because the row has room to spare (that was the old behaviour: a
        // short code's cells stretched edge-to-edge, looking disproportionate
        // and "melebar"). A long code that genuinely needs more than the
        // available row width still shrinks below natural width exactly as
        // before (`min()`, not a fixed value) — this is a ceiling, not a floor.
        $maxCellWidthMm = $layout['codeWidthMm'] / count($cells);
        $cellWidthMm = round(min(self::CODE_NATURAL_CELL_WIDTH_MM, $maxCellWidthMm), 4);

        // The resulting cell GROUP (not each cell individually) is centered
        // within the available code-row width — zero offset when the group
        // already fills the row (the long-code case, unchanged from before).
        $groupWidthMm = round($cellWidthMm * count($cells), 4);
        $groupLeftOffsetMm = round(max(0, ($layout['codeWidthMm'] - $groupWidthMm) / 2), 4);

        return [
            'qrDataUri' => $this->qrDataUri($this->qrTargetUrl($asset)),
            'cells' => $cells,
            'cellWidthMm' => $cellWidthMm,
            'groupLeftOffsetMm' => $groupLeftOffsetMm,
            // Recomputed from the actual cell width so a longer/shorter code (a
            // different sequence_no length) still fits one row, never wraps or
            // overflows (Tahap 6.0.1 §5).
            'cellFontSizePt' => round(min(self::CODE_FONT_MAX_PT, $cellWidthMm * 2.6), 1),
        ];
    }

    /**
     * The label's fixed internal geometry for `$size` — the same proportional
     * design (Tahap 6.0.1, clear-space/centering revised — see
     * {@see CLEAR_SPACE_TARGET_MM} / {@see MIN_HEADER_HEIGHT_MM}) scaled
     * to whichever of {@see SIZES} is requested. Identical for every label,
     * single or batch. Every box is `position: absolute`, in mm, relative to
     * the label's own top-left corner (0,0) — see this class's docblock for
     * why.
     *
     * @return array<string, float|string>
     */
    private function labelLayout(string $size = self::DEFAULT_SIZE): array
    {
        $dimensions = $this->dimensions($size);
        $boxWidthMm = $dimensions['width_mm'];
        $boxHeightMm = $dimensions['height_mm'];

        // Horizontal clear space: width is never the tight dimension at
        // 55/70/90mm, so the full target always applies without a floor.
        $insetXMm = self::CLEAR_SPACE_TARGET_MM;

        // Vertical clear space: floored so the header row (QR/logo) never
        // renders shorter than MIN_HEADER_HEIGHT_MM — see that constant's
        // own docblock for the exact 55x15mm numbers.
        $minContentHeightMm = self::MIN_HEADER_HEIGHT_MM / self::HEADER_HEIGHT_RATIO;
        $maxInsetYMm = max(0, ($boxHeightMm - $minContentHeightMm - self::ROW_GAP_MM) / 2);
        $insetYMm = min(self::CLEAR_SPACE_TARGET_MM, $maxInsetYMm);

        $rowLeftMm = $insetXMm;
        $rowWidthMm = $boxWidthMm - (2 * $insetXMm);
        $contentHeightMm = $boxHeightMm - (2 * $insetYMm) - self::ROW_GAP_MM;

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
            'headerTopMm' => $insetYMm,
            'headerLeftMm' => $rowLeftMm,
            'headerWidthMm' => $rowWidthMm,
            'headerHeightMm' => $headerHeightMm,
            'titleText' => self::TITLE_TEXT,
            'titleFontSizePt' => $titleFontSizePt,
            'codeTopMm' => $insetYMm + $headerHeightMm + self::ROW_GAP_MM,
            'codeLeftMm' => $rowLeftMm,
            'codeWidthMm' => $rowWidthMm,
            'codeHeightMm' => $codeHeightMm,
        ];
    }

    /**
     * R8 — the filename for one asset's Individual-mode PDF (the single-asset
     * case only — see {@see renderIndividualMulti()} for more than one):
     * `label-{asset_code}.pdf`, e.g. `label-01.02.001.001.2025.pdf`.
     * `asset_code` is a DB `GENERATED ALWAYS AS ... STORED` column built from
     * digits/dots (see the `assets` table migration) so this is
     * defensive-only, not a real-world requirement today: any character that
     * isn't filesystem-safe is replaced with `_`, and the database value
     * itself is never touched.
     */
    public function individualFilename(Asset $asset): string
    {
        $safeCode = preg_replace('/[^A-Za-z0-9._-]/', '_', $asset->asset_code) ?? $asset->asset_code;

        return "label-{$safeCode}.pdf";
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
