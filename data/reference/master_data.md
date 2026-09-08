# Master Data — Inventaris Vidatra (Tahap 1A)

Status: **dokumen referensi untuk review**. Bukan skema database, bukan migration.
Sumber: analisis 5 workbook di `excel/` (dibaca sel-per-sel).

## Keputusan yang diterapkan

1. `Kartu Inventaris Ruangan.xlsx` = template/contoh → isinya **diabaikan** untuk master data
   (termasuk klaim "kode lokasi 03 = SMA").
2. Lokasi: `01` = YAYASAN / PH (lokasi yang sama); `03` = SMP. Kode lokasi pada data aktual
   tidak diubah.
3. Subkategori & penomoran aset: **data Excel = source of truth**. Panduan hanya referensi.
   Tidak ada remap kode subkategori / nomor aset agar cocok dengan Panduan.
4. 1 baris Excel = 1 unit aset. Baris `005A` / `005B` dan pasangan komponen lain **tidak digabung**.

## Cakupan data aktual saat ini

| Kategori | File | Baris aset |
|---|---|---:|
| 02 MEUBELAIR | `excel/Inventaris Meubelair.xlsx` (sheet `02 MEUBELAIR`) | 186 |
| 03 ELEKTRONIK | `excel/Inventaris Elektronik.xlsx` (sheet `03 ELEKTRONIK`) | 192 |
| 06 ALAT KEBERSIHAN | `excel/Inventaris Alat Kebersihan.xlsx` (sheet `06 ALAT KEBERSIHAN`) | 16 |
| **Total** | | **394** |

Semua 394 baris memakai kode lokasi `01`. Kategori `01`, `04`, `05` belum memiliki file data.

---

## 1. Master Lokasi

Sumber: `Panduan Penomoran Inventaris.xlsx` (sheet `Nomor`), dikonfirmasi keputusan bisnis.

| kode | nama final | alias | ada data aset? | catatan |
|---|---|---|---|---|
| `01` | YAYASAN | PH | ✅ (394 baris) | Di file aset, sel LOKASI berlabel `PH`. `YAYASAN` (Panduan) dan `PH` = lokasi yang sama. |
| `02` | SD | – | ❌ | Belum ada data aset. |
| `03` | SMP | – | ❌ | **Final = SMP.** Template `Kartu Inventaris Ruangan.xlsx` menulis `UNIT KERJA: SMA` / `NO KODE LOKASI: 03` — diabaikan sesuai keputusan (1) & (2). |
| `04` | SMA | – | ❌ | Belum ada data aset. |

Catatan: Panduan memuat daftar lokasi ini dua kali (baris 4–7 dan baris 34–37) dengan isi identik.

---

## 2. Master Kategori

Sumber utama nama: `Panduan Penomoran Inventaris.xlsx`. Nama final memakai ejaan pada **data aktual**
bila berbeda.

| kode | nama final | nama di Panduan | ada data? | baris | catatan perbedaan |
|---|---|---|---|---:|---|
| `01` | TANAH DAN BANGUNAN | TANAH DAN BANGUNAN | ❌ | 0 | Panduan punya subkategori `01` TANAH, `02` BANGUNAN (2 digit). Belum ada file data. |
| `02` | MEUBELAIR | MEBEULAIR | ✅ | 186 | Ejaan berbeda. Data aktual (sheet + judul + header) memakai **MEUBELAIR**. |
| `03` | ELEKTRONIK | ELEKTRONIK | ✅ | 192 | Judul dalam file salah ketik `DAFTAR ELECKTRONIK`; header sel = `ELEKTRONIK`. |
| `04` | MEKANIK | MEKANIK | ❌ | 0 | Panduan: sub `001` ALAT PEMOTONG RUMPUT, `002` TOOLS, `003`–`013` kode tanpa nama. |
| `05` | ALAT RUMAH TANGGA | ALAT RUMAH TANGGA | ❌ | 0 | Panduan: sub `001` kode tanpa nama. |
| `06` | ALAT KEBERSIHAN | ALAT KEBERSIHAN | ✅ | 16 | Sama. |

---

## 3. Master Subkategori

**Aturan:** kode + nama diambil dari **header blok & baris data di file Excel** (source of truth).
Kolom "nama di Panduan" hanya informatif. Kolom "Δ" menandai perbedaan:
`=` sama · `+nama` Panduan tidak menamai · `~` beda redaksi · `!` **konflik arti pada kode yang sama** · `*` kode tidak ada di Panduan.

Kode subkategori **tidak unik lintas kategori** — selalu dipakai sebagai pasangan `(kategori_kode, subkategori_kode)`.

### 3.1 Kategori `02` — MEUBELAIR

| subkode | nama final (Excel) | baris | nama di Panduan | Δ |
|---|---|---:|---|:--:|
| `001` | MEJA | 35 | MEJA | `=` |
| `002` | KURSI | 73 | KURSI | `=` |
| `003` | LEMARI / RAK | 53 | LEMARI | `~` |
| `004` | SAFETY BOX | 1 | SAFETY BOX | `=` |
| `005` | PAPAN TULIS | 7 | *(kode tanpa nama)* | `+nama` |
| `006` | FOTO / GAMBAR | 14 | *(kode tanpa nama)* | `+nama` |
| `007` | TIANG BENDERA | 1 | *(kode tanpa nama)* | `+nama` |
| `008` | TANGGA | 2 | *(kode tanpa nama)* | `+nama` |

Subtotal: 186 baris. Header blok di file menyebut `001`–`007`; blok `008 TANGGA` muncul di data
tanpa tercantum di daftar "JENIS BARANG" file itu sendiri (tetap dipakai karena ada datanya).

### 3.2 Kategori `03` — ELEKTRONIK

| subkode | nama final (Excel) | baris | nama di Panduan | Δ |
|---|---|---:|---|:--:|
| `001` | KOMPUTER | 37 | KOMPUTER | `=` |
| `002` | LCD / KABEL | 6 | LCD | `~` |
| `003` | PRINTER | 27 | PRINTER | `=` |
| `004` | SCANNER | 2 | SCANNER | `=` |
| `005` | TELEVISI / TAPE / RADIO / CD / DVD | 3 | TAPE / RADIO | `~` |
| `007` | KIPAS ANGIN | 6 | TELEVISI | `!` |
| `008` | HANDYCAM / KAMERA FOTO | 6 | KAMERA FOTO | `~` |
| `009` | HANDYCAM / WEBCAM | 1 | HANDYCAM | `~` |
| `010` | SPEAKER | 11 | SPEAKER / SALON | `~` |
| `011` | AMPLIFIER / EQUALIZER / MIC | 15 | POWER / AMPLIFIER | `~` |
| `012` | TELEPON / HP | 12 | TELEPON / HP | `=` |
| `013` | BOR LISTRIK / GURINDA / KETAM | 3 | BOR LISTRIK | `~` |
| `014` | JAM | 7 | JAM DINDING | `~` |
| `015` | AC | 30 | AC | `=` |
| `016` | DISPENSER | 1 | DISPENSER | `=` |
| `018` | HUB INTERNET / MODEM | 6 | RISHOGRAPH | `!` |
| `020` | LAPTOP | 9 | KULKAS | `!` |
| `021` | KULKAS | 1 | *(tidak ada)* | `*` |
| `022` | PEMOTONG KERTAS | 5 | *(tidak ada)* | `*` |
| `023` | KALKULATOR ELECTRIC | 1 | *(tidak ada)* | `*` |
| `024` | ALAT KESEHATAN | 3 | *(tidak ada)* | `*` |

Subtotal: 192 baris. Kode `006`, `017`, `019` **tidak dipakai** (tanpa header blok & tanpa data) →
tidak dimasukkan ke master. Legend di dalam file menuliskan `009` sebagai "Webcam"; header blok
data menuliskan "HANDYCAM / WEBCAM" → nama final mengikuti header blok data.

### 3.3 Kategori `06` — ALAT KEBERSIHAN

| subkode | nama final (Excel) | baris | nama di Panduan | Δ |
|---|---|---:|---|:--:|
| `001` | TEMPAT SAMPAH | 14 | TEMPAT SAMPAH | `=` |
| `002` | VACUUM CLEANER | 1 | VACUUM CLEANER | `=` |
| `003` | HIGHT PRESURE (SEMPROTAN) | 1 | *(kode tanpa nama)* | `+nama` |

Subtotal: 16 baris. Catatan `003`: baris catatan di file menulis "003 HIGH PRESURE",
header blok menulis "HIGHT PRESURE (SEMPROTAN)" (ejaan tidak konsisten) → nama final mengikuti
header blok. Bisa disempurnakan menjadi "HIGH PRESSURE (SEMPROTAN)" pada tahap normalisasi
kalau Anda setuju (belum diubah karena source of truth = Excel).

---

## 4. Rekap perbedaan Panduan ↔ Data Aktual

| # | Lokasi/Kategori/Subkode | Panduan | Data aktual (final) | Tindakan |
|---|---|---|---|---|
| 1 | Lokasi `03` | SMP | SMP | Tidak ada konflik (template Kartu Ruangan diabaikan). |
| 2 | Kategori `02` | MEBEULAIR | MEUBELAIR | Pakai ejaan data aktual. |
| 3 | Sub `02/003` | LEMARI | LEMARI / RAK | Pakai data aktual. |
| 4 | Sub `02/005`–`008` | tak dinamai | PAPAN TULIS, FOTO/GAMBAR, TIANG BENDERA, TANGGA | Panduan perlu dilengkapi. |
| 5 | Sub `03/007` | TELEVISI | **KIPAS ANGIN** | Konflik. Ikuti data aktual; jangan remap. Panduan perlu direvisi. |
| 6 | Sub `03/018` | RISHOGRAPH | **HUB INTERNET / MODEM** | Konflik. Ikuti data aktual. |
| 7 | Sub `03/020` | KULKAS | **LAPTOP** | Konflik. Ikuti data aktual. |
| 8 | Sub `03/021`–`024` | tidak ada | KULKAS, PEMOTONG KERTAS, KALKULATOR ELECTRIC, ALAT KESEHATAN | Kode baru dari data aktual. |
| 9 | Sub `03/005` | TAPE / RADIO | TELEVISI / TAPE / RADIO / CD / DVD | Gabungan; ikuti data aktual. |
| 10 | Sub `03/006`,`017`,`019` | FOTO COPY, MEGAPHONE, CCTV | tidak dipakai (0 data) | Tidak dimasukkan master sekarang; bisa ditambah bila ada data. |
| 11 | Sub `06/003` | tak dinamai | HIGHT PRESURE (SEMPROTAN) | Panduan perlu dilengkapi. |

**Kesimpulan:** kode subkategori Elektronik pada Panduan sudah menyimpang dari praktik pencatatan
aktual sejak sekitar kode `005`. Karena data Excel tidak boleh diubah, master mengikuti Excel dan
Panduan diperlakukan sebagai dokumen historis yang perlu diperbarui.

---

## 5. Catatan penomoran aset (tidak diubah)

Nomor aset di 3 file tersimpan sebagai **5 bagian pada kolom terpisah**:
`lokasi(2) · kategori(2) · subkategori(3) · no_urut · tahun(4)`.

- `no_urut` **bukan integer murni**: ada `001`, `0001`, `005A`, `006B`, `0017A`, `0010B`.
  78 dari 192 baris Elektronik memakai akhiran huruf (menandai komponen; tiap baris tetap 1 aset).
- Simpan `no_urut` sebagai **string apa adanya**. Jangan pad/normalisasi, jangan remap.
- `nomor_aset` gabungan direkonstruksi dari 5 bagian; format pemisah untuk data lama tidak dipaksa
  seragam.
- Template Kartu Ruangan memakai pemisah tanda hubung (`03-02-001-002-2017`) — hanya relevan untuk
  laporan, tidak untuk master.

---

## 6. Master Ruangan (ringkas)

Detail lengkap ada di `data/reference/mapping_ruangan.md`. Ringkasan untuk konsistensi:

- Ruangan **bukan** master statis di dokumen ini — ia master data yang dikelola dari aplikasi
  (fitur **Kelola Ruangan**: tambah / ubah / nonaktifkan). Dokumen mapping hanya data awal
  untuk seeding.
- Setiap ruangan **berelasi ke lokasi**. Seluruh 15 ruangan hasil normalisasi berada di lokasi `01`.
- 15 ruangan kanonik lokasi `01`: Ruangan Ketua Harian, Ruangan Personalia & SARPRAS,
  Ruangan Admin Personalia / SARPRAS, Ruangan Pendidikan, Ruangan Keuangan, Ruangan Rapat,
  Ruangan Humas IT, Ruangan Usaha, Musholla, CS, Ruangan Cikini, Ruangan PLS, Gudang,
  WC Pria, WC Wanita.
- Seluruh 394 baris aset terpetakan ke 15 ruangan tersebut (tidak ada bucket `Lainnya`).
- **`Lainnya` bukan ruangan kanonik.** Saat import, string `Ruangan` yang tidak dikenali
  ditandai "belum terpetakan" untuk ditinjau manual — bukan dibuatkan master ruangan otomatis.
- Jangan hard-code daftar ruangan di aplikasi (frontend maupun backend).

---

## 7. Untuk dikonfirmasi pada tahap berikutnya (bukan bagian Tahap 1)

- Apakah Panduan akan direvisi mengikuti kode subkategori aktual (disarankan), atau tetap sebagai
  arsip terpisah.
- Ejaan yang ingin dibakukan untuk tampilan aplikasi (mis. `HIGHT PRESURE` → `HIGH PRESSURE`,
  kapitalisasi konsisten) — nilai sumber tetap dari Excel.
- Kategori `01`, `04`, `05`: apakah subkategori dari Panduan ikut di-seed sekarang sebagai master
  kosong, atau menunggu data.
