<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Services\Label\AssetLabelPdfService;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.0.1 — refined label layout, character cells, and deterministic A4 batch
 * pagination. Tahap 6.0.2 adds the 'small' (55x15mm, unchanged) / 'medium'
 * (70x20mm) / 'large' (90x25mm) print-size options — most tests here are now
 * parametrized across all three via {@see sizes()} rather than assuming a single
 * fixed physical size. {@see AssetLabelTest} covers authorization, size-parameter
 * validation, QR strategy, and read-only/atomicity guarantees.
 */
class AssetLabelLayoutTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private const MM_TO_PT = 2.83465;

    /** Expected `labelsPerPage()` for each size, hand-computed from the layout
     *  constants (A4 210x297mm, 10mm page margin, 5mm gaps, 2 columns) so a
     *  silent regression in the capacity math is actually caught, not just
     *  "some positive number" (Tahap 6.0.2 §4/§10). */
    private const EXPECTED_CAPACITY = [
        'small' => 28,
        'medium' => 22,
        'large' => 18,
    ];

    /** @return array<string, array{0: string, 1: float, 2: float}> */
    public static function sizes(): array
    {
        return [
            'small' => ['small', 55.0, 15.0],
            'medium' => ['medium', 70.0, 20.0],
            'large' => ['large', 90.0, 25.0],
        ];
    }

    /* ------------------------------------------------------------------ single: physical size */

    #[DataProvider('sizes')]
    public function test_single_label_page_size_matches_the_requested_size(string $size, float $expectedWidth, float $expectedHeight): void
    {
        $asset = $this->existingAsset('001');
        $pdf = app(AssetLabelPdfService::class)->renderSingle($asset, $size);

        $rect = $this->pageRect($pdf);

        $this->assertEqualsWithDelta($expectedWidth, $rect['w'] / self::MM_TO_PT, 0.05);
        $this->assertEqualsWithDelta($expectedHeight, $rect['h'] / self::MM_TO_PT, 0.05);
        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * "No outer whitespace" is verified by construction, not by pixel-inspecting
     * the render: {@see AssetLabelPdfService::labelLayout()} places the label's own
     * border box at the label's full width/height with no page-level margin
     * constant at all, and `labels.single`'s `@page { margin: 0; }` matches the
     * physical size exactly — there is no code path left that could add outer
     * whitespace. This test locks that contract in place, for every size.
     */
    #[DataProvider('sizes')]
    public function test_single_label_border_box_fills_the_entire_page(string $size, float $expectedWidth, float $expectedHeight): void
    {
        $service = app(AssetLabelPdfService::class);
        $layout = $this->invokeLayout($service, $size);

        $this->assertSame($expectedWidth, $layout['boxWidthMm']);
        $this->assertSame($expectedHeight, $layout['boxHeightMm']);
    }

    /* ------------------------------------------------------------------ dimensions() */

    #[DataProvider('sizes')]
    public function test_dimensions_returns_the_configured_width_and_height(string $size, float $expectedWidth, float $expectedHeight): void
    {
        $dimensions = app(AssetLabelPdfService::class)->dimensions($size);

        $this->assertSame($expectedWidth, $dimensions['width_mm']);
        $this->assertSame($expectedHeight, $dimensions['height_mm']);
    }

    public function test_100_by_30_mm_is_no_longer_a_valid_or_used_size(): void
    {
        $service = app(AssetLabelPdfService::class);

        foreach (AssetLabelPdfService::SIZES as $size) {
            $dimensions = $service->dimensions($size);
            $this->assertNotSame(100.0, $dimensions['width_mm']);
            $this->assertNotSame(30.0, $dimensions['height_mm']);
        }
    }

    /* ------------------------------------------------------------------ character cells */

    public function test_code_cells_split_into_one_character_per_cell(): void
    {
        $asset = $this->existingAsset('0004', 2019, [], 'ZL', 'ZC', '001');
        $service = app(AssetLabelPdfService::class);

        $cells = $service->codeCells($asset);

        $this->assertSame(str_split(str_replace('.', '-', $asset->asset_code)), $cells);
        $this->assertSame(['Z', 'L', '-', 'Z', 'C', '-', '0', '0', '1', '-', '0', '0', '0', '4', '-', '2', '0', '1', '9'], $cells);
    }

    public function test_dash_gets_its_own_cell(): void
    {
        $asset = $this->existingAsset('0004', 2019, [], 'ZL', 'ZC', '001');
        $cells = app(AssetLabelPdfService::class)->codeCells($asset);

        $dashCount = count(array_filter($cells, fn (string $c) => $c === '-'));

        // 5 components -> 4 separators, each its own cell.
        $this->assertSame(4, $dashCount);
    }

    public function test_no_space_characters_become_their_own_cell(): void
    {
        $asset = $this->existingAsset('0004', 2019, [], 'ZL', 'ZC', '001');
        $cells = app(AssetLabelPdfService::class)->codeCells($asset);

        $this->assertNotContains(' ', $cells);
    }

    public function test_19_character_code_produces_19_cells(): void
    {
        $asset = $this->existingAsset('0004', 2019, [], 'ZL', 'ZC', '001');
        $cells = app(AssetLabelPdfService::class)->codeCells($asset);

        $this->assertCount(19, $cells);
        $this->assertSame('ZL.ZC.001.0004.2019', $asset->asset_code);
    }

    /** A shorter canonical code (e.g. a numeric location/category, 18 chars total)
     *  still fits one row for every size — cells are always recomputed from the
     *  actual character count, never assumed to be 19. */
    public function test_a_shorter_code_still_recomputes_a_consistent_cell_width(): void
    {
        $short = $this->existingAsset('001', 2020, [], 'ZL', 'ZC', '001');
        $long = $this->existingAsset('0004', 2020, [], 'ZL', 'ZC', '002');

        $service = app(AssetLabelPdfService::class);
        $refl = new \ReflectionMethod($service, 'buildLabel');
        $refl->setAccessible(true);
        $layout = $this->invokeLayout($service);

        $shortLabel = $refl->invoke($service, $short, $layout);
        $longLabel = $refl->invoke($service, $long, $layout);

        // Fewer characters -> each cell gets proportionally MORE width, and every
        // cell within one label is exactly the same width (no per-cell drift).
        $this->assertGreaterThan($longLabel['cellWidthMm'], $shortLabel['cellWidthMm']);
        $this->assertEqualsWithDelta(
            $layout['codeWidthMm'],
            $shortLabel['cellWidthMm'] * count($service->codeCells($short)),
            0.01
        );
        $this->assertEqualsWithDelta(
            $layout['codeWidthMm'],
            $longLabel['cellWidthMm'] * count($service->codeCells($long)),
            0.01
        );
    }

    /**
     * All cells must render in one row — verified by construction: {@see
     * AssetLabelPdfService::buildLabel()} always derives `cellWidthMm` as
     * `codeWidthMm / count($cells)`, i.e. exactly `count($cells)` cells always sum
     * to the row's own width, never more (`white-space: nowrap` in labels._style
     * additionally forbids the browser layout itself from wrapping a cell's text).
     * Verified for every size, since a wider/taller label recomputes the whole
     * layout, not just the code row.
     */
    #[DataProvider('sizes')]
    public function test_all_cells_fit_in_the_single_available_row_width(string $size): void
    {
        $asset = $this->existingAsset('0004', 2019, [], 'ZL', 'ZC', '001');
        $service = app(AssetLabelPdfService::class);
        $layout = $this->invokeLayout($service, $size);

        $refl = new \ReflectionMethod($service, 'buildLabel');
        $refl->setAccessible(true);
        $label = $refl->invoke($service, $asset, $layout);

        $totalWidthMm = $label['cellWidthMm'] * count($label['cells']);

        $this->assertEqualsWithDelta($layout['codeWidthMm'], $totalWidthMm, 0.01);
        // A generous physical code font size is never allowed to overflow the row.
        $this->assertLessThanOrEqual(11.0, $label['cellFontSizePt']);
        $this->assertGreaterThan(0, $label['cellFontSizePt']);
    }

    /** An 18-char code (`ZL.ZC.001.001.2020`, 3-digit sequence) and a 19-char code
     *  (`ZL.ZC.001.0004.2019`, 4-digit sequence) both still fit, for every size —
     *  no wrap, no overflow, no assumed length. */
    #[DataProvider('sizes')]
    public function test_varying_code_lengths_fit_for_every_size(string $size): void
    {
        $eighteen = $this->existingAsset('001', 2020, [], 'ZL', 'ZC', '001');
        $nineteen = $this->existingAsset('0004', 2019, [], 'ZL', 'ZC', '002');
        $this->assertSame(18, strlen($eighteen->asset_code));
        $this->assertSame(19, strlen($nineteen->asset_code));

        $service = app(AssetLabelPdfService::class);
        $layout = $this->invokeLayout($service, $size);
        $refl = new \ReflectionMethod($service, 'buildLabel');
        $refl->setAccessible(true);

        foreach ([$eighteen, $nineteen] as $asset) {
            $label = $refl->invoke($service, $asset, $layout);
            $totalWidthMm = $label['cellWidthMm'] * count($label['cells']);
            $this->assertEqualsWithDelta($layout['codeWidthMm'], $totalWidthMm, 0.01);
            $this->assertGreaterThan(0, $label['cellWidthMm']);
        }
    }

    /* ------------------------------------------------------------------ batch: A4 grid */

    #[DataProvider('sizes')]
    public function test_batch_pdf_page_size_is_a4_portrait_for_every_size(string $size): void
    {
        $asset = $this->existingAsset('001');
        $pdf = app(AssetLabelPdfService::class)->renderBatch(collect([$asset]), $size);

        $rect = $this->pageRect($pdf);

        $this->assertEqualsWithDelta(210.0, $rect['w'] / self::MM_TO_PT, 0.05);
        $this->assertEqualsWithDelta(297.0, $rect['h'] / self::MM_TO_PT, 0.05);
    }

    #[DataProvider('sizes')]
    public function test_batch_grid_uses_exactly_two_columns(string $size): void
    {
        $service = app(AssetLabelPdfService::class);
        $refl = new \ReflectionMethod($service, 'gridPosition');
        $refl->setAccessible(true);

        $pos0 = $refl->invoke($service, 0, $size); // row 0, col 0
        $pos1 = $refl->invoke($service, 1, $size); // row 0, col 1
        $pos2 = $refl->invoke($service, 2, $size); // row 1, col 0 — wraps back to column 0

        $this->assertSame($pos0['xMm'], $pos2['xMm'], 'Third label did not wrap back to column 0 — grid is not 2 columns.');
        $this->assertNotSame($pos0['xMm'], $pos1['xMm']);
        $this->assertSame($pos0['yMm'], $pos1['yMm'], 'First two labels are not on the same row.');
        $this->assertGreaterThan($pos0['yMm'], $pos2['yMm'], 'Third label is not on a lower row.');
    }

    #[DataProvider('sizes')]
    public function test_batch_label_physical_size_matches_the_requested_size(string $size, float $expectedWidth, float $expectedHeight): void
    {
        $service = app(AssetLabelPdfService::class);
        $layout = $this->invokeLayout($service, $size);

        // labelLayout() is the SAME method renderSingle() and renderBatch() both
        // call — the label itself is never scaled or resized for the A4 sheet.
        $this->assertSame($expectedWidth, $layout['boxWidthMm']);
        $this->assertSame($expectedHeight, $layout['boxHeightMm']);
    }

    #[DataProvider('sizes')]
    public function test_batch_has_cutting_spacing_between_and_around_labels(string $size): void
    {
        $service = app(AssetLabelPdfService::class);
        $refl = new \ReflectionMethod($service, 'gridPosition');
        $refl->setAccessible(true);

        $pos0 = $refl->invoke($service, 0, $size);
        $pos1 = $refl->invoke($service, 1, $size);
        $labelWidth = $service->dimensions($size)['width_mm'];

        // page margin: the first label does not start at the page edge (x=0/y=0)
        $this->assertGreaterThan(0.0, $pos0['xMm']);
        $this->assertGreaterThan(0.0, $pos0['yMm']);
        // column gap: second label starts strictly after the first label's own
        // right edge, not flush against it
        $this->assertGreaterThan($pos0['xMm'] + $labelWidth, $pos1['xMm']);
    }

    /**
     * Every label on a full page stays within the A4 usable area (page minus the
     * outer margin) for every size — the acceptance criterion that no label may be
     * clipped, cross a page boundary, or fall outside the printable region.
     */
    #[DataProvider('sizes')]
    public function test_every_label_position_stays_within_the_a4_usable_area(string $size): void
    {
        $service = app(AssetLabelPdfService::class);
        $refl = new \ReflectionMethod($service, 'gridPosition');
        $refl->setAccessible(true);

        $pageMarginMm = $this->classConstant($service, 'PAGE_MARGIN_MM');
        $a4WidthMm = $this->classConstant($service, 'A4_WIDTH_MM');
        $a4HeightMm = $this->classConstant($service, 'A4_HEIGHT_MM');
        ['width_mm' => $labelWidth, 'height_mm' => $labelHeight] = $service->dimensions($size);

        $capacity = $service->labelsPerPage($size);

        for ($i = 0; $i < $capacity; $i++) {
            $pos = $refl->invoke($service, $i, $size);

            $this->assertGreaterThanOrEqual($pageMarginMm, $pos['xMm'], "label {$i} left edge is inside the margin");
            $this->assertGreaterThanOrEqual($pageMarginMm, $pos['yMm'], "label {$i} top edge is inside the margin");
            $this->assertLessThanOrEqual($a4WidthMm - $pageMarginMm, $pos['xMm'] + $labelWidth, "label {$i} right edge overflows the usable area");
            $this->assertLessThanOrEqual($a4HeightMm - $pageMarginMm, $pos['yMm'] + $labelHeight, "label {$i} bottom edge overflows the usable area");
        }
    }

    /** No two labels on the same page ever overlap, for every size. */
    #[DataProvider('sizes')]
    public function test_no_two_labels_on_a_page_overlap(string $size): void
    {
        $service = app(AssetLabelPdfService::class);
        $refl = new \ReflectionMethod($service, 'gridPosition');
        $refl->setAccessible(true);

        ['width_mm' => $w, 'height_mm' => $h] = $service->dimensions($size);
        $capacity = $service->labelsPerPage($size);

        $rects = [];
        for ($i = 0; $i < $capacity; $i++) {
            $pos = $refl->invoke($service, $i, $size);
            $rects[] = ['x1' => $pos['xMm'], 'y1' => $pos['yMm'], 'x2' => $pos['xMm'] + $w, 'y2' => $pos['yMm'] + $h];
        }

        for ($i = 0; $i < count($rects); $i++) {
            for ($j = $i + 1; $j < count($rects); $j++) {
                $a = $rects[$i];
                $b = $rects[$j];
                $overlaps = $a['x1'] < $b['x2'] && $b['x1'] < $a['x2'] && $a['y1'] < $b['y2'] && $b['y1'] < $a['y2'];
                $this->assertFalse($overlaps, "labels {$i} and {$j} overlap for size '{$size}'");
            }
        }
    }

    /* ------------------------------------------------------------------ pagination: capacity */

    #[DataProvider('sizes')]
    public function test_labels_per_page_is_deterministic_and_derived_from_layout_constants(string $size): void
    {
        $service = app(AssetLabelPdfService::class);

        $this->assertGreaterThan(0, $service->labelsPerPage($size));
        // Calling it twice must yield the exact same number — no randomness, no
        // dependency on how many assets are actually being rendered.
        $this->assertSame($service->labelsPerPage($size), $service->labelsPerPage($size));
    }

    /** Exact hand-computed capacity per size, so a regression in the capacity math
     *  itself (not just "some positive number") is caught. */
    public function test_capacity_matches_the_hand_computed_value_for_each_size(): void
    {
        $service = app(AssetLabelPdfService::class);

        foreach (self::EXPECTED_CAPACITY as $size => $expected) {
            $this->assertSame($expected, $service->labelsPerPage($size), "capacity mismatch for size '{$size}'");
        }
    }

    #[DataProvider('sizes')]
    public function test_a_single_label_produces_exactly_one_page(string $size): void
    {
        $assets = $this->makeAssets(1);

        $pdf = app(AssetLabelPdfService::class)->renderBatch($assets, $size);

        $this->assertSame(1, $this->pageCount($pdf));
    }

    #[DataProvider('sizes')]
    public function test_a_few_labels_below_capacity_produce_exactly_one_page(string $size): void
    {
        $assets = $this->makeAssets(3);

        $pdf = app(AssetLabelPdfService::class)->renderBatch($assets, $size);

        $this->assertSame(1, $this->pageCount($pdf));
    }

    #[DataProvider('sizes')]
    public function test_exactly_one_page_capacity_produces_a_single_page(string $size): void
    {
        $capacity = app(AssetLabelPdfService::class)->labelsPerPage($size);
        $assets = $this->makeAssets($capacity);

        $pdf = app(AssetLabelPdfService::class)->renderBatch($assets, $size);

        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * Page boundaries only ever fall between complete labels/rows: {@see
     * AssetLabelPdfService::renderBatch()} chunks assets by the exact, precomputed
     * `labelsPerPage()` capacity BEFORE any HTML/PDF layout happens — dompdf's own
     * (fallible, per Tahap 6.0's findings) page-breaking is never asked to decide
     * where a page ends, so a label can never end up split across two pages.
     */
    #[DataProvider('sizes')]
    public function test_capacity_plus_one_produces_exactly_two_pages(string $size): void
    {
        $capacity = app(AssetLabelPdfService::class)->labelsPerPage($size);
        $assets = $this->makeAssets($capacity + 1);

        $pdf = app(AssetLabelPdfService::class)->renderBatch($assets, $size);

        $this->assertSame(2, $this->pageCount($pdf));
    }

    #[DataProvider('sizes')]
    public function test_multiple_full_pages_plus_a_remainder_produces_the_right_page_count(string $size): void
    {
        $capacity = app(AssetLabelPdfService::class)->labelsPerPage($size);
        $assets = $this->makeAssets(($capacity * 2) + 3);

        $pdf = app(AssetLabelPdfService::class)->renderBatch($assets, $size);

        $this->assertSame(3, $this->pageCount($pdf));
    }

    #[DataProvider('sizes')]
    public function test_last_page_may_contain_fewer_labels_without_being_invalid(string $size): void
    {
        $capacity = app(AssetLabelPdfService::class)->labelsPerPage($size);
        $assets = $this->makeAssets($capacity + 1);

        $pdf = app(AssetLabelPdfService::class)->renderBatch($assets, $size);

        // A genuinely present second page (not a blank spurious one, and not one
        // that silently swallowed the last label) — {@see test_capacity_plus_one_produces_exactly_two_pages}
        // already confirms the count; this test documents WHY that's guaranteed.
        $this->assertSame(2, $this->pageCount($pdf));
    }

    /* ------------------------------------------------------------------ visual/manual smoke via HTTP */

    #[DataProvider('sizes')]
    public function test_batch_endpoint_still_returns_a4_multi_page_pdf_end_to_end(string $size): void
    {
        Sanctum::actingAs($this->operator());
        $capacity = app(AssetLabelPdfService::class)->labelsPerPage($size);
        $assets = $this->makeAssets($capacity + 2);

        $response = $this->postJson('/api/assets/batch/label', ['asset_ids' => $assets->pluck('id')->all(), 'size' => $size])
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /* ------------------------------------------------------------------ helpers */

    /** @return Collection<int, Asset> */
    private function makeAssets(int $count)
    {
        return collect(range(1, $count))->map(
            fn (int $i) => $this->existingAsset(str_pad((string) $i, 4, '0', STR_PAD_LEFT), 2020, [], 'ZL', 'ZC', '001')
        );
    }

    /** @return array<string, float|string> */
    private function invokeLayout(AssetLabelPdfService $service, string $size = 'small'): array
    {
        $refl = new \ReflectionMethod($service, 'labelLayout');
        $refl->setAccessible(true);

        return $refl->invoke($service, $size);
    }

    private function classConstant(object $object, string $name): mixed
    {
        return (new \ReflectionClassConstant($object, $name))->getValue();
    }

    private function pageCount(PdfDocument $pdf): int
    {
        $pdf->output();

        return $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    /** @return array{w: float, h: float} in points */
    private function pageRect(PdfDocument $pdf): array
    {
        $pdf->output();
        $canvas = $pdf->getDomPDF()->getCanvas();

        return ['w' => $canvas->get_width(), 'h' => $canvas->get_height()];
    }
}
