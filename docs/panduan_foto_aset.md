# Panduan — Foto Aset

Status: fitur Foto Aset (branch `feature/foto-dan-sumber-aset`). Bagian 1–5 untuk pengguna
aplikasi (operator, admin unit, admin); bagian 6–9 untuk pengelola server. Rincian API ada di
`docs/api_convention.md` §18, skema di `data/reference/schema_design.md` §2.11.

---

## 1. Ringkasan

- Setiap aset dapat memiliki **0 sampai 3 foto**.
- Foto **hanya dikelola dari halaman Detail Aset** (bagian **Foto Aset**). Form Tambah Aset dan
  Edit Aset tidak memiliki kolom foto; form Edit Aset menampilkan tautan ke halaman detail.
- Setelah aset baru disimpan, aplikasi membuka halaman detailnya — foto dapat langsung
  ditambahkan di sana.
- Foto **tidak** ditampilkan di daftar Inventaris, **tidak** dicetak di label QR, dan **tidak**
  ikut dalam Export Excel.

## 2. Menambahkan foto

Di halaman Detail Aset, bagian **Foto Aset**:

| Tombol | Kegunaan |
|---|---|
| **Ambil Foto** | Membuka kamera (di ponsel biasanya kamera belakang). Ini hanya petunjuk untuk browser — beberapa browser tetap menampilkan pilihan file atau galeri. |
| **Pilih dari Galeri** | Memilih foto yang sudah ada di perangkat/komputer. |

1. Pilih atau ambil foto. Aplikasi memperkecil foto di perangkat Anda (sisi terpanjang
   1600 piksel, JPEG) dan menghapus data lokasi/EXIF.
2. Periksa **pratinjau**, lalu tekan **Unggah Foto**. Selama pengunggahan tampil indikator
   kemajuan.
3. Setelah berhasil, daftar foto diperbarui dan **Riwayat Perubahan** mencatat
   "Foto ditambahkan" (mis. *Foto slot 2 ditambahkan (1600×1200, 312 KB)*).

Jika unggahan gagal, pesan kesalahan ditampilkan dan pratinjau tetap ada untuk dicoba
lagi atau dibatalkan. **Kegagalan unggah tidak menghapus atau mengubah foto lain.**

Server selalu memeriksa dan memproses ulang setiap foto (tidak bergantung pada kompresi di
browser). Tombol tambah hanya tampil untuk pengguna dengan hak **edit aset**; admin unit hanya
untuk aset di unitnya.

### Format yang didukung

- **JPEG** dan **PNG** (juga **WebP** bila didukung pustaka GD di server). Area transparan
  pada PNG menjadi putih.
- Ukuran file maksimal **15 MB** (batas aplikasi; batas server bisa lebih kecil — §7).
- Resolusi maksimal **12.000 piksel** pada sisi terpanjang dan **50 megapiksel** total.
- Semua foto disimpan sebagai JPEG (kualitas 80, sisi terpanjang maksimal 1600 piksel).

### Foto HEIC/HEIF (iPhone)

HEIC/HEIF **belum didukung** server. Biasanya Safari di iPhone mengubah foto ke JPEG secara
otomatis saat dipilih; bila browser tidak dapat membacanya, aplikasi menampilkan pesan. Solusi:

- iPhone: **Pengaturan → Kamera → Format → Most Compatible** (paling kompatibel), lalu ambil
  ulang foto; atau
- pilih/ekspor foto sebagai JPEG.

## 3. Melihat foto

Klik foto untuk membukanya dalam tampilan besar (tombol ‹ › atau panah kiri/kanan untuk
berpindah, **Tutup** atau Esc untuk keluar). Siapa pun yang boleh membuka detail aset boleh
melihat fotonya — termasuk *viewer*.

## 4. Menghapus foto — permanen

Tekan **Hapus** pada foto, lalu konfirmasi **Hapus Permanen**.

- **Foto yang dihapus tidak bisa dikembalikan.** Tidak ada Trash untuk foto, dan **Revert** di
  Riwayat Perubahan tidak memulihkan foto (peristiwa foto tidak dapat di-revert).
- Riwayat mencatat "Foto dihapus permanen" beserta slot, ukuran, dan dimensinya.

### Mengganti foto

Tidak ada tombol "ganti". Bila aset sudah memiliki 3 foto: hapus salah satu, lalu tambahkan
foto baru. Perhatikan urutannya — **foto yang sudah dihapus tidak kembali walaupun unggahan
foto baru gagal**. Siapkan foto pengganti terlebih dahulu. Jika aset belum penuh (kurang dari
3 foto), tambahkan foto baru dulu, baru hapus foto lama.

## 5. Foto dan status aset

| Status aset | Foto |
|---|---|
| Aktif / Write-off | Dapat dilihat, ditambah, dan dihapus seperti biasa. |
| Di Trash (dihapus sementara) | Foto **tetap tersimpan**. Hanya pengguna yang boleh memulihkan aset (dan admin unit untuk unitnya) yang dapat melihatnya; tidak dapat ditambah/dihapus. |
| Dipulihkan dari Trash | Foto terlihat kembali seperti sebelumnya. |

Revert atas perubahan data aset (edit, pindah ruangan, write-off, hapus/pulihkan) tidak
mengubah foto.

---

## 6. Penyimpanan & keamanan (pengelola server)

- File disimpan **privat** di `storage/app/private/asset-photos/{asset_id}/{uuid}.jpg`. Tidak ada
  URL publik, tidak ada symlink, tidak perlu `php artisan storage:link`. Nama file selalu UUID —
  nama file asli tidak dipakai.
- Database (`asset_photos`) hanya menyimpan metadata dan path relatif; isi foto tidak pernah
  masuk database maupun riwayat.
- Gambar hanya dilayani lewat `GET /api/assets/{asset}/photos/{photo}`: sesi login diperiksa dan
  aturan akses halaman detail (`AssetPolicy::viewDetail`) dijalankan pada **setiap** permintaan,
  termasuk permintaan bersyarat (ETag/304). Header: `Cache-Control: private, no-cache`,
  `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`,
  `Cross-Origin-Resource-Policy: same-origin`.
- Unggah dibatasi **30 permintaan per menit per pengguna**.
- `storage/app/private/.gitignore` (`*`) memastikan foto tidak pernah masuk Git.

## 7. Kebutuhan PHP & batas server

| Kebutuhan | Keterangan |
|---|---|
| Ekstensi **GD** | Wajib (decode, resize, encode JPEG). Periksa dukungan JPEG/PNG (dan WebP bila ingin menerima WebP): `php -r "print_r(gd_info());"`. Imagick tidak dipakai. |
| Ekstensi **fileinfo** | Wajib (deteksi tipe berdasarkan isi file). |
| Ekstensi **exif** | Opsional. Bila ada, orientasi foto JPEG dari kamera dikoreksi di server. Bila tidak ada, foto tetap diproses (browser modern biasanya sudah memutar foto sebelum mengunggah). |
| Izin tulis | User PHP harus dapat menulis `storage/app/private/` (folder `asset-photos` dibuat otomatis). |

Pengaruh `php.ini` (bisa lebih rendah di hosting daripada batas aplikasi):

- `upload_max_filesize` — file lebih besar ditolak PHP; pengguna melihat "Foto gagal diunggah.
  Ukuran file mungkin melebihi batas server". Disarankan ≥ 16M. Foto yang sudah dikompresi browser
  biasanya < 1 MB, jadi batas ini terutama berlaku bila browser tidak dapat mengompres.
- `post_max_size` — harus ≥ `upload_max_filesize`; bila terlampaui PHP membuang seluruh isi
  permintaan dan Laravel menjawab HTTP 413. Pengguna melihat "Ukuran foto melebihi batas yang
  diterima server. Coba foto dengan resolusi lebih kecil."
- Batas web server / proxy (mis. `LimitRequestBody` Apache, `client_max_body_size` nginx,
  `FcgidMaxRequestLen` mod_fcgid, batas body ModSecurity) menolak permintaan **sebelum** sampai ke
  PHP: biasanya HTTP 413 (pesan yang sama seperti di atas), pada mod_fcgid HTTP 500 ("Terjadi
  kesalahan pada server"), atau koneksi diputus — pengguna lalu melihat "Unggahan terputus sebelum
  server menjawab … ukuran foto mungkin melebihi batas server". Pastikan batas-batas ini ≥ 16M.
- `memory_limit` — sebelum decode, server memperkirakan kebutuhan memori
  (lebar × tinggi × faktor format + kanvas 1600×1600×4 byte, sisakan 16 MB) dan menolak foto yang
  tidak muat dengan pesan "Gambar terlalu besar untuk diproses server". Faktor per format
  (`config/inventory.php` → `photos.bytes_per_input_pixel`): **JPEG 6, WebP 6, PNG 9 byte/piksel**.
  PNG lebih besar karena decoder PNG di GD membuat salinan penuh gambar terlebih dahulu (terukur
  ±8,2 byte/piksel; JPEG/WebP ±4,9). Angka ini hasil pengukuran lokal (PHP 8.3, GD bawaan), belum
  diverifikasi di hosting produksi. 128M cukup untuk foto JPEG ponsel ±12 MP; 256M disarankan.
  `-1` (tanpa batas) tidak membuat pemrosesan bebas risiko — batas 12.000 px / 50 MP tetap
  berlaku.
- `max_execution_time` — pemrosesan satu foto biasanya < 1–2 detik; 30 detik memadai.

Semua batas aplikasi ada di `config/inventory.php` → `photos`.

## 8. Membersihkan file yatim (orphan)

File yatim = file di `asset-photos/` yang tidak lagi dirujuk database (mis. penghapusan file gagal
setelah barisnya terhapus — tercatat di log sebagai `asset_photo.file_delete_failed`). Command
manual, tidak terjadwal, tanpa queue/cron:

```bash
# 1. dry-run (bawaan): hanya menampilkan, tidak mengubah apa pun; file < 60 menit diabaikan
php artisan assets:photos:prune-orphans --older-than=1d

# 2. periksa baris "Target —" dan daftar "would delete", lalu hapus dengan batas sebanyak
#    jumlah yang tadi Anda periksa (contoh: 3 file)
php artisan assets:photos:prune-orphans --older-than=1d --delete --max-delete=3
```

`--older-than`: menit (90), atau 90m / 12h / 7d — minimal 10 menit.

**Selalu jalankan dry-run lebih dahulu.** Baris pertama keluaran menunjukkan target, mis.
`Target — environment: production, database: nama_db (connection mysql), disk: local`. Pastikan
itu memang database **pasangan** folder foto ini, lalu periksa satu per satu daftar
`would delete`. `--delete` **menghapus file secara permanen — tidak ada undo**; satu-satunya
jalan kembali adalah backup (§9).

Pengaman bawaan:

- `--delete` **ditolak** (exit 2, tidak ada yang dihapus) bila ditemukan file yatim padahal tabel
  `asset_photos` **kosong** — hampir pasti command membaca database yang salah (`--env`/`.env`
  keliru, atau restore belum selesai). Dry-run menampilkan catatan yang sama.
- `--max-delete=N` (opsional): `--delete` ditolak bila file yatim yang ditemukan **lebih dari N**.
  Tidak ada nilai bawaan — isi dengan jumlah yang Anda periksa di dry-run, agar penghapusan tidak
  melampaui apa yang sudah ditinjau (mis. bila database berubah di antara dua perintah).
- `--force` hanya melewati pengaman "tabel kosong" — untuk kasus yang memang sah, yaitu **semua**
  foto sudah dihapus dan tinggal file yatimnya. `--force` **tidak** melewati `--max-delete`,
  validasi argumen, maupun pemeriksaan path. Risikonya: bila database ternyata salah, `--force`
  menghapus foto yang masih dibutuhkan. Gunakan hanya setelah dry-run dan baris target diperiksa.

Aturan keamanan lain: hanya file bernama `{asset_id}/{uuid}.jpg` di dalam folder foto yang
dipertimbangkan; nama lain, direktori tak terduga beserta isinya, symlink, dan apa pun di luar
folder foto tidak pernah dihapus (hanya dilaporkan sebagai `ignored`); file yang lebih muda dari
`--older-than` dianggap unggahan yang mungkin sedang berjalan; rujukan database diperiksa ulang
tepat sebelum menghapus; baris tanpa file hanya dilaporkan (database tidak diubah). Setiap file
yang dihapus/gagal dihapus dan setiap penolakan dicatat di log (`asset_photo.orphan_deleted`,
`asset_photo.orphan_delete_failed`, `asset_photo.prune_refused`, ringkasan
`asset_photo.prune_finished`) dengan path relatif dan ID aset saja. Exit code: 0 berhasil, 1 ada
file yang gagal dihapus atau sebagian folder tidak terbaca, 2 argumen tidak valid atau penghapusan
ditolak.

**Jangan** menjalankan command ini dengan `--env` lain (mis. `--env=testing`) atau `.env` lain
tanpa memastikan database dan folder foto yang dipakai memang pasangan yang sama — folder foto
bisa sama sementara databasenya berbeda. Di hosting tanpa SSH, command ini tidak wajib
dijalankan — file yatim hanya memakan ruang disk.

## 9. Backup & deploy manual

**Backup.** Foto tidak ada di database. Backup harus mencakup **database** *dan* folder
`storage/app/private/asset-photos/` (mis. lewat File Manager/Backup cPanel), diambil pada titik
waktu yang sama. Pulihkan keduanya bersama-sama agar baris `asset_photos` dan file tetap cocok.

**Restore dan file yatim.** Restore cPanel/arsip mempertahankan waktu file asli, sehingga foto
hasil restore terlihat "lama" dan batas `--older-than` tidak melindunginya. Karena itu:

1. **Jangan** menjalankan `assets:photos:prune-orphans` selama restore belum selesai (mis. folder
   foto sudah dipulihkan, database belum — atau sebaliknya).
2. Pulihkan database **dan** folder foto dari titik backup yang **sama**. Bila database
   dikembalikan ke titik yang lebih lama daripada folder foto, foto yang lebih baru akan tampak
   yatim; jangan menghapusnya sebelum yakin database itu memang yang akan dipakai seterusnya.
3. Setelah restore, jalankan dry-run (§8): periksa baris `Target —`, pastikan tidak ada lonjakan
   `would delete`, dan perhatikan peringatan `row #… has no file` (foto yang dirujuk database
   tetapi tidak ada di disk — tanda folder foto belum lengkap dipulihkan).
4. Baru setelah itu, bila memang perlu, jalankan `--delete --max-delete=N`.

**Deploy (tanpa Node.js di server):**

1. Lokal: `npm run build` → hasil di `public/build/`.
2. Unggah kode PHP yang berubah dan folder `public/build/` (ganti seluruh isinya) ke server.
3. Jalankan migration: `php artisan migrate --force` (menambah tabel `asset_photos` dan dua nilai
   `event_type`). Bila `artisan` tidak dapat dijalankan dari hosting, gunakan mekanisme migrasi
   yang biasa dipakai untuk rilis sebelumnya.
4. Pastikan `storage/app/private/` dapat ditulis PHP; jangan buat symlink publik untuk foto.
5. Periksa `php.ini` (§7) dan ekstensi GD/fileinfo.
6. Uji: tambah, lihat, dan hapus satu foto pada aset uji.

**Rollback migration.** `migrate:rollback` untuk fitur ini **menolak berjalan** bila masih ada
baris foto atau riwayat `PHOTO_ADDED`/`PHOTO_DELETED` — agar file tidak yatim dan riwayat tidak
rusak. Jangan menghapus riwayat untuk memaksa rollback. Perhatikan: versi aplikasi sebelum fitur
ini tidak mengenal nilai `PHOTO_ADDED`/`PHOTO_DELETED`, sehingga riwayat aset yang memiliki
peristiwa foto akan gagal dimuat bila kode dikembalikan ke versi lama — koordinasikan dengan
pengembang sebelum melakukan downgrade.
