# Mockup Dashboard & Laporan (disetujui Oktober 2026)

Folder ini adalah **acuan desain** untuk mendesain ulang halaman Dashboard dan Laporan,
termasuk mode terang/gelap untuk seluruh website. Isinya HTML statis biasa: tidak dipakai
oleh aplikasi, tidak ikut build, dan tidak perlu server.

| File | Isi |
| --- | --- |
| `dashboard.html` | Dashboard: KPI, Sumber · Lokasi · Kondisi, Kategori, Aset per Ruangan |
| `laporan.html` | Halaman Laporan: filter, keterangan filter aktif, grafik, tabel, tombol Unduh PDF |
| `laporan-pdf.html` | Contoh isi PDF (A4 potret, selalu terang) |
| `assets/mockup.css` | Token warna terang/gelap dan semua gaya komponen |
| `assets/mockup.js` | Data contoh, perender grafik, tombol mode, pencarian/filter/paginasi ruangan |

**Cara membuka:** klik dua kali file HTML-nya (atau `file://…/docs/mockups/dashboard.html`).
Tambahkan `?data=contoh` untuk melihat grafik dengan data contoh yang lebih beragam
(mis. `dashboard.html?data=contoh`). Ubah lebar jendela untuk melihat tampilan tablet/HP.

> **Angka di mockup hanya ilustrasi.** Data bawaan meniru kondisi Oktober 2026
> (394 aset, semua Sumber = Yayasan); nama ruangan SD/SMP/SMA dikarang. Implementasi
> harus memakai data asli dari API (`DashboardController`, `ReportController`).

## Tempat implementasi

- React: `resources/js/pages/Dashboard.jsx`, `resources/js/pages/Reports.jsx`,
  layout di `resources/js/layouts/`, komponen bersama di `resources/js/components/`.
- API: `app/Http/Controllers/Api/DashboardController.php` (`GET /api/dashboard`),
  `app/Http/Controllers/Api/ReportController.php` (`GET /api/reports/inventory`).
- PDF: `barryvdh/laravel-dompdf` sudah terpasang (dipakai `AssetLabelController`).
- Gaya pakai Tailwind 4 (`resources/css/app.css`). Salin token warna di bawah sebagai
  variabel CSS / tema Tailwind; jangan menyalin `mockup.css` mentah-mentah.

## Warna

Palet dasar biru pucat: `#d5efff` `#a8d0ea` `#6f9bb8` `#f9f7f3` `#2e4a5b`.

| Token | Terang | Gelap | Dipakai untuk |
| --- | --- | --- | --- |
| `--bg` | `#f9f7f3` | `#1b2b35` | latar halaman |
| `--surface` | `#ffffff` | `#233846` | kartu, tabel |
| `--border` | `#e4e7ea` | `#2f4858` | garis kartu |
| `--divider` | `#eef0f1` | `#2b4252` | garis antarbaris |
| `--text` | `#2e4a5b` | `#f9f7f3` | teks utama, angka |
| `--muted` | `#4f6b7c` | `#b9ccd8` | label, keterangan |
| `--track` | `#eaf1f6` | `#2b4252` | latar batang grafik |
| `--bar` | `#6f9bb8` | `#a8d0ea` | batang grafik satu warna |
| `--accent` / `--accent-text` | `#2e4a5b` / `#fff` | `#a8d0ea` / `#1b2b35` | tombol utama |
| `--chip-bg` / `--chip-text` | `#d5efff` / `#2e4a5b` | `#2e4a5b` / `#d5efff` | chip filter aktif |
| `--input-bg` / `--input-border` | `#fff` / `#c9d4db` | `#1b2b35` / `#3a576a` | input & select |

**Sumber aset**: warna kategori tetap, urutannya jangan diubah (sudah dicek aman untuk
buta warna, terang dan gelap):

| Sumber | Terang | Gelap |
| --- | --- | --- |
| BOSDA | `#2a78d6` | `#3987e5` |
| BOSP | `#eb6834` | `#d95926` |
| BOSTK | `#1baf7a` | `#199e70` |
| Yayasan | `#eda100` | `#c98500` |
| Lainnya | `#e87ba4` | `#d55181` |
| Belum diberi keterangan | `#9ca3af` | `#7c8d99` |

**Kondisi**: warna status, sama di kedua mode. Baik `#0ca30c`, Kurang Baik `#fab219`,
Rusak Berat `#d03b3b`, Tidak diketahui `#9ca3af` (gelap `#7c8d99`).

## Aturan grafik

- Tanpa library grafik: cukup `div` + CSS seperti di mockup (ringan, lolos CSP).
- **Sumber** dan **Kondisi**: satu batang bertumpuk 100% (tinggi 24px, celah 2px antar
  segmen) + legenda di bawahnya. Legenda **selalu** menampilkan semua kategori, termasuk
  yang nilainya 0, dengan jumlah dan persen (`394` · `100%`). Segmen bernilai 0 tidak digambar.
- **Lokasi** dan **Kategori**: batang horizontal satu warna (`--bar`), **diurutkan dari
  terbesar**, label di kiri atas, angka di kanan atas. Kategori memakai dua kolom di layar lebar.
- **Tahun** (hanya Laporan): grafik kolom per tahun pengadaan, urut tahun.
- Tidak ada diagram lingkaran/donat. Angka memakai `font-variant-numeric: tabular-nums`.
- Setiap grafik punya teks alternatif (`role="img"` + `aria-label` berisi nilai-nilainya).

## Dashboard — susunan

1. Judul + keterangan singkat.
2. **Baris KPI**: Total aset, Aset aktif, Ditulis off, Lokasi, Kategori.
3. **Satu baris tiga kartu**: Aset per Sumber · Aset per Lokasi · Kondisi Aset.
4. **Aset per Kategori** (lebar penuh, batang dua kolom).
5. **Aset per Ruangan** (lebar penuh) dengan:
   - kotak cari nama ruangan, filter Lokasi, urutan (Jumlah terbanyak / Nama A–Z),
     jumlah per halaman (10 / 25);
   - tabel dua kolom: Ruangan (nama, dengan lokasi sebagai teks kecil di bawahnya) · Jumlah;
     bila hasil kosong: "Tidak ada ruangan yang cocok dengan filter.";
   - paginasi: teks "Menampilkan 1–10 dari 18 ruangan", nomor "1 / 2", tombol
     ‹ Sebelumnya / Selanjutnya › (nonaktif di halaman pertama/terakhir); ganti filter
     atau cari → kembali ke halaman 1.
   - Pencarian/filter/paginasi sebaiknya di server (query param ke API) bila ruangan banyak.

**Responsif** (pakai `flex-wrap`, bukan breakpoint kaku):

- Desktop (≥1024px): tiga kartu sejajar; KPI satu baris.
- Tablet (~768px): Sumber + Lokasi sebaris, Kondisi turun ke baris berikutnya; KPI terbungkus.
- HP (≤480px): semua kartu satu kolom; kontrol filter ruangan bertumpuk.
- Tinggi tombol dan input minimal 44px. Tidak boleh ada scroll horizontal.

## Mode terang/gelap — berlaku untuk SEMUA halaman

Termasuk landing page, login, dan semua halaman setelah login.

- Tombol bulat kecil 40px, `position: fixed` di **pojok kanan bawah** (16px dari tepi),
  agak transparan (opacity .72). Saat hover/fokus: tombol jadi penuh dan label
  ("Ganti ke mode gelap" / "Ganti ke mode terang") muncul di sebelah kirinya.
- Ikon: bulan saat mode terang (ajakan pindah ke gelap), matahari saat mode gelap.
- `aria-label` sama dengan teks label, bisa dipakai dengan keyboard,
  menghormati `prefers-reduced-motion`.
- Pilihan disimpan di `localStorage` (bungkus try/catch). Bila belum pernah memilih,
  ikuti `prefers-color-scheme` sistem.
- Pasang atribut `data-theme="light|dark"` di `<html>` **sebelum** React dirender
  (skrip kecil di Blade, file eksternal karena CSP tidak mengizinkan skrip inline)
  supaya tidak berkedip putih.
- Tombol tidak boleh menutupi kontrol penting (beri ruang bawah di halaman, mis. padding 72px).
- Label aset (QR) dan PDF tidak terpengaruh mode: selalu terang.

## Laporan — halaman

- Filter (enam select): Lokasi, Kategori, Kondisi, Status, Tahun, Sumber. Opsi diambil
  dari data master yang ada, bukan ditulis tetap. Tombol "Reset semua" mengosongkan filter.
- Di bawah filter: baris "Filter aktif:" berisi chip untuk tiap filter yang dipilih
  (atau "Semua data" bila kosong), lalu tombol **Unduh PDF** di kanan.
- Isi mengikuti filter: KPI, Sumber/Kondisi/Lokasi, Kategori, Tahun, tabel Aset per Ruangan
  (tabel di laporan tanpa kotak cari/paginasi; tampilkan semua baris hasil filter).
- Mode terang/gelap sama seperti halaman lain.

## Laporan — PDF

- Dibuat di server (dompdf) dari **filter yang sama** dengan yang sedang dipilih
  (kirim sebagai query string, validasi ulang di server, cek izin `assets.report`).
- A4 potret, **selalu mode terang**, tanpa tombol mode, tanpa kontrol filter.
- Kepala: "Laporan Inventaris", nama yayasan + domain, tanggal & jam cetak (WIB), nama
  pengguna yang mencetak.
- Kotak keterangan filter tepat di bawah kepala, contoh:
  "**Data dalam laporan ini berdasarkan filter:** Lokasi: YAYASAN · Status: Aktif.
  Filter lain tidak dipakai (Kategori, Kondisi, Tahun, Sumber: semua)."
  Tanpa filter: "tanpa filter — semua data."
- Isi: 5 KPI, Sumber + Kondisi (batang bertumpuk + legenda angka & %), Lokasi + Kategori
  (batang horizontal urut terbesar), tabel Ruangan · Lokasi · Jumlah.
- Kaki tiap halaman: "Laporan Inventaris — Yayasan Vidatra" dan "Halaman X dari Y".
  Kepala tabel diulang di halaman berikutnya; baris tabel tidak terpotong.
- dompdf tidak mendukung flex/grid dengan baik: susun ulang tata letak PDF dengan tabel
  atau `display: inline-block`, warna ditulis langsung (bukan variabel CSS), font
  bawaan dompdf (DejaVu Sans) supaya tidak perlu unduh font.
- Nama file: `laporan-inventaris-YYYYMMDD-HHmm.pdf`.

## Batasan yang wajib dijaga

- **CSP ketat** (`app/Http/Middleware/SecurityHeaders.php`): `default-src 'self'`,
  `img-src 'self' data:`, `font-src 'self' data:`. Tidak boleh CDN, Google Fonts, atau
  skrip inline. Gaya inline (`style=""`) diizinkan.
- **MariaDB 10.11 di server**: jangan pakai collation `utf8mb4_0900_ai_ci`; kolom CHAR di
  generated column dibungkus `RTRIM()`. Agregasi dashboard/laporan cukup `GROUP BY` biasa.
- Hosting tanpa Node, queue, dan cron: PDF dibuat langsung di request; `public/build`
  di-build di laptop lalu diunggah.
- `funding_source` lama tetap terpisah dari Sumber (`BOSDA/BOSP/BOSTK/Yayasan/Lainnya`);
  aset tanpa Sumber tampil sebagai "Belum diberi keterangan".
- Tambahkan/ubah test Feature untuk endpoint yang berubah dan untuk PDF (status 200,
  `Content-Type: application/pdf`, filter tak valid ditolak, pengguna tanpa izin ditolak).
