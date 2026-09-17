{{-- One label's markup (Tahap 6.0.1) — logo, title, QR, and the asset code as
     individual character cells (not plain text; see AssetLabelPdfService::codeCells()).
     `$baseTopMm`/`$baseLeftMm` place this label on its page (0,0 for a single-label
     PDF; a computed grid position for a batch A4 page) — everything else here is
     the shared geometry from labels._style, relative to that base. --}}
<div class="label-box" style="top: {{ $baseTopMm }}mm; left: {{ $baseLeftMm }}mm;">
    <div class="header-row">
        <div class="logo-box"><img src="{{ $logoDataUri }}" alt="Logo Yayasan Vidatra"></div>
        <div class="title-box"><span>{{ $titleText }}</span></div>
        <div class="qr-box"><img src="{{ $label['qrDataUri'] }}" alt="QR Code"></div>
    </div>
    {{-- Layout revision — `groupLeftOffsetMm` centers the whole cell GROUP
         within the code-row's available width (zero when the group already
         fills it, i.e. a long code — unchanged from before); cells are still
         positioned individually via absolute mm coordinates, just all shifted
         by the same offset, so this stays exactly as Dompdf-reliable as the
         rest of this file's positioning. --}}
    <div class="code-row">
        @foreach ($label['cells'] as $i => $char)
            <span
                class="code-cell"
                style="left: {{ $label['groupLeftOffsetMm'] + $i * $label['cellWidthMm'] }}mm; width: {{ $label['cellWidthMm'] }}mm; font-size: {{ $label['cellFontSizePt'] }}pt;"
            >{{ $char }}</span>
            @unless ($loop->last)
                <span class="code-divider" style="left: {{ $label['groupLeftOffsetMm'] + ($i + 1) * $label['cellWidthMm'] }}mm;"></span>
            @endunless
        @endforeach
    </div>
</div>
