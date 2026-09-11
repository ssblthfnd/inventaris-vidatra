# Database Schema Design — Inventaris Vidatra (Tahap 2)

Status: **dokumen desain FINAL untuk review**. Bukan migration, bukan kode.
Tidak ada perubahan pada file Excel, `data/reference/master_data.md`, atau
`data/reference/mapping_ruangan.md`.

Revisi 2 — menerapkan **keputusan bisnis final** (lihat §0). Perubahan utama vs revisi 1:
penomoran `sequence_no` **berlanjut lintas tahun**, `room_aliases` & pencocokan ruangan
**selalu scoped per lokasi**, field `room_status` **dihapus** (turunan dari `room_id IS NULL`),
`assets.quantity` **dikunci = 1** (CHECK), `year` → `asset_year`, generator + concurrency
dijelaskan eksplisit, `mutation_logs` tanpa `updated_at`, catatan kompatibilitas produksi cPanel
ditambahkan (§14).

Sumber:
1. `data/reference/master_data.md` (Tahap 1A — keputusan master data)
2. `data/reference/mapping_ruangan.md` (Tahap 1B — keputusan ruangan & alias)
3. `data/excel/struktur_data_inventaris.md` (draft analisis awal — sebagian **disempurnakan / digantikan** oleh Tahap 1 & keputusan bisnis, lihat §12)

Target DBMS: **MySQL 8.0.16+ / InnoDB**, charset `utf8mb4`, collation `utf8mb4_0900_ai_ci`
(case- & accent-insensitive — membantu pencocokan alias ruangan & nama). Semua tabel
`ENGINE=InnoDB`, `ROW_FORMAT=DYNAMIC`. **Verifikasi versi DB produksi sebelum Tahap 3 — §14.**

Konvensi penamaan: tabel & kolom **bahasa Inggris, snake_case, plural untuk tabel**
(mengikuti daftar tabel yang diminta). Nilai enum memakai slug `snake_case`.

---

## 0. Keputusan Bisnis Final (mengunci desain)

| # | keputusan | dampak schema |
|---|---|---|
| B1 | **Penomoran aset berlanjut lintas tahun.** Scope nomor urut = `location_code + category_code + subcategory_code`. `asset_year` **bukan** bagian scope generator. Contoh: 2025 → 001,002,003 · 2026 → 004,005,006 · 2027 → 007,008,009. | Generator (§5.3) query `MAX` per triplet **tanpa** filter `asset_year`. `asset_year` tetap komponen nomor & bagian identitas unik. |
| B2 | **`sequence_no` = STRING, nilai Excel apa adanya.** Valid: `001`, `0001`, `005A`, `005B`, `0017A`, `0017B`. Tidak ada normalisasi/padding untuk data existing. | `sequence_no VARCHAR(10)`. `struktur_data_inventaris.md` yang menulis `no_urut INT` **digantikan** (§12 D1). |
| B3 | **1 baris Excel = 1 physical asset.** `005A` & `005B` = dua record berbeda. | Tidak ada penggabungan. `uq_assets_number` memakai `sequence_no` penuh. `quantity` dikunci `= 1` (§2.6, B6). |
| B4 | **`Kartu Inventaris Ruangan.xlsx` = template/contoh, bukan source of truth.** Actual Excel inventory = source of truth. | Tidak ada tabel/kolom yang diturunkan dari template. Kartu Ruangan = laporan query atas `assets`. |
| B5 | **Tidak ada canonical room `Lainnya`.** Room tak dikenal → status unmapped (`room_id IS NULL`) + masuk review. | Tidak pernah `INSERT INTO rooms` otomatis saat import. Lihat §6, §11 I9–I10. |
| B6 | **`assets.quantity` selalu `1`** — satu record = satu unit. Tidak mendukung grouped asset. | `quantity INT UNSIGNED NOT NULL DEFAULT 1` + `CHECK (quantity = 1)`. Kolom dipertahankan untuk future extensibility (§12 D7). |
| B7 | **`room_aliases` & pencocokan ruangan selalu scoped per lokasi.** `GUDANG` di lokasi `01` ≠ `GUDANG` di lokasi `03`. | `room_aliases.location_code` NN; unique `(location_code, match_key)`; composite FK ke `rooms`. Pencocokan nama kanonik juga wajib `location_code` + nama ternormalisasi. Lihat §1 revisi wajib, §6. |

---

## 1. Entity Relationship Overview

### 1.1 Domain inti

```
locations (4 baris tetap)
   │  1─N
   ├── rooms (master data aplikasi: tambah / edit / nonaktif)
   │      │  1─N
   │      ├── room_aliases (raw string Excel → room, SELALU scoped per lokasi)
   │      └── assets (via rooms) ── penempatan aset saat ini
   │
   ├── room_aliases ── setiap alias milik satu lokasi (room_aliases.location_code)
   ├── assets ── lokasi struktural aset (komponen-1 nomor aset)
   └── mutation_logs ── from_location_code / to_location_code

categories (6 baris tetap)
   │  1─N
   └── subcategories (natural key: category_code + code)
          │  1─N
          └── assets

assets (inti — 1 baris = 1 physical unit, quantity selalu 1)
   │  1─N
   └── mutation_logs (histori, append-only)

users
   ├── 1─N mutation_logs.performed_by
   ├── 1─N assets.created_by / updated_by
   ├── 1─N room_aliases.created_by
   └── 1─N import_batches.uploaded_by

import_batches (1 file/sheet = 1 kategori aset)
   │  1─N
   └── import_rows (baris mentah + status validasi)
          └── 0/1 assets (hasil promosi)
```

### 1.2 Prinsip desain yang dipegang

| # | Prinsip | Sumber |
|---|---|---|
| P1 | Kode `location` / `category` / `subcategory` = **string identifier**, bukan integer. Leading zero signifikan. | master_data.md §1–2, prompt |
| P2 | Kode subkategori **tidak unik lintas kategori** → selalu pasangan `(category, subcategory)`. | master_data.md §3 |
| P3 | 1 baris Excel = 1 unit aset. `005A` / `005B` / `0017A` **tidak digabung**. | master_data.md §Keputusan(4), §5 · B3 |
| P4 | `sequence_no` disimpan **string apa adanya** — tanpa padding, cast, normalisasi. | master_data.md §5 · B2 |
| P5 | Data Excel = source of truth. Panduan Penomoran = referensi historis, **tidak** dipaksakan ke skema. | master_data.md §4 · B4 |
| P6 | Ruangan = master data, **tidak hard-code**. Bisa dinonaktifkan (soft-disable, bukan hapus). | mapping_ruangan.md §5 |
| P7 | Tidak ada ruangan kanonik `Lainnya`. Raw value tak dikenal → status unmapped, bukan auto-create room. | mapping_ruangan.md §5(5) · B5 |
| P8 | Histori mutasi tidak boleh hilang saat ruangan dinonaktifkan / user dihapus. | prompt |
| P9 | Nomor aset lama tidak boleh rusak; generator nomor baru harus tetap bisa berjalan, **berlanjut lintas tahun**. | struktur_data_inventaris.md §5 · B1 |
| P10 | Semua lookup ruangan (alias & nama kanonik) **wajib scoped per `location_code`**. Nama sama di lokasi berbeda = master room berbeda. | B7 |

---

## 2. Table-by-Table Design

Notasi: **PK** primary key · **FK** foreign key · **U** unique · **IX** index ·
`NN` NOT NULL · `NULL` nullable.

---

### 2.1 `locations`

**Tujuan:** master lokasi struktural (YAYASAN/PH, SD, SMP, SMA). Tabel referensi statis,
hanya diubah admin. Induk `rooms`, `room_aliases`, dan komponen pertama nomor aset.

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `code` | `CHAR(2)` | NN | — | **PK**. `'01'` YAYASAN, `'02'` SD, `'03'` SMP, `'04'` SMA. Digit, leading zero signifikan → `CHAR`, bukan INT (P1). |
| `name` | `VARCHAR(50)` | NN | — | Nama final (mis. `YAYASAN`). |
| `alias` | `VARCHAR(50)` | NULL | `NULL` | Alias tampilan (mis. `PH` untuk `01`). master_data.md §1. |
| `is_active` | `TINYINT(1)` | NN | `1` | Menyembunyikan lokasi yang belum dipakai dari pilihan input tanpa menghapus. |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | Audit (Laravel). |

- **PK:** `code`
- **U:** `uq_locations_name (name)`
- **FK:** —
- **IX:** PK cukup (≤ 4 baris)
- **Relasi:** `locations 1─N rooms`, `1─N room_aliases`, `1─N assets`, `1─N mutation_logs (from/to_location_code)`
- **Alasan desain:** natural PK `CHAR(2)` karena (a) kode **adalah** data — muncul literal di nomor aset; (b) immutable per keputusan bisnis ("kode lokasi aktual harus dipertahankan"); (c) hanya 4 baris, tidak akan tumbuh; (d) FK terbaca natural (`assets.location_code`). Surrogate id tidak memberi manfaat integritas, hanya indireksi.

---

### 2.2 `categories`

**Tujuan:** master kategori barang (6 kode dari Panduan, nama final mengikuti Excel bila beda ejaan).
Referensi statis. Induk `subcategories`, komponen kedua nomor aset.

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `code` | `CHAR(2)` | NN | — | **PK**. `'01'`..`'06'`. `CHAR`, bukan INT (P1). |
| `name` | `VARCHAR(50)` | NN | — | Nama final, ejaan **data aktual**: `MEUBELAIR` (bukan `MEBEULAIR`), `ELEKTRONIK`, `ALAT KEBERSIHAN`, `TANAH DAN BANGUNAN`, `MEKANIK`, `ALAT RUMAH TANGGA`. master_data.md §2. |
| `is_active` | `TINYINT(1)` | NN | `1` | `01`, `04`, `05` belum ada data — bisa disembunyikan dari input sampai dipakai. |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | Audit. |

- **PK:** `code`
- **U:** `uq_categories_name (name)`
- **FK:** —
- **IX:** PK cukup (≤ 6 baris)
- **Relasi:** `categories 1─N subcategories`, `1─N assets` (lewat pasangan kode), `1─N import_batches`
- **Alasan desain:** sama dengan `locations` — kode adalah data, immutable, set kecil dan tetap.

---

### 2.3 `subcategories`

**Tujuan:** master subkategori per kategori. Kode subkategori **hanya unik dalam satu kategori**
(P2). Nama & kode diambil dari **header blok + baris data Excel** (source of truth), bukan Panduan.

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK (surrogate)**. Identitas internal & CRUD admin. |
| `category_code` | `CHAR(2)` | NN | — | **FK → `categories.code`**. Bagian natural key. |
| `code` | `CHAR(3)` | NN | — | `'001'`..`'024'` dst. Tidak unik global (P2). `CHAR(3)`, bukan INT. |
| `name` | `VARCHAR(120)` | NN | — | Nama final dari header blok Excel (mis. `KIPAS ANGIN` untuk `03/007`, bukan `TELEVISI`). |
| `guide_name` | `VARCHAR(120)` | NULL | `NULL` | Nama versi Panduan (informasi historis, mis. `TELEVISI`). Opsional — dari master_data.md §3. |
| `is_active` | `TINYINT(1)` | NN | `1` | Kode `006`/`017`/`019` Elektronik tidak dipakai → tidak di-seed / non-aktif. |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | Audit. |

- **PK:** `id`
- **U:**
  - `uq_subcategories_natural (category_code, code)` — **natural key**, target composite FK dari `assets`.
  - `uq_subcategories_cat_name (category_code, name)` — cegah dua subkategori bernama sama dalam satu kategori.
- **FK:** `category_code → categories.code` `ON UPDATE RESTRICT ON DELETE RESTRICT`
- **IX:** `uq_subcategories_natural` melayani filter `category_code` (leftmost prefix)
- **Relasi:** `subcategories 1─N assets` (via `(category_code, subcategory_code)`)
- **Alasan desain — surrogate vs composite (jawaban eksplisit):** dipakai **kombinasi keduanya**:
  - **Surrogate `id`** sebagai PK tabel → identitas stabil untuk CRUD, pagination, referensi internal. `name` editable; surrogate memisahkan identitas baris dari isinya.
  - **Composite unique `(category_code, code)`** sebagai **natural key & satu-satunya target FK dari `assets`**. Inilah penegak integritas P2: aset menunjuk pasangan `(category, subcategory)`, bukan `subcategory_id` telanjang.
  - `assets` **tidak** menyimpan `subcategory_id`. Alasannya: `category_code` + `subcategory_code` **wajib** ada di baris `assets` (komponen literal nomor aset, §5). Menambah `subcategory_id` hanya menciptakan jalur referensi kedua yang bisa tidak konsisten. Jadi `assets` mengikat ke composite natural key — pilihan **integritas**, bukan kemudahan.

---

### 2.4 `rooms`

**Tujuan:** master ruangan aplikasi (bukan hard-code, P6). Setiap ruangan milik satu lokasi.
15 ruangan kanonik lokasi `01` (mapping_ruangan.md §1) di-seed sebagai data awal;
selanjutnya dikelola dari aplikasi (tambah / edit / nonaktif).

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK**. |
| `location_code` | `CHAR(2)` | NN | — | **FK → `locations.code`**. Ruangan wajib punya lokasi (P6). |
| `name` | `VARCHAR(100)` | NN | — | Nama kanonik (mis. `Ruangan Keuangan`, `WC Pria`). |
| `pic` | `VARCHAR(100)` | NULL | `NULL` | Pengguna / penanggung jawab (mapping_ruangan.md §1). Kosong untuk Cikini/PLS/Gudang/WC. |
| `notes` | `VARCHAR(255)` | NULL | `NULL` | Catatan bebas. |
| `is_active` | `TINYINT(1)` | NN | `1` | Status aktif/nonaktif. `0` = dinonaktifkan (soft-disable). |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | Audit. |

- **PK:** `id`
- **U:**
  - `uq_rooms_location_name (location_code, name)` — cegah nama ruangan ganda **dalam satu lokasi**. Nama sama di lokasi berbeda **diizinkan** dan dianggap master room berbeda (P10).
  - `uq_rooms_location_id (location_code, id)` — **wajib** sebagai target semua composite FK yang masuk ke `rooms` (dari `assets`, `room_aliases`, `mutation_logs`). Menegakkan "aset/alias/log tidak menunjuk ruangan di lokasi lain" (§11).
- **FK:** `location_code → locations.code` `ON UPDATE RESTRICT ON DELETE RESTRICT`
- **IX:** `ix_rooms_is_active (is_active)`; `location_code` terlayani oleh `uq_rooms_location_name`
- **Relasi:** `rooms 1─N room_aliases`, `1─N assets`, `1─N mutation_logs (from/to_room_id)`
- **Soft delete vs status — keputusan:** pakai **`is_active` flag, BUKAN Laravel SoftDeletes (`deleted_at`)**.
  Alasan: ruangan yang dinonaktifkan **harus tetap ter-join penuh** oleh aset lama, Kartu Inventaris Ruangan historis, dan `mutation_logs`. `deleted_at` + global scope Eloquent akan menyembunyikan baris dari query default dan diam-diam merusak laporan histori. `is_active` menjaga baris tetap terlihat di join, hanya dikeluarkan dari **pilihan "tambah/pindah aset"**. Hard delete hanya bila `RESTRICT` FK mengizinkan (tidak ada aset/log/alias yang menunjuk).
- **Alasan desain:** surrogate `id` karena `name` editable dan tidak muncul di nomor aset (beda dari `locations`/`categories`).

---

### 2.5 `room_aliases`

**Tujuan:** kamus pemetaan **raw string kolom `Ruangan` Excel → `rooms.id`**, **selalu scoped per
lokasi** (P7, P10, B7). Menggantikan bagian 3 `mapping_ruangan.md` sebagai **tabel runtime** yang
bisa ditambah dari aplikasi tanpa ubah kode (mapping_ruangan.md §5(6)). Import mencocokkan lewat
tabel ini menggunakan **`location_code` + `match_key`**; kegagalan cocok → aset berstatus unmapped
(`room_id IS NULL`), **bukan** membuat room baru.

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK**. |
| `location_code` | `CHAR(2)` | **NN** | — | **FK → `locations.code`**. **Alias selalu milik satu lokasi.** Raw string sama di lokasi berbeda = alias berbeda (B7). |
| `raw_value` | `VARCHAR(150)` | NN | — | String Excel apa adanya saat pertama ditemui (mis. `SARANA DAN HUM`, `BAG. KUANGAN`). Untuk audit/tampilan. |
| `match_key` | `VARCHAR(150)` | NN | — | Bentuk ternormalisasi untuk pencocokan: `UPPER(TRIM(collapse_internal_whitespace(raw_value)))`. Dihitung aplikasi saat insert. |
| `room_id` | `BIGINT UNSIGNED` | NN | — | **FK → `rooms.id`**. Satu `(location_code, match_key)` → tepat satu room. |
| `source` | `ENUM('tahap1_seed','manual')` | NN | `'manual'` | Asal alias: seed dari mapping_ruangan.md §3 atau ditambah operator. |
| `notes` | `VARCHAR(255)` | NULL | `NULL` | Catatan (mis. "varian terpotong dari SARANA DAN HUMAS"). |
| `created_by` | `BIGINT UNSIGNED` | NULL | `NULL` | **FK → `users.id`** `ON DELETE SET NULL`. |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | Audit. |

- **PK:** `id`
- **U:** `uq_room_aliases_location_match_key (location_code, match_key)` — **`match_key` TIDAK unik global** (B7). Satu bentuk ternormalisasi hanya boleh menunjuk satu room **dalam lokasi yang sama**.
- **FK:**
  - `location_code → locations.code` `ON UPDATE RESTRICT ON DELETE RESTRICT`
  - `room_id → rooms.id` `ON UPDATE RESTRICT ON DELETE RESTRICT`
  - **composite** `(location_code, room_id) → rooms(location_code, id)` `ON UPDATE RESTRICT ON DELETE RESTRICT` — **menjamin alias & room yang ditunjuknya berada di lokasi yang sama.** Kedua kolom NN → selalu diperiksa.
- **IX:** `ix_room_aliases_room_id (room_id)` (ambil semua alias satu room). Lookup import `(location_code, match_key)` terlayani `uq_room_aliases_location_match_key`.
- **Relasi:** `locations 1─N room_aliases`, `rooms 1─N room_aliases`
- **Room alias SELALU scoped by location (eksplisit):** raw room yang sama (`GUDANG`, `KEUANGAN`,
  dst) dapat muncul di beberapa lokasi dan merujuk ruangan fisik berbeda. Karena itu:
  - kolom `location_code` **wajib** (NN);
  - unique-nya `(location_code, match_key)`, **bukan** `match_key` saja;
  - **setiap** lookup alias saat import memakai `WHERE location_code = :asset_location AND match_key = :key` — tidak pernah query alias global tanpa `location_code`.
- **Alasan `match_key` terpisah:** `mapping_ruangan.md` sengaja mencatat varian penulisan terpisah untuk keterlacakan (`KEUANGAN` vs `Keuangan`). Untuk **pencocokan** tahan banting, beda huruf besar/kecil & spasi ganda tidak boleh bikin gagal match. `match_key` menormalkan case + whitespace; typo asli (`KUANGAN`) tetap alias tersendiri. `raw_value` disimpan untuk audit.
- **Alasan tanpa `is_active`:** alias murni lookup helper, tanpa dependensi histori. Alias salah **dihapus** saja. (Bila perlu jejak, tambah `deleted_at` — §12 D10.)

---

### 2.6 `assets`

**Tujuan:** tabel inti. **1 baris = 1 physical asset** (P3, B3). Menyimpan komponen nomor aset,
penempatan ruangan saat ini, kondisi, penghapusan bisnis, dan atribut deskriptif (nullable, lintas kategori).

#### Komponen nomor aset (P4, P9, B1, B2)

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK (surrogate)**. |
| `location_code` | `CHAR(2)` | NN | — | **FK → `locations.code`**. Komponen 1. Semua 394 baris = `'01'`. |
| `category_code` | `CHAR(2)` | NN | — | Komponen 2. Bagian composite FK ke `subcategories`. Disimpan eksplisit untuk kecepatan filter (struktur_data_inventaris.md §3.5) — konsistensinya dijamin FK. |
| `subcategory_code` | `CHAR(3)` | NN | — | Komponen 3. |
| `sequence_no` | `VARCHAR(10)` | NN | — | Komponen 4. **String apa adanya (B2)**: `'001'`, `'0001'`, `'005A'`, `'005B'`, `'0017A'`, `'0017B'`. Tanpa padding/cast/normalisasi. `CHECK (sequence_no <> '')`. |
| `asset_year` | `SMALLINT UNSIGNED` | NN | — | Komponen 5. Tahun 4 digit (2017, 2019, …) yang menjadi bagian nomor aset. `CHECK (asset_year BETWEEN 1980 AND 2100)`. **`asset_year` TIDAK menentukan `sequence_no` berikutnya** — sequence berlanjut lintas tahun (B1, §5). |
| `asset_code` | `VARCHAR(40)` | NN | *(generated)* | `GENERATED ALWAYS AS (CONCAT_WS('.', location_code, category_code, subcategory_code, sequence_no, asset_year)) STORED`. Rekonstruksi kanonik, separator titik. Contoh: `01.02.001.005.2025`. **Dihasilkan DB — jangan buat generator `asset_code` di application layer** (§5.4, §7). |

#### Penempatan ruangan (P6, P7, P10) — `room_status` DIHAPUS

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `room_id` | `BIGINT UNSIGNED` | NULL | `NULL` | **FK → `rooms.id`**. **`room_id IS NOT NULL` = mapped; `room_id IS NULL` = unmapped ("belum terpetakan", perlu review).** Tidak ada kolom status terpisah. |
| `room_raw_value` | `VARCHAR(150)` | NULL | `NULL` | String `Ruangan` Excel/import apa adanya — **disimpan permanen** untuk audit & pencocokan ulang, baik saat mapped maupun unmapped. |

> **Catatan revisi:** field `room_status ENUM('mapped','unmapped')` **dihapus** karena redundan —
> nilainya 100% turunan dari `room_id IS NULL`. Semua query/laporan memakai predikat `room_id`.

#### Kondisi & penghapusan bisnis

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `condition` | `ENUM('baik','kurang_baik','rusak_berat')` | NULL | `NULL` | Kondisi fisik. **Nullable dulu** — flag Excel tidak konsisten; NULL menampung baris ambigu tanpa menebak. Rencana: jadikan `NN` setelah normalisasi (§12 D9). Nilai mentah disimpan di `import_rows`, bukan di sini. |
| `is_written_off` | `TINYINT(1)` | NN | `0` | `1` = sudah di-junk / dihapus dari inventaris secara **bisnis** (mis. printer Cikini "Di Junk"). **Ortogonal** dari `condition` — aset di-junk tetap punya kondisi fisik. **Bukan** soft delete (record tetap ada & ter-query). |
| `written_off_on` | `DATE` | NULL | `NULL` | Tanggal penghapusan bisnis. |
| `written_off_note` | `VARCHAR(255)` | NULL | `NULL` | Alasan / referensi SK penghapusan. |

#### Atribut deskriptif (semua NULL, lintas kategori)

| kolom | tipe MySQL | keterangan |
|---|---|---|
| `brand_model` | `VARCHAR(150)` NULL | Merk / model. |
| `serial_no` | `VARCHAR(150)` NULL | No. seri pabrik. |
| `material` | `VARCHAR(80)` NULL | Bahan. |
| `purchase_date` | `DATE` NULL | Tanggal pembelian. |
| `funding_source` | `VARCHAR(80)` NULL | Sumber dana (BOSDA, YYS, …). |
| `detail_type` | `VARCHAR(100)` NULL | `jenis_detail` — khusus Elektronik (Monitor/CPU/LCD). |
| `capacity_note` | `VARCHAR(100)` NULL | `kapasitas_ket` — khusus Alat Kebersihan ("12 liter"). |
| `quantity` | `INT UNSIGNED` NN default `1` | **Selalu `1` (B6)** — `CHECK (quantity = 1)`. Satu record = satu physical unit; **grouped asset / quantity > 1 tidak didukung** pada desain saat ini. Kolom dipertahankan untuk **kompatibilitas / future extensibility**; bila kebijakan berubah, CHECK dilonggarkan lewat migration tersendiri (§12 D7). |
| `notes` | `TEXT` NULL | Keterangan bebas. |

#### Audit & lifecycle

| kolom | tipe MySQL | NN/NULL | keterangan |
|---|---|---|---|
| `import_row_id` | `BIGINT UNSIGNED` | NULL | **FK → `import_rows.id`** `ON DELETE SET NULL`. Provenance: baris import asal. |
| `created_by` | `BIGINT UNSIGNED` | NULL | **FK → `users.id`** `ON DELETE SET NULL`. |
| `updated_by` | `BIGINT UNSIGNED` | NULL | **FK → `users.id`** `ON DELETE SET NULL`. |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | Laravel. |
| `deleted_at` | `TIMESTAMP` | NULL | **Laravel SoftDeletes — untuk koreksi / error record aplikasi**, BUKAN penghapusan bisnis (itu `is_written_off`). Nomor aset **tidak** di-reuse walau baris di-soft-delete (§11 I4, §12 lifecycle). |

- **PK:** `id`
- **U:**
  - `uq_assets_number (location_code, category_code, subcategory_code, sequence_no, asset_year)` — **identitas bisnis nomor aset** (P3/B3: `005A` ≠ `005B`). Penegak anti-duplikat.
  - `uq_assets_asset_code (asset_code)` — turunan (generated) untuk lookup/search by string kanonik + jaring pengaman kedua.
  - Unique tetap berlaku untuk baris **soft-deleted** (MySQL menghitungnya) → nomor aset **tidak** di-reuse. Disengaja.
- **FK:**
  - `location_code → locations.code` `ON UPDATE RESTRICT ON DELETE RESTRICT`
  - `(category_code, subcategory_code) → subcategories(category_code, code)` `ON UPDATE RESTRICT ON DELETE RESTRICT` — menjamin pasangan valid (P2) & `category_code` konsisten dengan subkategori.
  - **composite** `(location_code, room_id) → rooms(location_code, id)` `ON UPDATE RESTRICT ON DELETE RESTRICT` — menjamin ruangan aset **satu lokasi** dengan aset (§11 I3). `room_id` NULL → constraint di-skip (MATCH SIMPLE). `location_code` selalu NN.
  - `import_row_id → import_rows.id ON DELETE SET NULL`
  - `created_by`, `updated_by → users.id ON DELETE SET NULL`
- **IX:**
  - `ix_assets_room_id (room_id)` — Kartu Inventaris Ruangan **dan** daftar "belum terpetakan" (`WHERE room_id IS NULL`; InnoDB meng-index NULL).
  - `ix_assets_category_sub (category_code, subcategory_code)` — dari composite FK; leftmost melayani filter kategori & query generator (§5.3).
  - `ix_assets_location (location_code)` — dari FK.
  - `ix_assets_condition (condition)`
  - `ix_assets_written_off (is_written_off)`
  - `ix_assets_asset_year (asset_year)`
  - `ix_assets_deleted_at (deleted_at)`
- **Relasi:** turunan dari FK di atas + `assets 1─N mutation_logs`.
- **Alasan desain kunci:**
  - **Surrogate `id` walau ada natural key nomor aset:** 5-tuple string panjang & dipakai di banyak FK (`mutation_logs`, `import_rows`). Surrogate `BIGINT` membuat FK ramping & join cepat; natural key tetap ditegakkan sebagai `UNIQUE`.
  - **`asset_code` GENERATED STORED:** dijamin **selalu** sinkron dengan komponen di level DB — tak bisa melenceng karena bug/import parsial; bisa di-index UNIQUE. master_data.md §5 mengizinkan rekonstruksi dari 5 bagian & tidak mewajibkan separator asli → kanonik titik aman untuk data lama & baru.
  - **`category_code` disimpan walau derivable:** struktur_data_inventaris.md §3.5 minta eksplisit untuk kecepatan filter; composite FK ke `subcategories` membuat penyimpanan ganda **tidak bisa** tidak konsisten.

---

### 2.7 `mutation_logs`

**Tujuan:** histori perubahan aset — append-only audit foundation (generik sejak Tahap
5.8.8; awalnya hanya pindah ruangan di Tahap 5.4). **Append-only.** Harus tetap terbaca
walau ruangan dinonaktifkan / user dihapus (P8).

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK**. |
| `asset_id` | `BIGINT UNSIGNED` | NN | — | **FK → `assets.id`** `ON DELETE RESTRICT`. Histori tidak boleh yatim. |
| `type` | `ENUM('pindah_ruangan','perbaikan','penghapusan','perubahan_kondisi','lainnya')` | **NULL** (sejak 5.8.8; sebelumnya NN) | `NULL` | Penanda LEGACY pindah-ruangan saja — hanya pernah diisi `'pindah_ruangan'`. Tidak pernah diperluas ke event baru (lihat `event_type`). |
| `event_type` | `ENUM('CREATE','EDIT','MOVE_ROOM','WRITE_OFF','UNWRITE_OFF','SOFT_DELETE','RESTORE','BATCH_EDIT','BATCH_DELETE')` | NULL | `NULL` | **(Tahap 5.8.8)** Klasifikasi generik — diisi untuk SETIAP baris yang ditulis aplikasi, termasuk pindah ruangan (`MOVE_ROOM`). NULL hanya pada baris legacy/fixture yang ditulis manual di luar recorder. |
| `mutation_date` | `DATE` | NN | — | Tanggal kejadian fisik. |
| `from_location_code` | `CHAR(2)` | NULL | `NULL` | **FK → `locations.code`** `ON DELETE RESTRICT`. |
| `to_location_code` | `CHAR(2)` | NULL | `NULL` | **FK → `locations.code`** `ON DELETE RESTRICT`. |
| `from_room_id` | `BIGINT UNSIGNED` | NULL | `NULL` | Ruangan asal (lihat composite FK di bawah). |
| `to_room_id` | `BIGINT UNSIGNED` | NULL | `NULL` | Ruangan tujuan. |
| `from_room_label` | `VARCHAR(150)` | NULL | `NULL` | **Snapshot** nama ruangan asal saat kejadian (master room dapat berubah/deactivated di masa depan). |
| `to_room_label` | `VARCHAR(150)` | NULL | `NULL` | **Snapshot** nama ruangan tujuan saat kejadian. |
| `condition_before` | `ENUM('baik','kurang_baik','rusak_berat')` | NULL | `NULL` | Snapshot kondisi sebelum event — diisi untuk SEMUA `event_type` sejak 5.8.8, tidak hanya pindah ruangan. |
| `condition_after` | `ENUM('baik','kurang_baik','rusak_berat')` | NULL | `NULL` | — |
| `notes` | `TEXT` | NULL | `NULL` | Keterangan / metadata bebas (mis. `mutation_note` pada pindah ruangan). |
| `before_snapshot` | `JSON` | NULL | `NULL` | **(Tahap 5.8.8)** Snapshot lengkap state aset yang relevan SEBELUM event (`AssetMutationRecorder::snapshot()`); `NULL` untuk `event_type=CREATE` (belum ada state sebelumnya). |
| `after_snapshot` | `JSON` | NULL | `NULL` | **(Tahap 5.8.8)** Snapshot lengkap SETELAH event — bentuk sama dengan `before_snapshot`. |
| `batch_operation_id` | `UUID` (`CHAR(36)`) | NULL | `NULL` | **(Tahap 5.8.8)** Menyatukan seluruh event per-asset yang berasal dari SATU request batch HTTP (batch create/edit/delete). `NULL` untuk operasi individual. |
| `performed_by` | `BIGINT UNSIGNED` | NULL | `NULL` | **FK → `users.id`** `ON DELETE SET NULL`. Log tetap ada bila user dihapus. |
| `created_at` | `TIMESTAMP` | NN | `CURRENT_TIMESTAMP` | Waktu pencatatan (≠ `mutation_date`). |

> **`updated_at` DIHAPUS** — mutation log bersifat **append-only**; tidak ada mekanisme edit.
> Koreksi dilakukan dengan entri pembetulan baru. (Laravel model: `public $timestamps = false`
> atau hanya kelola `created_at`.)

- **PK:** `id`
- **U:** —
- **FK:**
  - `asset_id → assets.id` `ON UPDATE RESTRICT ON DELETE RESTRICT`
  - `from_location_code → locations.code` · `to_location_code → locations.code` — `ON DELETE RESTRICT` (validasi lokasi walau room NULL)
  - `performed_by → users.id ON DELETE SET NULL`
  - **composite** `(from_location_code, from_room_id) → rooms(location_code, id)` `ON DELETE RESTRICT`
  - **composite** `(to_location_code, to_room_id) → rooms(location_code, id)` `ON DELETE RESTRICT`
  - Composite FK memakai MATCH SIMPLE: bila salah satu kolom NULL, tidak diperiksa. **Aturan aplikasi:** bila `*_room_id` diisi maka `*_location_code` wajib diisi (opsional ditegakkan dengan `CHECK (from_room_id IS NULL OR from_location_code IS NOT NULL)` dan pasangannya).
- **IX:**
  - `ix_mutation_logs_asset (asset_id, mutation_date)` — timeline per aset
  - `ix_mutation_logs_type (type)`
  - `ix_mutation_logs_event_type (event_type)` — **(Tahap 5.8.8)** filter per jenis event generik
  - `ix_mutation_logs_batch_operation (batch_operation_id)` — **(Tahap 5.8.8)** lookup seluruh event dalam satu batch
  - `ix_mutation_logs_from_room (from_room_id)` · `ix_mutation_logs_to_room (to_room_id)` — mutasi per ruangan (composite FK index leftmost-nya `*_location_code`, jadi index tunggal ini tetap perlu)
  - `ix_mutation_logs_performed_by (performed_by)`
  - `ix_mutation_logs_date (mutation_date)`
- **Relasi:** `assets 1─N mutation_logs`; `rooms`/`locations`/`users` sebagai referensi lunak.
- **Histori tidak hilang saat ruangan dinonaktifkan (P8):**
  1. Nonaktif ruangan = `rooms.is_active = 0`, **baris tetap ada** → `from_room_id`/`to_room_id` tetap resolve.
  2. Composite FK `ON DELETE RESTRICT` → ruangan yang pernah dipakai log **tidak bisa** di-hard-delete.
  3. Composite FK `(…_location_code, …_room_id) → rooms(location_code, id)` → ruangan asal/tujuan **konsisten dengan lokasinya**.
  4. Snapshot `from_room_label`/`to_room_label` → log tetap terbaca benar walau ruangan **di-rename**.
  5. `performed_by ON DELETE SET NULL` → log tetap ada walau user pelaku dihapus.
- **Kapan baris dibuat — implementasi aktual (Tahap 5.8.8), menggantikan rencana awal
  di atas (`perbaikan`/`penghapusan`/`perubahan_kondisi` pada `type` tidak pernah
  dipakai untuk event baru — lihat catatan `type` vs `event_type` di atas):**
  - Create aset (individual/batch) → `event_type='CREATE'`, `before_snapshot=NULL`.
  - Pindah ruangan (individual/batch) → `event_type='MOVE_ROOM'` (`BATCH_EDIT` bila
    lewat batch edit), **`type='pindah_ruangan'` tetap diisi** (kompatibilitas), isi
    from/to (room + location + label).
  - Edit field deskriptif lain (individual/batch, bukan ruangan) →
    `event_type='EDIT'` / `'BATCH_EDIT'`, `type=NULL`.
  - Write-off / batal write-off → `event_type='WRITE_OFF'` / `'UNWRITE_OFF'`.
  - Soft delete (individual/batch) → `event_type='SOFT_DELETE'` / `'BATCH_DELETE'`.
  - Restore → `event_type='RESTORE'`. Restore pada aset yang sudah aktif (no-op) tidak
    membuat baris.
  - Request yang tidak benar-benar mengubah apa pun (before/after snapshot identik)
    tidak membuat baris — dicegah lewat perbandingan snapshot, bukan dirty-tracking
    Eloquent mentah (`updated_by` selalu berubah saat `save()`).
  - **Import awal tidak** membuat mutation_logs (keadaan awal, bukan mutasi).

---

### 2.8 `users`

**Tujuan:** akun aplikasi + kontrol akses berbasis role. Basis dari tabel Laravel bawaan +
kolom `role` & `is_active`.

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK**. |
| `name` | `VARCHAR(255)` | NN | — | Laravel. |
| `email` | `VARCHAR(255)` | NN | — | Laravel. **U**. |
| `email_verified_at` | `TIMESTAMP` | NULL | `NULL` | Laravel. |
| `password` | `VARCHAR(255)` | NN | — | Laravel (hash). |
| `role` | `ENUM('admin','operator','viewer')` | NN | `'viewer'` | Kontrol akses. Default paling aman = `viewer`. |
| `is_active` | `TINYINT(1)` | NN | `1` | Nonaktifkan akun tanpa hapus (jaga referensi di log/aset/alias). |
| `remember_token` | `VARCHAR(100)` | NULL | `NULL` | Laravel. |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | Laravel. |

- **PK:** `id`
- **U:** `uq_users_email (email)`
- **FK:** — (direferensikan oleh `assets`, `mutation_logs`, `room_aliases`, `import_batches`)
- **IX:** `ix_users_role (role)`
- **Role — kolom vs tabel (jawaban eksplisit):** untuk kebutuhan **saat ini**, cukup **kolom `role` ber-`ENUM`** pada `users`. Tabel `roles` + pivot + `permissions` hanya berbayar bila: (a) satu user punya banyak role, (b) role dibuat/diubah admin saat runtime, atau (c) perlu permission granular per aksi. Ketiganya **tidak** ada di scope ini — hanya 3 tingkat akses tetap. `ENUM` sekaligus jadi constraint DB. Migrasi ke `spatie/laravel-permission` nanti tetap mudah (map kolom → tabel role sekali jalan).
- Pembagian akses (ditegakkan di aplikasi, bukan DB):

  | role | akses |
  |---|---|
  | `admin` | Semua: kelola `users`, master (`locations`/`categories`/`subcategories`/`rooms`/`room_aliases`), import, aset, mutasi, laporan. |
  | `operator` | Input/edit `assets` & `mutation_logs`, jalankan import & review ruangan, tambah `room_aliases`. Tidak kelola `users`, tidak hapus master. |
  | `viewer` | Baca aset, mutasi, dan semua laporan (Kartu Inventaris Ruangan, rekap). Tanpa tulis. |

- **Open decision (§12 D3):** set 3-role (prompt) vs 4-role struktur_data_inventaris.md.

---

### 2.9 `import_batches`  *(tabel staging — dipertahankan, §7)*

**Tujuan:** satu baris = **satu proses import satu file/sheet = satu kategori aset**. Menyimpan
status & ringkasan hasil validasi + promosi.

> **Business rule (didokumentasikan):** satu import batch saat ini **merepresentasikan satu
> kategori aset dari satu source file/sheet.** Contoh: `Inventaris Meubelair.xlsx` (sheet
> `02 MEUBELAIR`) → satu batch `category_code = '02'`. Impor 394 baris awal = 3 batch (02, 03, 06).

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK**. |
| `source_filename` | `VARCHAR(255)` | NN | — | mis. `Inventaris Elektronik.xlsx`. |
| `source_sheet` | `VARCHAR(100)` | NULL | `NULL` | mis. `03 ELEKTRONIK`. |
| `category_code` | `CHAR(2)` | **NULL** | `NULL` | **FK → `categories.code`** `ON DELETE RESTRICT`. Kategori batch. **Nullable** — lihat catatan di bawah. |
| `status` | `ENUM('uploaded','validating','validated','partially_imported','imported','failed','rolled_back')` | NN | `'uploaded'` | Lifecycle batch. |
| `total_rows` | `INT UNSIGNED` | NN | `0` | Baris data terbaca. |
| `valid_rows` | `INT UNSIGNED` | NN | `0` | — |
| `warning_rows` | `INT UNSIGNED` | NN | `0` | mis. ruangan belum terpetakan. |
| `error_rows` | `INT UNSIGNED` | NN | `0` | — |
| `imported_rows` | `INT UNSIGNED` | NN | `0` | Sudah dipromosikan ke `assets`. |
| `uploaded_by` | `BIGINT UNSIGNED` | NULL | `NULL` | **FK → `users.id`** `ON DELETE SET NULL`. |
| `notes` | `TEXT` | NULL | `NULL` | — |
| `imported_at` | `TIMESTAMP` | NULL | `NULL` | — |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | — |

- **Kenapa `category_code` tetap nullable (bukan dihapus, bukan NN):**
  1. Batch dibuat saat **UPLOAD**, sebelum PARSE memastikan kategori dari isi file → belum tentu diketahui di baris pertama.
  2. Filosofi staging: muat raw dulu, validasi kemudian (§7). File yang salah/campur tetap bisa distaging untuk triase, bukan ditolak mentah.
  3. Setelah PARSE, aplikasi **wajib** mengisi `category_code`. Bila terisi, VALIDATE menandai **warning** (`category_mismatch`) untuk setiap `import_rows.category_code` yang ≠ `import_batches.category_code`.
  Dengan kata lain: aturan "satu batch = satu kategori" ditegakkan di layer validasi + proses, bukan sebagai `NOT NULL` di kolom, agar alur staging tidak patah.
- **PK:** `id` · **FK:** `category_code`, `uploaded_by` · **IX:** `ix_import_batches_status (status)`
- **Relasi:** `import_batches 1─N import_rows`; `categories 1─N import_batches`

---

### 2.10 `import_rows`  *(tabel staging — dipertahankan, §7)*

**Tujuan:** menampung **setiap baris Excel mentah** + hasil parsing + status validasi, **sebelum**
dipromosikan ke `assets`. Tempat parkir baris "belum siap" (ruangan tak dikenal, dup, kategori invalid).

| kolom | tipe MySQL | NN/NULL | default | keterangan |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NN | auto_increment | **PK**. |
| `import_batch_id` | `BIGINT UNSIGNED` | NN | — | **FK → `import_batches.id`** `ON DELETE CASCADE`. |
| `row_number` | `INT UNSIGNED` | NN | — | Nomor baris di spreadsheet sumber. |
| `raw_payload` | `JSON` | NN | — | **Seluruh isi baris asli (semua kolom) apa adanya** — dipertahankan agar data Excel asli dapat diaudit/review kapan pun. |
| `location_code` | `CHAR(2)` | NULL | `NULL` | Hasil parse (kandidat). |
| `category_code` | `CHAR(2)` | NULL | `NULL` | Hasil parse. |
| `subcategory_code` | `CHAR(3)` | NULL | `NULL` | Hasil parse (dari header blok). |
| `sequence_no` | `VARCHAR(10)` | NULL | `NULL` | **String apa adanya** — suffix huruf dipertahankan (B2). |
| `asset_year` | `SMALLINT UNSIGNED` | NULL | `NULL` | Hasil parse. |
| `room_raw_value` | `VARCHAR(150)` | NULL | `NULL` | Kolom `Ruangan` Excel apa adanya. |
| `matched_room_id` | `BIGINT UNSIGNED` | NULL | `NULL` | **FK → `rooms.id`** `ON DELETE SET NULL`. Hasil pencocokan **scoped by `location_code`**. |
| `room_match_method` | `ENUM('exact_name','alias','none')` | NULL | `NULL` | Cara ketemu. `none` = belum terpetakan. |
| `condition_raw` | `VARCHAR(50)` | NULL | `NULL` | Flag kondisi mentah dari Excel (`v`, `V`, `-`, dll) — **tidak** dinormalisasi di sini. |
| `condition_parsed` | `ENUM('baik','kurang_baik','rusak_berat')` | NULL | `NULL` | Hasil interpretasi. |
| `validation_status` | `ENUM('pending','valid','warning','error')` | NN | `'pending'` | Hanya `valid`/`warning` yang boleh dipromosikan. |
| `validation_messages` | `JSON` | NULL | `NULL` | Daftar `{code, field, message, severity}`. |
| `duplicate_of_asset_id` | `BIGINT UNSIGNED` | NULL | `NULL` | **FK → `assets.id`** `ON DELETE SET NULL`. **Makna: baris ini duplikat dari aset yang sudah ada** (nomor aset bentrok) → tidak dipromosikan. |
| `promoted_asset_id` | `BIGINT UNSIGNED` | NULL | `NULL` | **FK → `assets.id`** `ON DELETE SET NULL`. **Makna: aset yang berhasil dibuat dari baris ini.** |
| `promoted_at` | `TIMESTAMP` | NULL | `NULL` | — |
| `created_at` / `updated_at` | `TIMESTAMP` | NULL | `NULL` | — |

- **`duplicate_of_asset_id` vs `promoted_asset_id` — dipisah sengaja:** dua makna berbeda —
  yang pertama menandai baris **gagal** karena bentrok dengan aset lain; yang kedua menandai baris
  **sukses** dengan aset hasilnya. Sebuah baris tidak akan pernah punya keduanya terisi.
- **PK:** `id`
- **U:** `uq_import_rows_batch_row (import_batch_id, row_number)` — idempotensi parse ulang.
- **FK:** `import_batch_id` (CASCADE), `matched_room_id`, `duplicate_of_asset_id`, `promoted_asset_id` (semua `ON DELETE SET NULL`)
- **IX:** `ix_import_rows_status (validation_status)`, `ix_import_rows_matched_room (matched_room_id)`, `ix_import_rows_promoted (promoted_asset_id)`
- **Relasi:** `import_batches 1─N import_rows`; `import_rows 0/1 assets` (promosi)

---

## 3. Relationship & Foreign Key Rules

### 3.1 Daftar FK & perilaku referensial

| child | kolom | parent | ON UPDATE | ON DELETE | alasan |
|---|---|---|---|---|---|
| `rooms` | `location_code` | `locations.code` | RESTRICT | RESTRICT | Lokasi tak boleh hilang saat masih dipakai ruangan. |
| `subcategories` | `category_code` | `categories.code` | RESTRICT | RESTRICT | Kode kategori immutable; subkategori tak boleh yatim. |
| `room_aliases` | `location_code` | `locations.code` | RESTRICT | RESTRICT | Alias selalu milik satu lokasi (B7). |
| `room_aliases` | `room_id` | `rooms.id` | RESTRICT | RESTRICT | Alias tak boleh menunjuk room yang hilang. |
| `room_aliases` | `(location_code, room_id)` | `rooms (location_code, id)` | RESTRICT | RESTRICT | **Alias & room-nya wajib satu lokasi (B7).** Kedua kolom NN → selalu diperiksa. |
| `room_aliases` | `created_by` | `users.id` | RESTRICT | SET NULL | Alias tetap ada walau pembuatnya dihapus. |
| `assets` | `location_code` | `locations.code` | RESTRICT | RESTRICT | Komponen nomor aset. |
| `assets` | `(category_code, subcategory_code)` | `subcategories (category_code, code)` | RESTRICT | RESTRICT | **Jamin pasangan valid (P2).** |
| `assets` | `(location_code, room_id)` | `rooms (location_code, id)` | RESTRICT | RESTRICT | **Jamin ruangan satu lokasi dengan aset (§11 I3).** `room_id` NULL → di-skip (MATCH SIMPLE). |
| `assets` | `import_row_id` | `import_rows.id` | RESTRICT | SET NULL | Aset tetap ada walau baris import dibersihkan. |
| `assets` | `created_by` / `updated_by` | `users.id` | RESTRICT | SET NULL | Aset tetap ada walau user dihapus. |
| `mutation_logs` | `asset_id` | `assets.id` | RESTRICT | RESTRICT | Histori tak boleh yatim; aset dgn log tak bisa hard-delete (pakai `deleted_at`). |
| `mutation_logs` | `from_location_code` / `to_location_code` | `locations.code` | RESTRICT | RESTRICT | Validasi lokasi walau room NULL. |
| `mutation_logs` | `(from_location_code, from_room_id)` | `rooms (location_code, id)` | RESTRICT | RESTRICT | **Ruangan asal konsisten dengan lokasinya (P8).** MATCH SIMPLE. |
| `mutation_logs` | `(to_location_code, to_room_id)` | `rooms (location_code, id)` | RESTRICT | RESTRICT | **Ruangan tujuan konsisten dengan lokasinya (P8).** MATCH SIMPLE. |
| `mutation_logs` | `performed_by` | `users.id` | RESTRICT | SET NULL | Log tetap ada walau user dihapus. |
| `import_batches` | `category_code` | `categories.code` | RESTRICT | RESTRICT | — |
| `import_batches` | `uploaded_by` | `users.id` | RESTRICT | SET NULL | — |
| `import_rows` | `import_batch_id` | `import_batches.id` | RESTRICT | CASCADE | Baris ikut terhapus bila batch dibuang **sebelum** promosi. Setelah promosi, `assets.import_row_id` jadi NULL (SET NULL), aset tetap ada. |
| `import_rows` | `matched_room_id` / `duplicate_of_asset_id` / `promoted_asset_id` | resp. | RESTRICT | SET NULL | Referensi lunak. |

**Aturan umum:** tidak ada `ON UPDATE CASCADE` / `ON DELETE CASCADE` untuk kode master
(`locations.code`, `categories.code`, `subcategories.code`) — kode **immutable** (P1, P5, §13);
perubahan kode = operasi manual terkontrol, bukan efek samping. `import_rows → import_batches`
adalah satu-satunya CASCADE (staging, pra-promosi).

### 3.2 Kardinalitas

- `locations (1) ─ (N) rooms` — wajib (`rooms.location_code` NN)
- `locations (1) ─ (N) room_aliases` — wajib (`room_aliases.location_code` NN)
- `locations (1) ─ (N) assets` — wajib
- `rooms (1) ─ (0..N) room_aliases`
- `rooms (1) ─ (0..N) assets` — opsional (`assets.room_id` NULL saat belum terpetakan)
- `categories (1) ─ (N) subcategories`
- `categories (1) ─ (0..N) import_batches`
- `subcategories (1) ─ (0..N) assets` — via pasangan kode
- `assets (1) ─ (0..N) mutation_logs`
- `users (1) ─ (0..N) mutation_logs / assets / room_aliases / import_batches`
- `import_batches (1) ─ (N) import_rows`
- `import_rows (1) ─ (0..1) assets`

---

## 4. Primary Key & Unique Constraint Strategy

### 4.1 Ringkasan pilihan PK

| tabel | PK | jenis | alasan |
|---|---|---|---|
| `locations` | `code` `CHAR(2)` | **natural** | Kode = data, immutable, muncul di nomor aset, set kecil tetap. |
| `categories` | `code` `CHAR(2)` | **natural** | Idem. |
| `subcategories` | `id` `BIGINT` | **surrogate** + natural unique `(category_code, code)` | Kode tak unik global (P2); natural key tetap ditegakkan sebagai UNIQUE & jadi target FK dari `assets`. |
| `rooms` | `id` `BIGINT` | **surrogate** | `name` editable, tidak muncul di nomor aset. |
| `room_aliases` | `id` `BIGINT` | **surrogate** + unique `(location_code, match_key)` | `match_key` tak unik global (B7). |
| `assets` | `id` `BIGINT` | **surrogate** + natural unique 5-tuple + unique `asset_code` | 5-tuple string terlalu berat sebagai FK target di `mutation_logs`/`import_rows`. |
| `mutation_logs` | `id` `BIGINT` | **surrogate** | Event log. |
| `users` | `id` `BIGINT` | **surrogate** | Standar Laravel. |
| `import_batches` / `import_rows` | `id` `BIGINT` | **surrogate** | — |

**Prinsip:** natural PK hanya untuk tabel referensi yang benar-benar statis & kodenya immutable
(`locations`, `categories`). Sisanya surrogate + **natural key sebagai `UNIQUE`** — integritas
natural tetap ditegakkan tanpa membebani FK.

### 4.2 Daftar unique constraint

| tabel | constraint | kolom | tujuan |
|---|---|---|---|
| `locations` | PK | `code` | — |
| `locations` | `uq_locations_name` | `name` | Cegah lokasi duplikat. |
| `categories` | PK | `code` | — |
| `categories` | `uq_categories_name` | `name` | — |
| `subcategories` | `uq_subcategories_natural` | `(category_code, code)` | **Natural key (P2)** + target composite FK. |
| `subcategories` | `uq_subcategories_cat_name` | `(category_code, name)` | Cegah nama ganda dalam kategori. |
| `rooms` | `uq_rooms_location_name` | `(location_code, name)` | Cegah ruangan duplikat **per lokasi** (nama sama lintas lokasi diizinkan). |
| `rooms` | `uq_rooms_location_id` | `(location_code, id)` | **Target semua composite FK ke `rooms`** (assets, room_aliases, mutation_logs) — §11 I3. |
| `room_aliases` | `uq_room_aliases_location_match_key` | `(location_code, match_key)` | **Satu `(lokasi, bentuk ternormalisasi)` → satu room (B7).** |
| `assets` | `uq_assets_number` | `(location_code, category_code, subcategory_code, sequence_no, asset_year)` | **Identitas bisnis + anti-duplikat nomor aset (B3).** |
| `assets` | `uq_assets_asset_code` | `asset_code` | Lookup by string kanonik + jaring pengaman. |
| `users` | `uq_users_email` | `email` | — |
| `import_rows` | `uq_import_rows_batch_row` | `(import_batch_id, row_number)` | Idempotensi parse ulang. |

### 4.3 Apakah kode master perlu unique?

- `locations.code`, `categories.code` — **ya**, sebagai PK.
- `subcategories.code` — **tidak** sendirian (P2); **ya** sebagai pasangan `(category_code, code)`.
- `rooms.name` — unique **per lokasi**, bukan global (dua lokasi boleh punya "Gudang" — P10).
- `room_aliases.match_key` — unique **per lokasi** `(location_code, match_key)`, bukan global (B7).

---

## 5. Asset Number Strategy

### 5.1 Pilihan

| opsi | deskripsi |
|---|---|
| A | Simpan hanya satu field string `asset_number`. |
| B | Simpan hanya 5 komponen terpisah. |
| **C (final)** | **Simpan 5 komponen terpisah sebagai source of truth + `asset_code` string kanonik sebagai GENERATED STORED column.** |

### 5.2 Keputusan: **Opsi C**

**Komponen (authoritative):** `location_code CHAR(2)`, `category_code CHAR(2)`,
`subcategory_code CHAR(3)`, `sequence_no VARCHAR(10)`, `asset_year SMALLINT UNSIGNED`.

**Turunan:** `asset_code VARCHAR(40) GENERATED ALWAYS AS
(CONCAT_WS('.', location_code, category_code, subcategory_code, sequence_no, asset_year)) STORED`,
`UNIQUE`. Contoh: `01.02.001.005.2025`.

**Alasan:**

1. **Komponen terpisah wajib** karena:
   - Generator nomor baru butuh query `MAX` per `(location, category, subcategory)` — mustahil andal dari string tunggal (P9, B1).
   - Filter & laporan (rekap per kategori, Kartu Ruangan) memfilter per komponen.
   - `sequence_no` **harus** string apa adanya (B2) — `005A`, `0017A` tidak muat di INT & tidak boleh dinormalisasi.
2. **`asset_code` turunan tetap perlu** karena: pencarian pengguna, tampilan, export; `UNIQUE(asset_code)` = jaring pengaman kedua anti-duplikat.
3. **GENERATED STORED, bukan kolom biasa yang diisi aplikasi:** dijamin **selalu** sinkron di level DB — tak bisa melenceng karena bug/import parsial; bisa di-index UNIQUE. Butuh MySQL 5.7+/MariaDB 10.2+ (§14).

### 5.3 Generator nomor aset baru (konsep — tidak diimplementasi di Tahap 2)

#### Scope

Generator mencari `sequence_no` existing **hanya berdasarkan**:

```
location_code + category_code + subcategory_code
```

**BUKAN berdasarkan `asset_year`.** Sequence berlanjut lintas tahun (B1):

```
(01, 02, 001) 2025 → 001, 002, 003
(01, 02, 001) 2026 → 004, 005, 006      ← lanjut, tidak reset
(01, 02, 001) 2027 → 007, 008, 009
```

**Soft-deleted asset (`deleted_at IS NOT NULL`) tetap ikut diperhitungkan** agar nomor **tidak
pernah** digunakan ulang.

#### Parsing sequence existing

Untuk `sequence_no` seperti `005`, `005A`, `005B` → ambil **numeric prefix** = `5`
("keluarga sequence 5"). Suffix huruf diabaikan **hanya untuk perhitungan MAX** (nilai existing di
DB tidak diubah — B2).

```
'001'   → 1
'0001'  → 1
'005A'  → 5
'0017B' → 17
```

Generator mengambil **numeric prefix terbesar** dari seluruh baris pada scope triplet, lalu
menghasilkan `MAX + 1`.

#### Format nomor baru

Untuk asset **baru**: `sequence_no` numerik **3 digit** dengan zero-pad: `001`, `002`, …, `999`,
lalu `1000`, `1001`, … (tanpa suffix huruf). Contoh: MAX prefix = 46 → `sequence_no` baru = `047`.

**Sequence existing tidak diubah.** Zero-pad 3 digit hanya konvensi untuk data baru; tidak
melanggar "simpan apa adanya" untuk data lama (B2).

Karena scope selalu `(location, category, subcategory)`, kode subkategori `001` yang dipakai
lintas kategori **tidak pernah** bertabrakan (P2).

#### Concurrency

Tidak ada tabel sequence terpisah (tidak diperlukan untuk skala aplikasi ini). Alur:

```
1. BEGIN TRANSACTION
2. SELECT sequence_no FROM assets
   WHERE location_code=? AND category_code=? AND subcategory_code=?
   (termasuk soft-deleted)                 -- boleh FOR UPDATE untuk kurangi race
3. hitung MAX(numeric_prefix) + 1  → sequence_no baru (3-digit)
4. INSERT INTO assets (...)                -- asset_code ter-generate DB
5. COMMIT
6. Bila INSERT gagal karena duplicate-key (uq_assets_number / uq_assets_asset_code)
   akibat concurrent insert  →  ROLLBACK, ulangi dari langkah 2 (retry, mis. maks 3–5x).
```

**`UNIQUE(uq_assets_number)` + `UNIQUE(uq_assets_asset_code)` = proteksi final** — walau dua
request menghitung MAX yang sama, hanya satu INSERT berhasil; yang lain retry.

### 5.4 `asset_code` dihasilkan DB — bukan application layer

Karena `asset_code` adalah GENERATED STORED column, aplikasi **tidak** menyusun/menyimpan string
`asset_code` sendiri. Aplikasi hanya mengisi 5 komponen; DB yang merangkai & menjamin `UNIQUE`.
Import maupun input manual mengikuti aturan ini.

### 5.5 Identitas unik bisnis

```
location_code + category_code + subcategory_code + sequence_no + asset_year
```

= `uq_assets_number`. `asset_year` **bagian identitas** (dua aset dengan komponen sama tapi tahun
beda = dua aset), **tetapi bukan** bagian scope generator (B1).

---

## 6. Room & Room Alias Strategy

### 6.1 Model

```
locations (1) ─ (N) rooms (1) ─ (N) room_aliases      [alias.location_code = room.location_code]
locations (1) ─ (N) room_aliases                       [alias selalu milik satu lokasi]
rooms     (1) ─ (N) assets                             (assets.room_id, nullable)
```

- `rooms` = master data penuh: `name`, `location_code` (NN), `pic`, `notes`, `is_active`.
- 15 ruangan kanonik lokasi `01` (mapping_ruangan.md §1) = **seed awal**, bukan hard-code.
- CRUD dari aplikasi: tambah, edit, **nonaktifkan** (`is_active = 0`). Hapus permanen hanya bila tidak ada aset/log/alias yang menunjuk (FK RESTRICT).
- **Nama ruangan sama di dua lokasi berbeda = dua master room berbeda** (P10). `uq_rooms_location_name` mengunci keunikan **per lokasi**, bukan global.

### 6.2 Pencocokan ruangan saat import — **SELALU scoped by `location_code`**

```
INPUT : location_code (dari komponen nomor aset baris tsb), room_raw_value (kolom "Ruangan")

1. key = UPPER(TRIM(collapse_whitespace(room_raw_value)))

2. EXACT CANONICAL ROOM:
   cari rooms WHERE location_code = :location_code
                AND UPPER(TRIM(collapse_whitespace(name))) = key
     → ketemu : matched_room_id = rooms.id ; room_match_method = 'exact_name'

3. ALIAS (bila langkah 2 gagal):
   cari room_aliases WHERE location_code = :location_code
                       AND match_key = key
     → ketemu : matched_room_id = room_aliases.room_id ; room_match_method = 'alias'

4. TIDAK KETEMU:
     - matched_room_id = NULL ; room_match_method = 'none'
     - validation_status = 'warning' (code 'room_unmapped') — BUKAN 'error'
     - JANGAN auto-create room. JANGAN buat 'Lainnya' (B5).
     - room_raw_value disimpan apa adanya.
```

- **Tidak ada** query room / alias yang **global tanpa `location_code`** — di semua langkah,
  `location_code` adalah filter wajib.
- Saat **PROMOTE** ke `assets`:
  - method `exact_name` / `alias` → `assets.room_id = matched_room_id`, `assets.room_raw_value = room_raw_value`.
  - method `none` → `assets.room_id = NULL` (⇒ unmapped), `assets.room_raw_value = room_raw_value`.
    Aset **tetap dipromosikan** bila business rule mengizinkan (nomor aset & lokasi valid); muncul di daftar "belum terpetakan" (`WHERE room_id IS NULL`).
- **Review unmapped:** operator memilih (a) tambah `room_alias` untuk lokasi tsb → hubungkan ke room existing, atau (b) buat room baru manual (untuk lokasi tsb) lalu alias. **Tidak pernah** otomatis.

### 6.3 Kenapa `room_aliases` tabel terpisah & location-aware

- Satu room punya **banyak** varian penulisan (mapping_ruangan.md §2: "Ruangan Personalia & SARPRAS" ← 14 string mentah).
- Alias bertambah tiap import berikutnya menemukan varian baru — harus bisa ditambah **dari aplikasi tanpa ubah kode** (mapping_ruangan.md §5(6)).
- **Location-aware (B7):** raw string generik (`GUDANG`, `KEUANGAN`, `USAHA`) bisa muncul di SD/SMP/SMA dengan makna ruangan berbeda. `match_key` global akan salah memetakan. Karena itu `location_code` NN + unique `(location_code, match_key)` + composite FK `(location_code, room_id) → rooms(location_code, id)`.
- `raw_value` disimpan untuk audit; `match_key` untuk lookup tahan-variasi.

---

## 7. Excel Import Strategy

### 7.1 Tabel staging (`import_batches`, `import_rows`) — **DIPERTAHANKAN**

Tidak diubah menjadi direct import. **Alasan:**

1. **Kualitas data sumber bermasalah:** case tidak konsisten (`v`/`V`), typo (`KUANGAN`, `KABIS`), 59 varian string ruangan, `sequence_no` bersuffix huruf, kemungkinan duplikat.
2. **Wajib ada validasi & review sebelum data masuk `assets`.** Staging = tempat baris "belum siap" diparkir tanpa mengotori tabel operasional.
3. **Idempotensi & rollback:** import 394 baris dari 3 file + import berkala. Batch bisa divalidasi ulang, diperbaiki per baris, dipromosikan sebagian, atau dibatalkan.
4. **Provenance:** `assets.import_row_id` → tahu baris/file asal tiap aset (verifikasi "jumlah baris DB == jumlah baris Excel").
5. **Alur review ruangan belum terpetakan** butuh tempat menahan baris.
6. **`raw_payload JSON`** menyimpan baris Excel asli utuh → audit/review kapan pun.

### 7.2 Alur

```
1. UPLOAD    → buat import_batch (status 'uploaded'), baca sheet, buat import_rows
              (raw_payload JSON per baris, row_number).
2. PARSE     → isi kolom kandidat: location/category/subcategory/sequence_no/asset_year
              (dari header blok + sel), room_raw_value, condition_raw.
              set import_batches.category_code.
3. VALIDATE  → set validation_status + validation_messages per baris (aturan §7.3).
              pencocokan ruangan SELALU scoped by location_code (§6.2).
              update ringkasan batch.
4. REVIEW    → operator lihat baris 'error'/'warning':
              - perbaiki inline, atau perbaiki sumber & re-upload
              - untuk ruangan: tambah room_alias (untuk lokasi tsb) / buat room baru
5. PROMOTE   → untuk baris 'valid'/'warning': INSERT ke assets (5 komponen; asset_code
              ter-generate DB), set import_rows.promoted_asset_id + promoted_at,
              assets.import_row_id. baris 'error' dilewati.
6. VERIFY    → COUNT(assets per category) == baris Excel; setiap asset_code cocok Excel.
```

### 7.3 Aturan validasi (di `import_rows`, sebelum promosi)

| pemeriksaan | hasil bila gagal |
|---|---|
| `location_code` ada di `locations` | **error** (`invalid_location`) |
| `(category_code, subcategory_code)` ada di `subcategories` | **error** (`invalid_subcategory`) — termasuk subkategori yang belum di-seed |
| `category_code` baris == `import_batches.category_code` (bila batch-nya terisi) | **warning** (`category_mismatch`) — satu batch = satu kategori (§2.9) |
| `asset_year` terparse & 1980–2100 | **error** (`invalid_year`) |
| `sequence_no` tidak kosong | **error** (`empty_sequence`) |
| `sequence_no` bersuffix huruf | **info** — **diterima apa adanya** (B2), bukan error |
| nomor aset 5-tuple `(location, category, subcategory, sequence_no, asset_year)` sudah ada di `assets` | **error** (`duplicate_asset`), set `duplicate_of_asset_id` |
| nomor aset 5-tuple duplikat dg baris lain di batch yg belum dipromosikan | **error** (`duplicate_in_batch`) |
| `room_raw_value` cocok (scoped by `location_code`) exact-name / alias | ketemu → **valid**; tidak → **warning** (`room_unmapped`), tetap dapat dipromosikan (`room_id` = NULL) bila business rule mengizinkan |
| `condition_raw` bisa diinterpretasi | tidak → **warning** (`condition_unknown`), `condition = NULL` |

- Hanya `validation_status IN ('valid','warning')` yang boleh dipromosikan.
- **Duplicate asset** ditangkap dua lapis: cek staging (di atas) + `UNIQUE(uq_assets_number)` / `UNIQUE(uq_assets_asset_code)` sebagai backstop DB saat INSERT.
- **`sequence_no` bersuffix huruf** disimpan mentah di `import_rows.sequence_no` **dan** `assets.sequence_no`. Tidak ada padding/cast (B2).
- **Invalid category/subcategory/location** tidak pernah dipromosikan; menunggu perbaikan sumber atau penambahan master (§12 D8).

---

## 8. Mutation History Strategy

### 8.1 Kebutuhan tercakup

| kebutuhan | kolom |
|---|---|
| aset | `asset_id` |
| jenis mutasi | `type` (enum) |
| lokasi/ruangan asal | `from_location_code`, `from_room_id`, `from_room_label` |
| lokasi/ruangan tujuan | `to_location_code`, `to_room_id`, `to_room_label` |
| tanggal | `mutation_date` (kejadian) + `created_at` (pencatatan) |
| keterangan | `notes` |
| user | `performed_by` |
| perubahan kondisi | `condition_before`, `condition_after` |

### 8.2 Append-only

- **Tidak ada `updated_at`, tidak ada `deleted_at`.** Log tidak diedit/dihapus; koreksi = entri baru.

### 8.3 Histori tidak hilang saat ruangan dinonaktifkan (P8)

1. **Nonaktif ≠ hapus** — `rooms.is_active = 0`; baris tetap ada; semua FK tetap resolve.
2. **Composite FK `ON DELETE RESTRICT`** pada `(from/to_location_code, from/to_room_id) → rooms(location_code, id)` — ruangan yang pernah muncul di log **tidak bisa** di-hard-delete, **dan** ruangan asal/tujuan wajib konsisten dengan lokasinya.
3. **Snapshot label** `from_room_label`/`to_room_label` — nama ruangan **saat kejadian**; log tetap akurat walau ruangan di-rename kemudian.
4. **`performed_by ON DELETE SET NULL`** — log tetap ada walau user pelaku dihapus.

---

## 9. User & Role Strategy

- **Keputusan: kolom `role ENUM('admin','operator','viewer')` pada `users`.** Tidak perlu tabel `roles`/`permissions` terpisah untuk scope saat ini (alasan lengkap §2.8).
- `is_active` untuk menonaktifkan akun tanpa hapus — referensi di `mutation_logs.performed_by`, `assets.created_by/updated_by`, `room_aliases.created_by` harus tetap resolve.
- **Jalur migrasi** bila nanti perlu RBAC granular: pindah ke `spatie/laravel-permission` (map `users.role` → tabel `roles` sekali jalan). Skema sekarang tidak menghalangi.
- **Open decision (§12 D3):** set 3-role (prompt) vs 4-role struktur_data_inventaris.md (`admin`/`staff_sarpras`/`kepala_unit`/`viewer`).

---

## 10. Indexes

### 10.1 Per tabel (di luar PK & UNIQUE yang sudah otomatis ter-index)

| tabel | index | kolom | alasan |
|---|---|---|---|
| `subcategories` | *(cukup)* | — | `uq_subcategories_natural` melayani filter `category_code`. |
| `rooms` | `ix_rooms_is_active` | `is_active` | Daftar ruangan aktif untuk dropdown. |
| `room_aliases` | `ix_room_aliases_room_id` | `room_id` | Ambil semua alias satu room. Lookup import `(location_code, match_key)` dilayani `uq_room_aliases_location_match_key`. |
| `assets` | `ix_assets_room_id` | `room_id` | Kartu Inventaris Ruangan **+** daftar "belum terpetakan" (`room_id IS NULL`). |
| `assets` | `ix_assets_category_sub` | `(category_code, subcategory_code)` | Rekap per kategori/subkategori + query generator (§5.3). Leftmost melayani filter `category_code`. |
| `assets` | `ix_assets_location` | `location_code` | Filter lokasi (dari FK). |
| `assets` | `ix_assets_condition` | `condition` | Rekap kondisi. |
| `assets` | `ix_assets_written_off` | `is_written_off` | Pisahkan aset aktif vs di-junk. |
| `assets` | `ix_assets_asset_year` | `asset_year` | Filter/laporan per tahun. |
| `assets` | `ix_assets_deleted_at` | `deleted_at` | Efisiensi SoftDeletes. |
| `mutation_logs` | `ix_mutation_logs_asset` | `(asset_id, mutation_date)` | Timeline per aset. |
| `mutation_logs` | `ix_mutation_logs_type` | `type` | Filter jenis. |
| `mutation_logs` | `ix_mutation_logs_from_room` / `_to_room` | `from_room_id` / `to_room_id` | Mutasi per ruangan (composite FK index leftmost-nya `*_location_code`). |
| `mutation_logs` | `ix_mutation_logs_performed_by` | `performed_by` | Audit per user. |
| `mutation_logs` | `ix_mutation_logs_date` | `mutation_date` | Laporan periode. |
| `users` | `ix_users_role` | `role` | Filter admin. |
| `import_batches` | `ix_import_batches_status` | `status` | Dashboard import. |
| `import_rows` | `ix_import_rows_status` | `validation_status` | Ambil baris error/warning. |
| `import_rows` | `ix_import_rows_matched_room` | `matched_room_id` | — |
| `import_rows` | `ix_import_rows_promoted` | `promoted_asset_id` | Cek sudah dipromosikan. |

### 10.2 Catatan

- InnoDB meng-index otomatis kolom-kolom **setiap FK** (composite FK → index gabungan). `assets(category_code, subcategory_code)` juga melayani query yang memfilter `category_code` saja (leftmost prefix). Tidak perlu index terpisah untuk `category_code`.
- `uq_assets_number` (leftmost `location_code, category_code, subcategory_code`) melayani query generator (`MAX(sequence_no)` per triplet, **tanpa** `asset_year`) tanpa index tambahan.
- `uq_rooms_location_id (location_code, id)` = target semua composite FK ke `rooms`; tidak perlu index lain untuk itu.
- Daftar "belum terpetakan" = `WHERE room_id IS NULL AND deleted_at IS NULL` → `ix_assets_room_id` cukup (InnoDB meng-index NULL). Tidak ada kolom `room_status` (dihapus).
- Tidak ada full-text index sekarang; pencarian `brand_model`/`notes` pakai `LIKE` (volume kecil, 394 baris). Ditinjau ulang bila data tumbuh.

---

## 11. Data Integrity Rules

| # | aturan | mekanisme |
|---|---|---|
| I1 | Kode lokasi/kategori/subkategori disimpan sebagai string, leading zero utuh | `CHAR(2)` / `CHAR(3)`, **bukan** INT |
| I2 | Subkategori hanya valid dalam kategori-nya | `UNIQUE(subcategories.category_code, code)` + **composite FK** `assets(category_code, subcategory_code) → subcategories(category_code, code)` |
| I3 | Aset / alias / mutation-log tak boleh menunjuk ruangan beda lokasi | `UNIQUE(rooms.location_code, id)` + **composite FK** dari `assets(location_code, room_id)`, `room_aliases(location_code, room_id)`, `mutation_logs(from/to_location_code, from/to_room_id)` → `rooms(location_code, id)`; kolom nullable → di-skip (MATCH SIMPLE) |
| I4 | Nomor aset unik; **tidak di-reuse** walau soft-deleted | `UNIQUE(location_code, category_code, subcategory_code, sequence_no, asset_year)` + `UNIQUE(asset_code)`; MySQL menghitung baris soft-deleted |
| I5 | `sequence_no` tidak dinormalisasi | `VARCHAR(10)`, `CHECK(sequence_no <> '')`, tanpa transformasi di layer manapun (B2) |
| I6 | `asset_year` masuk akal | `CHECK(asset_year BETWEEN 1980 AND 2100)` |
| I7 | `005A`/`005B` = baris terpisah | Tidak ada penggabungan; `sequence_no` berbeda → dua baris `assets` sah (B3) |
| I8 | Satu `(lokasi, raw string ruangan ternormalisasi)` → satu room | `UNIQUE(room_aliases.location_code, match_key)` (B7) |
| I9 | Raw ruangan tak dikenal tidak membuat room | Import set `room_id = NULL` (⇒ unmapped); **tidak ada** `INSERT INTO rooms` (B5) |
| I10 | Tidak ada room `Lainnya` | Aturan proses + review manual; tidak di-seed (B5) |
| I11 | Histori mutasi tak hilang saat ruangan nonaktif | `is_active` flag (bukan delete) + composite FK `RESTRICT` + snapshot `*_room_label` |
| I12 | Histori/aset/alias tak hilang saat user dihapus | `performed_by`, `created_by`, `updated_by` → `ON DELETE SET NULL` |
| I13 | Aset dengan histori tak bisa hard-delete | `mutation_logs.asset_id` FK `RESTRICT`; aset dibuang via `deleted_at` |
| I14 | Duplikat aset saat import tertangkap | Cek staging (`duplicate_of_asset_id`) + `UNIQUE(uq_assets_number)` / `UNIQUE(uq_assets_asset_code)` backstop |
| I15 | Kondisi fisik ≠ status penghapusan bisnis | `condition` (enum, nullable) **terpisah** dari `is_written_off` (boolean) + `written_off_on` |
| I16 | Kode master immutable setelah dipakai aset | FK `ON UPDATE RESTRICT` di semua referensi kode master; tanpa `CASCADE`. Lihat §13. |
| I17 | Nama ruangan tak ganda **per lokasi** (lintas lokasi diizinkan) | `UNIQUE(rooms.location_code, name)` |
| I18 | Email user unik | `UNIQUE(users.email)` |
| I19 | `assets.quantity` selalu `1` | `CHECK (quantity = 1)` (B6) |
| I20 | `asset_code` selalu konsisten dengan 5 komponen | GENERATED STORED column — dihasilkan DB, tidak bisa diisi manual (§5.4) |

### 11.1 Soft delete vs status aktif — ringkasan keputusan

| tabel | mekanisme | alasan |
|---|---|---|
| `rooms` | **`is_active`** (tanpa `deleted_at`) | Harus tetap ter-join oleh aset & histori; global scope SoftDeletes merusak laporan. |
| `users` | **`is_active`** | Referensi di `mutation_logs`/`assets`/`room_aliases` harus tetap resolve. |
| `assets` | **`deleted_at` (SoftDeletes)** untuk **koreksi / error record aplikasi** + **`is_written_off`** untuk **penghapusan bisnis** | Dua konsep beda: baris keliru (dihapus lunak) vs aset yang sah dibuang tapi jejaknya disimpan (`is_written_off=1`, record tetap ada & ter-query). |
| `subcategories` / `categories` / `locations` | **`is_active`** | Statis; retire kode tanpa hapus, aset lama tetap valid. |
| `mutation_logs` | **tak ada** — append-only | Histori tidak dihapus/diedit; tanpa `updated_at`/`deleted_at`. |
| `room_aliases` | hard delete (opsional `deleted_at`, §12 D10) | Murni lookup helper, tanpa dependensi histori. |

> **`deleted_at` bukan penghapusan bisnis.** Aset yang secara bisnis tidak dipakai lagi memakai
> `is_written_off = 1` (+ `written_off_on`). `deleted_at` hanya untuk record yang **salah dibuat**
> (mis. salah input, dobel entri manual). **Nomor aset tetap tidak boleh dipakai ulang** walau
> baris di-soft-delete (I4).

### 11.2 Yang **tidak** ditegakkan DB (tanggung jawab aplikasi / service layer)

- `condition` sebaiknya terisi untuk aset non-import (DB izinkan NULL selama fase transisi — §12 D9).
- `mutation_logs`: kelengkapan `from_*`/`to_*` sesuai `type` (mis. `pindah_ruangan` wajib `to_room_id` + `to_location_code`); bila `*_room_id` diisi maka `*_location_code` wajib diisi (opsional ditegakkan `CHECK`).
- Pengisian `*_room_label` sebagai snapshot pada saat mutasi dicatat.
- `import_batches.category_code` diisi setelah PARSE; VALIDATE menandai `category_mismatch` per baris.
- Retry generator saat duplicate-key concurrent (§5.3) — DB unique = backstop.
- Nilai enum di luar daftar sudah dicegah `ENUM`; penambahan nilai baru = perubahan skema (migration).

---

## 12. Open Decisions / Questions

### 12.1 Sudah FINAL (dikunci revisi ini — tidak perlu ditanyakan lagi)

| # | topik | keputusan final |
|---|---|---|
| F1 | `sequence_no` tipe | **`VARCHAR(10)`, nilai Excel apa adanya**, tanpa normalisasi/padding (B2). `struktur_data_inventaris.md` (`no_urut INT`) **digantikan**. Sisa (non-schema): apakah beri anotasi "superseded" di file itu — tidak mengubah desain. |
| F2 | Scope generator `sequence_no` | **`location_code + category_code + subcategory_code`**, berlanjut lintas tahun; `asset_year` bukan bagian scope (B1). |
| F3 | `asset_year` | Nama kolom `asset_year` (bukan `year`), tipe `SMALLINT UNSIGNED`. Bagian identitas unik `uq_assets_number`, bukan bagian scope generator. |
| F4 | `asset_code` | **GENERATED STORED** column, separator titik, `UNIQUE`. Tidak dibuat di application layer (§5.4). |
| F5 | `room_aliases` location-aware | Kolom `location_code` NN; unique `(location_code, match_key)`; composite FK `(location_code, room_id) → rooms(location_code, id)`. Semua lookup alias & nama kanonik scoped by `location_code` (B7, P10). |
| F6 | `room_status` di `assets` | **Dihapus.** `room_id IS NULL` = unmapped. `room_raw_value` dipertahankan. |
| F7 | `assets.quantity` | Selalu `1`, `CHECK (quantity = 1)` (B6). Kolom dipertahankan untuk future extensibility. Grouped asset tidak didukung. |
| F8 | `mutation_logs.updated_at` | **Dihapus** — append-only. Hanya `created_at`. |
| F9 | Staging import | `import_batches` + `import_rows` **dipertahankan**; `raw_payload JSON` dipertahankan; `duplicate_of_asset_id` & `promoted_asset_id` tetap terpisah. |
| F10 | `import_batches.category_code` | **Nullable dipertahankan** (dibuat saat UPLOAD sebelum kategori diketahui). Business rule "1 batch = 1 kategori" ditegakkan di PARSE/VALIDATE (`category_mismatch` warning). |
| F11 | Nama tabel | Inggris plural (`locations`, `categories`, …). Kolom domain-spesifik boleh istilah lokal (`pic`). |
| F12 | Composite FK ke `rooms` | Semua memakai order `(location_code, id)` → satu helper key `uq_rooms_location_id`. |
| F13 | Master code immutability | `ON UPDATE RESTRICT` di semua FK kode master; tanpa CASCADE (§13). |

### 12.2 Masih TERBUKA (tidak memblokir Tahap 3, bisa default)

| # | topik | opsi / catatan | rekomendasi default |
|---|---|---|---|
| D1 | `struktur_data_inventaris.md` yang digantikan | Beri catatan "superseded" pada §3.4/§3.5/§5 file itu, atau biarkan sebagai draft historis. **File itu tidak diubah pada Tahap 2.** | Biarkan; rujuk lewat dokumen ini. |
| D2 | `rooms.tipe` (`unit_kerja`/`ruang_fisik`) + `unit_kerja_induk` | Ada di struktur doc §3.4; Tahap 1B memodelkan semua ruangan setara berelasi ke lokasi. | **Drop** — ikuti Tahap 1B. |
| D3 | Set role | 3 (`admin`/`operator`/`viewer`) vs 4 (`+staff_sarpras`/`kepala_unit`). | 3-role sekarang. |
| D8 | Seed subkategori kategori `01`/`04`/`05` dari Panduan | master_data.md §7 — open. Skema mendukung keduanya. | Tunda sampai ada data, atau seed `is_active=0`. |
| D9 | `condition` NULL → NN | Data Excel tidak konsisten. | NULLable saat import; normalisasi + ubah `NN` setelah review, via migration terpisah. |
| D10 | `room_aliases.deleted_at` | Perlu jejak alias yang pernah ada? | Default hard delete; tambah `deleted_at` hanya bila audit alias diperlukan. |
| D11 | Ejaan tampilan (`HIGHT PRESURE` → `HIGH PRESSURE`, kapitalisasi) | master_data.md §7. Nilai sumber tetap Excel. | Kolom `name` simpan versi tampilan; tak memblokir skema. |
| D12 | >1 ruang rapat fisik (`R. RAPAT YAYASAN` vs `R. RAPAT`) | mapping_ruangan.md §6 — sama untuk sekarang. | 1 room; skema mengizinkan pemisahan + re-alias nanti. |
| D14 | Tabel infra Laravel (`sessions`, `password_reset_tokens`, `personal_access_tokens`, `jobs`, `cache`) | Sudah ada dari starter / bawaan. | Non-domain; biarkan default (§13.2). |
| D16 | Opsional `CHECK` pada `mutation_logs` | `(from_room_id IS NULL OR from_location_code IS NOT NULL)` dan pasangannya. | Tambahkan bila DB produksi mendukung CHECK (§14). |

---

## 13. Master Code Immutability

Setelah sebuah kode master **dipakai oleh aset**, kode tersebut **tidak boleh diubah sembarangan**.
Berlaku untuk:

- `locations.code`
- `categories.code`
- `subcategories.code` (dan pasangan `(category_code, code)`)

**Alasan:** kode-kode ini adalah **komponen literal nomor aset** dan menyusun `asset_code`
(GENERATED). Mengubah kode = mengubah identitas setiap aset yang memakainya + mengubah nilai
`uq_assets_number` & `asset_code` → merusak referensi historis, laporan, dan nomor yang sudah
tercetak/terdistribusi.

**Mekanisme:**

1. Semua FK yang menunjuk kode master memakai `ON UPDATE RESTRICT` (tidak ada `CASCADE`) — DB menolak `UPDATE` kode selama masih direferensikan.
2. Perubahan kode (bila benar-benar perlu) = **operasi migrasi manual terkontrol** oleh admin, bukan lewat CRUD biasa, dan didokumentasikan.
3. `is_active = 0` adalah cara yang benar untuk "menonaktifkan" kode master yang tidak dipakai lagi — bukan mengubah/menghapus kodenya.

---

## 14. Deployment & Production Database Compatibility

Skema ini menetapkan `utf8mb4` + collation **`utf8mb4_0900_ai_ci`** → **membutuhkan MySQL 8.0+**.

### 14.1 Fitur yang dipakai & versi minimum

| fitur | dipakai untuk | MySQL | MariaDB |
|---|---|---|---|
| `GENERATED ... STORED` + index UNIQUE di atasnya | `assets.asset_code` | 5.7+ | 10.2+ |
| `CHECK` constraint **ditegakkan** | `sequence_no <> ''`, `asset_year BETWEEN …`, `quantity = 1` | **8.0.16+** | 10.2.1+ |
| composite FK dengan sebagian kolom NULL (MATCH SIMPLE skip) | `assets`/`room_aliases`/`mutation_logs` → `rooms` | semua | semua |
| collation `utf8mb4_0900_ai_ci` | pencocokan case-insensitive (nama ruangan, `match_key`, email) | 8.0+ | **tidak ada** — MariaDB: `utf8mb4_uca1400_ai_ci` (10.10+) atau `utf8mb4_unicode_ci` |
| `JSON` type | `import_rows.raw_payload`, `validation_messages` | 5.7+ | 10.2+ (alias `LONGTEXT`) |

### 14.2 Karena production menggunakan cPanel

Versi MySQL/MariaDB di cPanel sering tidak sepenuhnya terkontrol — **verifikasi sebelum Tahap 3:**

1. `SELECT VERSION();` di server produksi.
2. Pastikan **≥ MySQL 8.0.16** (ideal), atau MariaDB ≥ 10.4.
3. Bila **MySQL 5.7 / MariaDB**: catat penyesuaian —
   - `CHECK` mungkin **diabaikan diam-diam** di MySQL 5.7 (di-parse tapi tidak ditegakkan) → integritas `quantity=1` / `sequence_no <> ''` / range `asset_year` harus dipindah ke **application-layer validation**.
   - collation `utf8mb4_0900_ai_ci` tidak tersedia → ganti ke `utf8mb4_unicode_ci` (MySQL 5.7) / `utf8mb4_uca1400_ai_ci` (MariaDB baru). **Perilaku pencocokan** (`match_key`, nama ruangan, email) harus diuji ulang setelah ganti collation.
   - GENERATED STORED column & `JSON` tetap OK di 5.7+/MariaDB 10.2+.

### 14.3 Kebijakan

- **Jangan** ganti collation sekarang tanpa alasan. Dokumen ini hanya **mencatat dependency +
  langkah verifikasi**.
- Bila verifikasi gagal, opsi fallback **dibahas di Tahap 3** (bukan sekarang): turunkan collation
  ke `utf8mb4_unicode_ci`, pindahkan CHECK ke validasi aplikasi, pertahankan generated column &
  composite FK.
- Semua pencocokan case-insensitive di aplikasi bergantung pada collation `_ci`. Perubahan collation
  = wajib regression test pada alur import & pencarian.

---

## 15. Recommended Final Schema

### 15.1 ERD tekstual

```
locations
├── rooms
│   ├── room_aliases
│   ├── assets
│   └── mutation_logs         (from_room_id / to_room_id)
├── room_aliases              (room_aliases.location_code)
├── assets                    (assets.location_code)
└── mutation_logs             (from_location_code / to_location_code)

categories
└── subcategories
    └── assets                (via composite key category_code + subcategory_code)

rooms
└── room_aliases              (scoped: room_aliases.location_code = rooms.location_code)

assets
└── mutation_logs

users
├── assets                    (created_by / updated_by)
├── mutation_logs             (performed_by)
├── room_aliases              (created_by)
└── import_batches            (uploaded_by)

import_batches
└── import_rows
    └── assets                (import_rows.promoted_asset_id ↔ assets.import_row_id)
```

Composite / guard relationships:

```
assets        (category_code, subcategory_code)   ──FK──▶ subcategories (category_code, code)   [I2]
assets        (location_code, room_id)            ──FK──▶ rooms (location_code, id)             [I3]
room_aliases  (location_code, room_id)            ──FK──▶ rooms (location_code, id)             [I3]
mutation_logs (from_location_code, from_room_id)  ──FK──▶ rooms (location_code, id)             [I3]
mutation_logs (to_location_code, to_room_id)      ──FK──▶ rooms (location_code, id)             [I3]
```

### 15.2 Daftar tabel final & fungsi singkat

| # | tabel | PK | fungsi singkat |
|---|---|---|---|
| 1 | `locations` | `code` CHAR(2) | Master 4 lokasi (YAYASAN/PH, SD, SMP, SMA). Statis. Induk ruangan, alias, & komponen-1 nomor aset. |
| 2 | `categories` | `code` CHAR(2) | Master 6 kategori barang. Statis. Induk subkategori & komponen-2 nomor aset. |
| 3 | `subcategories` | `id` (+U `category_code,code`) | Master subkategori per kategori. Kode unik hanya dalam kategori. Nama = data Excel. |
| 4 | `rooms` | `id` (+U `location_code,name`; +U `location_code,id`) | Master ruangan **per lokasi**. CRUD dari aplikasi, `is_active` untuk nonaktif. Nama sama lintas lokasi = room berbeda. 15 kanonik lokasi `01` di-seed. |
| 5 | `room_aliases` | `id` (+U `location_code,match_key`) | Kamus "raw string Excel → room", **scoped per lokasi**. Bisa ditambah dari aplikasi. Basis pencocokan import. |
| 6 | `assets` | `id` (+U 5-tuple, +U `asset_code`) | **Inti.** 1 baris = 1 physical unit (`quantity=1`). Komponen nomor aset terpisah + `asset_code` generated. Penempatan ruangan (`room_id` NULL = unmapped), kondisi, penghapusan bisnis (`is_written_off`), atribut deskriptif, soft delete (koreksi). |
| 7 | `mutation_logs` | `id` | Histori **append-only** perubahan aset (pindah/perbaikan/penghapusan/kondisi). Snapshot label ruangan + composite FK RESTRICT agar histori awet. Tanpa `updated_at`. |
| 8 | `users` | `id` (+U `email`) | Akun + `role` ENUM (`admin`/`operator`/`viewer`) + `is_active`. |
| 9 | `import_batches` | `id` | 1 baris = 1 proses import 1 file/sheet = 1 kategori aset. Status + ringkasan validasi/promosi. |
| 10 | `import_rows` | `id` (+U `batch_id,row_number`) | Baris Excel mentah (`raw_payload JSON`) + hasil parse + status validasi. Staging sebelum promosi ke `assets`. `duplicate_of_asset_id` (gagal) vs `promoted_asset_id` (sukses) terpisah. |

**Non-domain (bawaan Laravel/starter, di luar scope desain ini):** `password_reset_tokens`,
`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`.

---

## Ringkasan keputusan penting

1. **Kode master = `CHAR`, bukan INT.** `locations`/`categories` PK natural; `subcategories` surrogate + natural unique `(category_code, code)`. Kode **immutable** setelah dipakai aset (§13).
2. **Nomor aset = Opsi C:** 5 komponen terpisah (source of truth) + `asset_code` GENERATED STORED UNIQUE (dihasilkan DB, bukan app layer).
3. **`sequence_no` = `VARCHAR`, apa adanya** (B2). **Generator berlanjut lintas tahun** — scope `location+category+subcategory`, `asset_year` bukan bagian scope (B1). Concurrency: transaction + MAX + retry, DB `UNIQUE` = proteksi final.
4. **`asset_year`** (rename dari `year`) — bagian identitas unik, bukan bagian scope generator.
5. **`room_aliases` & semua pencocokan ruangan location-aware** (B7): `location_code` NN, unique `(location_code, match_key)`, composite FK ke `rooms(location_code, id)`. Nama ruangan sama di lokasi berbeda = master room berbeda.
6. **`room_status` dihapus** dari `assets` — `room_id IS NULL` = unmapped. `room_raw_value` dipertahankan.
7. **`assets.quantity` dikunci `= 1`** (`CHECK`), grouped asset tidak didukung (B6).
8. **`rooms`/`users` pakai `is_active`; `assets` pakai `deleted_at` (koreksi) + `is_written_off` (penghapusan bisnis)**; `mutation_logs` append-only tanpa `updated_at`. Nomor aset tidak di-reuse walau soft-deleted.
9. **Integritas silang via composite FK:** `(category_code, subcategory_code)` + `(location_code, room_id)` di tiga tabel.
10. **Staging `import_batches` + `import_rows` dipertahankan**; `raw_payload JSON` untuk audit; validasi wajib sebelum promosi ke `assets`.
11. **Kompatibilitas produksi cPanel** (§14): schema butuh MySQL 8.0.16+ untuk CHECK + collation `utf8mb4_0900_ai_ci`. Verifikasi `SELECT VERSION()` sebelum Tahap 3; jangan ganti collation tanpa alasan.

**Menunggu keputusan sebelum Tahap 3 (migration implementation).**
