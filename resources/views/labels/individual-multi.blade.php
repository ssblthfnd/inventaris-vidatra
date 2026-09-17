<!DOCTYPE html>
{{-- Individual-mode multi-asset PDF (R8 revision). One page PER ASSET, every
     page exactly the chosen physical label size (labels._style) — this is
     NOT an A4 sheet; labels.batch.blade.php (the actual A4 grid) is
     untouched and not reused here. Mirrors that file's own
     `page-break-after: always` mechanism exactly — the same dompdf-reliable
     multi-page technique this app already trusted, just one label per page
     instead of a grid of them, and every page sharing the SAME `@page` size
     (Individual mode always renders one size for the whole request; dompdf
     does not reliably support `@page` size varying page-to-page within one
     document, so nothing here ever asks it to). --}}
<html>
<head>
<meta charset="utf-8">
<title>Label Aset — Individual</title>
@include('labels._style')
<style>
    @page {
        size: {{ $pageWidthMm }}mm {{ $pageHeightMm }}mm;
        margin: 0;
    }

    .individual-page {
        position: relative;
        width: {{ $pageWidthMm }}mm;
        height: {{ $pageHeightMm }}mm;
        page-break-after: always;
    }

    .individual-page:last-child {
        page-break-after: avoid;
    }
</style>
</head>
<body>
@foreach ($labels as $label)
    <div class="individual-page">
        @include('labels._label', ['baseTopMm' => 0, 'baseLeftMm' => 0, 'label' => $label])
    </div>
@endforeach
</body>
</html>
