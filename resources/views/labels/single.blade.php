<!DOCTYPE html>
{{-- Single asset-label PDF (Tahap 6.0.1; size selectable Tahap 6.0.2). ONE page,
     exactly the requested physical label size (one of config('inventory.label.sizes'))
     — the label's own border sits flush against the page edge, so there is
     deliberately no page margin/padding of any kind here. Read-only rendering:
     every value was already resolved by AssetLabelPdfService before this view runs. --}}
<html>
<head>
<meta charset="utf-8">
<title>Label Aset</title>
@include('labels._style')
<style>
    @page {
        size: {{ $pageWidthMm }}mm {{ $pageHeightMm }}mm;
        margin: 0;
    }
</style>
</head>
<body>
    @include('labels._label', ['baseTopMm' => 0, 'baseLeftMm' => 0])
</body>
</html>
