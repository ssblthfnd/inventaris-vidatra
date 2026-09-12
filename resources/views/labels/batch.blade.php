<!DOCTYPE html>
{{-- Batch asset-label PDF (Tahap 6.0.1; size selectable Tahap 6.0.2). A4 portrait
     pages, each holding a deterministic grid of labels at explicit x/y coordinates
     computed by AssetLabelPdfService::labelsPerPage() / ::gridPosition() —
     dompdf's own page-breaking never decides how many labels fit a page; `$pages`
     here is already chunked to exactly that capacity, one chunk per `.a4-page`.
     Every physical label stays exactly the requested size (labels._style) — only
     the sheet around it, and the cutting spacing between labels, is A4-specific. --}}
<html>
<head>
<meta charset="utf-8">
<title>Label Aset — Batch</title>
@include('labels._style')
<style>
    @page {
        size: {{ $a4WidthMm }}mm {{ $a4HeightMm }}mm;
        margin: 0;
    }

    .a4-page {
        position: relative;
        width: {{ $a4WidthMm }}mm;
        height: {{ $a4HeightMm }}mm;
        page-break-after: always;
    }

    .a4-page:last-child {
        page-break-after: avoid;
    }
</style>
</head>
<body>
@foreach ($pages as $pageItems)
    <div class="a4-page">
        @foreach ($pageItems as $item)
            @include('labels._label', [
                'baseTopMm' => $item['position']['yMm'],
                'baseLeftMm' => $item['position']['xMm'],
                'label' => $item['label'],
            ])
        @endforeach
    </div>
@endforeach
</body>
</html>
