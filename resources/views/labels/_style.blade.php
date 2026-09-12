{{-- Shared label geometry CSS (Tahap 6.0.1; parametrized by print size in Tahap
     6.0.2) — included by both labels.single and labels.batch so the two never
     drift apart. Every rule here is the label's fixed internal geometry (the same
     proportional design regardless of single/batch or which of small/medium/large
     was requested); only `.label-box`'s own top/left differ per instance, set
     inline by _label.blade.php (see AssetLabelPdfService::labelLayout() /
     ::gridPosition()). --}}
<style>
    * {
        box-sizing: content-box;
    }

    body {
        margin: 0;
        padding: 0;
        font-family: Helvetica, Arial, sans-serif;
    }

    .label-box {
        position: absolute;
        width: {{ $boxWidthMm }}mm;
        height: {{ $boxHeightMm }}mm;
        border: 0.8mm double #000000;
    }

    .header-row {
        position: absolute;
        top: {{ $headerTopMm }}mm;
        left: {{ $headerLeftMm }}mm;
        width: {{ $headerWidthMm }}mm;
        height: {{ $headerHeightMm }}mm;
    }

    .logo-box {
        position: absolute;
        top: 0;
        left: 0;
        width: {{ $headerHeightMm }}mm;
        height: {{ $headerHeightMm }}mm;
        text-align: center;
    }

    .logo-box img {
        height: {{ max(0.5, $headerHeightMm - 0.4) }}mm;
        width: auto;
    }

    .qr-box {
        position: absolute;
        top: 0;
        left: {{ $headerWidthMm - $headerHeightMm }}mm;
        width: {{ $headerHeightMm }}mm;
        height: {{ $headerHeightMm }}mm;
    }

    .qr-box img {
        height: {{ max(0.5, $headerHeightMm - 0.2) }}mm;
        width: {{ max(0.5, $headerHeightMm - 0.2) }}mm;
    }

    .title-box {
        position: absolute;
        top: 0;
        left: {{ $headerHeightMm }}mm;
        width: {{ $headerWidthMm - (2 * $headerHeightMm) }}mm;
        height: {{ $headerHeightMm }}mm;
        text-align: center;
        overflow: hidden;
    }

    .title-box span {
        display: inline-block;
        line-height: {{ $headerHeightMm }}mm;
        font-weight: bold;
        font-size: {{ $titleFontSizePt }}pt;
        letter-spacing: 0.1mm;
        white-space: nowrap;
    }

    .code-row {
        position: absolute;
        top: {{ $codeTopMm }}mm;
        left: {{ $codeLeftMm }}mm;
        width: {{ $codeWidthMm }}mm;
        height: {{ $codeHeightMm }}mm;
        border: 0.5pt solid #000000;
        overflow: hidden;
    }

    .code-cell {
        position: absolute;
        top: 0;
        height: 100%;
        line-height: {{ $codeHeightMm }}mm;
        text-align: center;
        font-family: 'Courier New', Courier, monospace;
        font-weight: bold;
        white-space: nowrap;
    }

    .code-divider {
        position: absolute;
        top: 0;
        height: 100%;
        width: 0;
        border-left: 0.5pt solid #000000;
    }
</style>
