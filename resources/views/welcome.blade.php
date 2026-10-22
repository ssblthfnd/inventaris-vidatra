<!DOCTYPE html>
<html lang="id">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">

    <meta name="description" content="Sistem Inventaris Yayasan Vidatra: catat, cari, dan pantau aset setiap unit, lengkap dengan label QR, foto barang, sumber dana, dan laporan.">

    <title>Inventaris Vidatra</title>

    {{-- Sets data-theme before the app renders (no white flash); external because the CSP forbids inline scripts. --}}
    <script src="{{ asset('theme-init.js') }}?v={{ @filemtime(public_path('theme-init.js')) }}"></script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
    <body>
    <div id="app"></div>
</body>
</html>
