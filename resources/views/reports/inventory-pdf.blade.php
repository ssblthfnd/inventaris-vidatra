{{--
    Laporan Inventaris — A4 portrait PDF (design: docs/mockups/laporan-pdf.html).
    Rendered by dompdf (App\Services\Report\ReportPdfService): tables and inline-block
    instead of flex/grid, colours written out (no CSS variables), DejaVu Sans, always the
    light theme. The footer and "Halaman X dari Y" are drawn by the service after rendering,
    inside the bottom page margin.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan Inventaris</title>
<style>
    @page { margin: 13mm 14mm 20mm 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #1f333f; }
    h1 { margin: 0; font-size: 19pt; font-weight: bold; color: #2e4a5b; }
    h2 { margin: 0 0 6pt; font-size: 10pt; font-weight: bold; color: #2e4a5b; }
    .muted { color: #4f6b7c; }
    .num { text-align: right; white-space: nowrap; }
    table { border-collapse: collapse; }

    .head { width: 100%; border-bottom: 1.5pt solid #2e4a5b; }
    .head td { vertical-align: bottom; padding: 0 0 8pt; }
    .head .meta { text-align: right; line-height: 1.5; }

    .filter-note { margin-top: 10pt; padding: 7pt 10pt; background: #eef7fd; border: 0.75pt solid #a8d0ea; border-radius: 6pt; line-height: 1.5; }
    .filter-note strong { color: #2e4a5b; }

    .kpis { width: 100%; margin-top: 10pt; border-collapse: separate; border-spacing: 5pt 0; }
    .kpis td { width: 20%; padding: 7pt 8pt; border: 0.75pt solid #c9d4db; border-radius: 6pt; }
    .kpis .label { display: block; color: #4f6b7c; }
    .kpis .value { display: block; margin-top: 3pt; font-size: 15pt; font-weight: bold; color: #2e4a5b; }

    .pair { width: 100%; margin-top: 10pt; border-collapse: separate; border-spacing: 5pt 0; }
    .pair > tbody > tr > td.box { width: 50%; padding: 8pt 10pt; vertical-align: top; border: 0.75pt solid #c9d4db; border-radius: 6pt; }

    .stack { width: 100%; height: 11pt; background: #eaf1f6; }
    .stack td { height: 11pt; padding: 0; border-right: 1.5pt solid #ffffff; font-size: 1pt; }
    .stack td.last { border-right: 0; }

    .legend { width: 100%; margin-top: 6pt; }
    .legend td { padding: 1.5pt 0; }
    .legend .sw { width: 12pt; }
    .legend .sw span { display: inline-block; width: 7pt; height: 7pt; border-radius: 1.5pt; }
    .legend .pct { width: 40pt; color: #4f6b7c; }

    .bars { width: 100%; }
    .bars td { padding: 2pt 0; vertical-align: middle; }
    .bars .label { width: 40%; padding-right: 6pt; }
    .bars .count { width: 34pt; }
    .track { height: 6pt; background: #eaf1f6; border-radius: 2pt; }
    .fill { height: 6pt; background: #6f9bb8; border-radius: 2pt; }

    .rooms { width: 100%; margin-top: 4pt; }
    .rooms thead { display: table-header-group; }
    .rooms tr { page-break-inside: avoid; }
    .rooms th { padding: 5pt 4pt; text-align: left; font-weight: bold; border-bottom: 0.75pt solid #2e4a5b; }
    .rooms th.num { text-align: right; }
    .rooms td { padding: 4pt; border-bottom: 0.5pt solid #c9d4db; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <h1>Laporan Inventaris</h1>
                <div class="muted" style="margin-top: 3pt; font-size: 9.5pt;">Yayasan Vidatra · {{ $domain }}</div>
            </td>
            <td class="meta muted">
                Dicetak: {{ $printedAt }}<br>
                Oleh: {{ $printedBy }}
            </td>
        </tr>
    </table>

    <div class="filter-note">
        <strong>Data dalam laporan ini berdasarkan filter:</strong>
        @if ($caption['used'] === [])
            tanpa filter — semua data.
        @else
            {{ implode(' · ', $caption['used']) }}.
            @if ($caption['unused'] !== [])
                <span class="muted">Filter lain tidak dipakai ({{ implode(', ', $caption['unused']) }}: semua).</span>
            @endif
        @endif
    </div>

    <table class="kpis">
        <tr>
            @foreach ($kpis as $label => $value)
                <td><span class="label">{{ $label }}</span><span class="value">{{ number_format($value, 0, ',', '.') }}</span></td>
            @endforeach
        </tr>
    </table>

    <table class="pair">
        <tr>
            @foreach (['Aset per Sumber' => $sources, 'Kondisi Aset' => $conditions] as $title => $items)
                @php($drawn = array_values(array_filter($items, fn ($item) => $item['value'] > 0)))
                <td class="box">
                    <h2>{{ $title }}</h2>
                    <table class="stack">
                        <tr>
                            @forelse ($drawn as $item)
                                <td class="{{ $loop->last ? 'last' : '' }}" style="width: {{ $item['width'] }}%; background: {{ $item['color'] }};">&nbsp;</td>
                            @empty
                                <td class="last">&nbsp;</td>
                            @endforelse
                        </tr>
                    </table>
                    <table class="legend">
                        @foreach ($items as $item)
                            <tr>
                                <td class="sw"><span style="background: {{ $item['color'] }};"></span></td>
                                <td>{{ $item['label'] }}</td>
                                <td class="num">{{ number_format($item['value'], 0, ',', '.') }}</td>
                                <td class="num pct">{{ $item['percent'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="pair">
        <tr>
            @foreach (['Aset per Lokasi' => $locations, 'Aset per Kategori' => $categories] as $title => $rows)
                <td class="box">
                    <h2>{{ $title }}</h2>
                    @if ($rows === [])
                        <span class="muted">Tidak ada data.</span>
                    @else
                        <table class="bars">
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="label">{{ $row['label'] }}</td>
                                    <td>
                                        <div class="track">
                                            @if ($row['width'] > 0)
                                                <div class="fill" style="width: {{ $row['width'] }}%;"></div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="num count">{{ number_format($row['value'], 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <h2 style="margin-top: 12pt;">Aset per Ruangan</h2>
    <table class="rooms">
        <thead>
            <tr>
                <th>Ruangan</th>
                <th>Lokasi</th>
                <th class="num">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rooms as $room)
                <tr>
                    <td>{{ $room['name'] }}</td>
                    <td>{{ $room['location_name'] ?? '—' }}</td>
                    <td class="num">{{ number_format($room['asset_count'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="muted">Tidak ada aset yang sesuai dengan filter.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
