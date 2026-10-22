<?php

namespace App\Services\Report;

use App\Enums\AssetCondition;
use App\Enums\AssetSource;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Services\Dashboard\DashboardService;
use App\Support\LocationScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Carbon\CarbonImmutable;

/**
 * `GET /api/reports/inventory/pdf` — the Laporan page as an A4 portrait PDF
 * (docs/mockups/laporan-pdf.html), built from the SAME validated filters and the
 * SAME {@see ReportService} aggregates as `GET /api/reports/inventory`: no asset
 * rows are ever loaded, so it stays cheap enough to render inside the request on
 * shared hosting.
 *
 * The filters are not drawn as controls; {@see filterCaption()} turns them into a
 * sentence under the header. Always light colours, DejaVu Sans (bundled with
 * dompdf), no remote resources, no embedded PHP: "Halaman X dari Y" and the footer
 * are drawn with the canvas `page_text()` after rendering.
 */
class ReportPdfService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const FOOTER = 'Laporan Inventaris — Yayasan Vidatra';

    /** The six filters the Laporan page offers, in its order; absent ones are named "not used". */
    private const MAIN_FILTERS = [
        'location_code' => 'Lokasi',
        'category_code' => 'Kategori',
        'condition' => 'Kondisi',
        'is_written_off' => 'Status',
        'asset_year' => 'Tahun',
        'asset_source' => 'Sumber',
    ];

    /** PDF colours (dompdf has no CSS variables): the light theme tokens. */
    public const SOURCE_COLORS = [
        'bosda' => '#2a78d6',
        'bosp' => '#eb6834',
        'bostk' => '#1baf7a',
        'yayasan' => '#eda100',
        'lainnya' => '#e87ba4',
        'belum_diberi_keterangan' => '#9ca3af',
    ];

    public const CONDITION_COLORS = [
        'baik' => '#0ca30c',
        'kurang_baik' => '#fab219',
        'rusak_berat' => '#d03b3b',
        'unknown' => '#9ca3af',
    ];

    /**
     * The applied filters as caption parts: `used` = ["Lokasi: YAYASAN", …] in page
     * order (every value of a multi-value filter, e.g. from an older link), followed by
     * filters the page no longer offers but the API still accepts (Subkategori,
     * Ruangan, Pencarian); `unused` = labels of the six page filters left empty.
     * A unit_admin without a Lokasi filter is still limited to their own location,
     * so that is stated instead of calling Lokasi "not used".
     *
     * @return array{used: list<string>, unused: list<string>}
     */
    public function filterCaption(AssetIndexRequest $request): array
    {
        $used = [];
        $unused = [];

        foreach (self::MAIN_FILTERS as $key => $label) {
            $values = $this->valueLabels($key, $request);

            if ($key === 'location_code' && $values === [] && ! LocationScope::for($request->user())->isGlobal()) {
                $own = LocationScope::for($request->user())->resolveFilterCodes([]);
                $values = array_map(fn (string $name): string => "{$name} (sesuai hak akses)", $this->locationNames($own));
            }

            if ($values === []) {
                $unused[] = $label;
            } else {
                $used[] = "{$label}: ".implode(', ', $values);
            }
        }

        foreach (['subcategory_code' => 'Subkategori', 'room_id' => 'Ruangan'] as $key => $label) {
            $values = $this->valueLabels($key, $request);
            if ($values !== []) {
                $used[] = "{$label}: ".implode(', ', $values);
            }
        }

        $search = trim((string) $request->validated('q'));
        if ($search !== '') {
            $used[] = "Pencarian: \"{$search}\"";
        }

        return ['used' => $used, 'unused' => $unused];
    }

    /**
     * Everything `reports/inventory-pdf.blade.php` prints, already ordered and
     * formatted, so the view is plain loops (and its text is testable as HTML).
     *
     * @param  array<string, mixed>  $report  {@see ReportService::build()}
     * @return array<string, mixed>
     */
    public function viewData(array $report, AssetIndexRequest $request, CarbonImmutable $printedAt): array
    {
        $summary = $report['summary'];
        $total = $summary['total_assets'];
        $countActive = fn (array $rows): int => count(array_filter($rows, fn (array $r): bool => $r['is_active']));

        $sources = array_map(fn (array $s): array => [
            'label' => $s['label'],
            'value' => $s['asset_count'],
            'color' => self::SOURCE_COLORS[$s['value']],
        ], $report['by_source']);

        $conditions = [];
        foreach (self::conditionLabels() as $key => $label) {
            $conditions[] = ['label' => $label, 'value' => $report['by_condition'][$key], 'color' => self::CONDITION_COLORS[$key]];
        }

        return [
            'printedAt' => $printedAt->locale('id')->translatedFormat('j F Y, H.i').' WIB',
            'printedBy' => $request->user()->name,
            'domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'inventaris.vidatra.sch.id',
            'caption' => $this->filterCaption($request),
            'kpis' => [
                'Total Aset' => $total,
                'Aset Aktif' => $summary['active_assets'],
                'Aset Ditulis Off' => $summary['written_off_assets'],
                'Lokasi Terdaftar' => $countActive($report['by_location']),
                'Kategori Terdaftar' => $countActive($report['by_category']),
            ],
            'sources' => $this->withShares($sources, $total),
            'conditions' => $this->withShares($conditions, $total),
            'locations' => $this->bars($report['by_location']),
            'categories' => $this->bars($report['by_category']),
            'rooms' => $report['by_room'],
        ];
    }

    /**
     * @param  array<string, mixed>  $report  {@see ReportService::build()}
     */
    public function render(array $report, AssetIndexRequest $request, CarbonImmutable $printedAt): PdfDocument
    {
        $pdf = Pdf::loadView('reports.inventory-pdf', $this->viewData($report, $request, $printedAt))
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isJavascriptEnabled' => false,
                // embed only the glyphs used (~900 KB -> tens of KB with DejaVu Sans)
                'isFontSubsettingEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

        $pdf->render();
        $this->drawFooter($pdf);

        return $pdf;
    }

    public function filename(CarbonImmutable $printedAt): string
    {
        return 'laporan-inventaris-'.$printedAt->format('Ymd-Hi').'.pdf';
    }

    /** Footer on every page: a rule, the report name on the left, "Halaman X dari Y" on the right. */
    private function drawFooter(PdfDocument $pdf): void
    {
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');

        $margin = 40; // pt — matches the @page side margins of the view (14mm)
        $y = $canvas->get_height() - 32;
        $right = $canvas->get_width() - $margin;
        $grey = [0.31, 0.42, 0.49]; // #4f6b7c

        $canvas->page_line($margin, $y - 6, $right, $y - 6, [0.79, 0.83, 0.86], 0.5);
        $canvas->page_text($margin, $y, self::FOOTER, $font, 8, $grey);

        // page_text() cannot right-align, so measure the widest label: page number as long as the count
        $pages = $canvas->get_page_count();
        $widest = $metrics->getTextWidth("Halaman {$pages} dari {$pages}", $font, 8);
        $canvas->page_text($right - $widest, $y, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 8, $grey);
    }

    /**
     * Display labels for one filter's validated values, in a stable order.
     *
     * @return list<string>
     */
    private function valueLabels(string $key, AssetIndexRequest $request): array
    {
        $raw = $request->filterArray($key);
        if ($raw === []) {
            return [];
        }

        return match ($key) {
            'location_code' => $this->locationNames($raw),
            'category_code' => Category::query()->whereIn('code', $raw)->orderBy('code')->pluck('name')->all(),
            'condition' => array_values(array_intersect_key(self::conditionLabels(), array_flip($raw))),
            'is_written_off' => $this->statusLabels($raw),
            'asset_year' => array_map('strval', collect($request->assetYears())->sort()->values()->all()),
            'asset_source' => array_values(array_map(
                fn (AssetSource $s): string => $s->label(),
                array_filter(AssetSource::cases(), fn (AssetSource $s): bool => in_array($s->value, $request->assetSources(), true)),
            )),
            'subcategory_code' => $this->subcategoryNames($request->subcategoryPairs()),
            'room_id' => $this->roomNames($request),
            default => $raw,
        };
    }

    /**
     * Stacked-bar items + their share of `$total` ("95,9%", Indonesian decimal comma);
     * `width` is the drawn segment width, so zero items get no segment.
     *
     * @param  list<array{label: string, value: int, color: string}>  $items
     * @return list<array{label: string, value: int, color: string, percent: string, width: float}>
     */
    private function withShares(array $items, int $total): array
    {
        return array_map(function (array $item) use ($total): array {
            $share = $total > 0 ? $item['value'] / $total * 100 : 0.0;

            return $item + [
                'percent' => rtrim(rtrim(number_format($share, 1, ',', '.'), '0'), ',').'%',
                'width' => round($share, 3),
            ];
        }, $items);
    }

    /**
     * Location/category rows as horizontal bars, largest first; an inactive row is
     * listed only while it holds matching assets and is marked "(nonaktif)".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{label: string, value: int, width: float}>
     */
    private function bars(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => $b['asset_count'] <=> $a['asset_count'] ?: strcmp($a['name'], $b['name']));
        $max = max([1, ...array_column($rows, 'asset_count')]);

        return array_map(fn (array $r): array => [
            'label' => $r['is_active'] ? $r['name'] : "{$r['name']} (nonaktif)",
            'value' => $r['asset_count'],
            'width' => round($r['asset_count'] / $max * 100, 3),
        ], $rows);
    }

    /** Condition buckets in display order, `unknown` (= NULL) last. @return array<string, string> */
    public static function conditionLabels(): array
    {
        $labels = [];
        foreach (AssetCondition::cases() as $condition) {
            $labels[$condition->value] = $condition->label();
        }

        return $labels + ['unknown' => 'Tidak diketahui'];
    }

    /**
     * `is_written_off[]` values (1/0, true/false) as status labels, Aktif first.
     *
     * @param  list<string>  $raw
     * @return list<string>
     */
    private function statusLabels(array $raw): array
    {
        $flags = array_map(fn (string $v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN), $raw);

        return array_values(array_filter([
            in_array(false, $flags, true) ? 'Aktif' : null,
            in_array(true, $flags, true) ? 'Ditulis off' : null,
        ]));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  [category_code, subcategory_code]
     * @return list<string>
     */
    private function subcategoryNames(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        return Subcategory::query()
            ->where(function ($q) use ($pairs): void {
                foreach ($pairs as [$category, $code]) {
                    $q->orWhere(fn ($w) => $w->where('category_code', $category)->where('code', $code));
                }
            })
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function locationNames(array $codes): array
    {
        return Location::query()->whereIn('code', $codes)->orderBy('code')->pluck('name')->all();
    }

    /** @return list<string> */
    private function roomNames(AssetIndexRequest $request): array
    {
        $filter = $request->roomFilter();
        if ($filter === null) {
            return [];
        }

        $names = Room::query()
            ->with('location:code,name')
            ->whereIn('id', $filter['ids'])
            ->orderBy('location_code')
            ->orderBy('name')
            ->get()
            ->map(fn (Room $room): string => "{$room->name} ({$room->location?->name})")
            ->all();

        if ($filter['includeNull']) {
            $names[] = DashboardService::ROOMLESS_LABEL;
        }

        return $names;
    }
}
