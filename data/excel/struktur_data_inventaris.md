# Struktur Data — Sistem Inventaris Barang Sekolah/Yayasan

Versi final. Disusun dari analisis:
`Panduan_Penomoran_Inventaris.xlsx`, `Inventaris_Alat_Kebersihan.xlsx`, `Inventaris_Elektronik.xlsx`,
`Inventaris_Meubelair.xlsx`, `Kartu_Inventaris_Ruangan.xlsx`.

---

## 1. Prinsip desain

1. **Nomor aset tidak diubah.** Format lama `LOKASI.KATEGORI.SUBKATEGORI.NOURUT.TAHUN` (mis. `01.06.001.001.2019`)
   tetap dipakai apa adanya untuk data lama, dan dilanjutkan (bukan diformat ulang) untuk data baru.
2. **Satu tabel `aset` untuk semua kategori barang** (Elektronik, Meubelair, Alat Kebersihan, dst).
   Kolom yang hanya relevan untuk kategori tertentu dibuat *nullable*, bukan dipisah per tabel kategori.
3. **Kartu Inventaris Ruangan bukan tabel tersendiri** — ia adalah laporan (view/query) atas tabel `aset`
   yang difilter berdasarkan `ruangan_id`. Barang kelompok (mis. 30 kursi identik) cukup satu baris
   `aset` dengan `jumlah = 30`, tidak perlu 30 baris terpisah.
4. **Kode kategori/subkategori mengikuti daftar resmi di Panduan Penomoran**, bukan dropdown bebas —
   input di aplikasi harus memilih dari master, tidak mengetik manual, untuk mencegah salah kode
   (contoh kasus: 008 Kamera Foto vs 009 Handycam yang mirip).

---

## 2. Entitas & relasi

```
LOKASI (1) ───< ASET (N)
KATEGORI (1) ───< SUBKATEGORI (N)
SUBKATEGORI (1) ───< ASET (N)
RUANGAN (1) ───< ASET (N)
ASET (1) ───< MUTASI_LOG (N)
USER (1) ───< MUTASI_LOG (N)
```

- **Kartu Inventaris Ruangan** = `SELECT * FROM aset WHERE ruangan_id = :id`, dikelompokkan per subkategori.
- **Rekap per kategori/kondisi** = `GROUP BY kategori_kode, keadaan`.

---

## 3. Kamus kolom

### 3.1 `lokasi` — master tetap, hanya admin yang ubah
| Kolom | Tipe | Wajib | Keterangan |
|---|---|---|---|
| kode | CHAR(2) PK | ya | "01" Yayasan, "02" SD, "03" SMP, "04" SMA |
| nama | VARCHAR(50) | ya | |

### 3.2 `kategori` — master tetap
| Kolom | Tipe | Wajib | Keterangan |
|---|---|---|---|
| kode | CHAR(2) PK | ya | "01".."06" |
| nama | VARCHAR(50) | ya | Tanah&Bangunan, Meubelair, Elektronik, Mekanik, Alat Rumah Tangga, Alat Kebersihan |

### 3.3 `subkategori` — master tetap, PK gabungan
| Kolom | Tipe | Wajib | Keterangan |
|---|---|---|---|
| kategori_kode | CHAR(2) PK, FK → kategori | ya | |
| kode | CHAR(3) PK | ya | "001", "002", … — **tidak unik lintas kategori**, hanya unik dalam satu kategori |
| nama | VARCHAR(100) | ya | mis. "Komputer", "Meja", "Kamera Foto" |

### 3.4 `ruangan`
| Kolom | Tipe | Wajib | Keterangan |
|---|---|---|---|
| id | SERIAL PK | ya | |
| nama | VARCHAR(100) | ya | mis. "Ruang M (Kelas XII)" atau "KEUANGAN" |
| tipe | ENUM | ya | `unit_kerja` atau `ruang_fisik` |
| lokasi_kode | CHAR(2), FK → lokasi | tidak | opsional, untuk ruang fisik yang punya kode lokasi sendiri |
| unit_kerja_induk | VARCHAR(100) | tidak | untuk `ruang_fisik`, mis. "SMA" |

### 3.5 `aset` — tabel inti
| Kolom | Tipe | Wajib | Berlaku untuk | Keterangan |
|---|---|---|---|---|
| id | SERIAL PK | ya | semua | |
| nomor_aset | VARCHAR(30) UNIQUE | ya | semua | string asli, **tidak digenerate ulang** untuk data lama |
| lokasi_kode | CHAR(2), FK → lokasi | ya | semua | |
| kategori_kode | CHAR(2), FK → kategori | ya | semua | disimpan terpisah walau bisa didapat via subkategori, untuk mempercepat filter |
| subkategori_kode | CHAR(3) | ya | semua | FK gabungan ke `subkategori(kategori_kode, kode)` |
| no_urut | INT | ya | semua | bagian dari nomor_aset, dipakai untuk lanjutan penomoran |
| tahun | INT | ya | semua | tahun pembelian/pendataan |
| ruangan_id | INT, FK → ruangan | tidak | semua | lokasi penempatan saat ini |
| jumlah | INT DEFAULT 1 | ya | semua | 1 = unit tunggal, >1 = kelompok identik |
| keadaan | ENUM | ya | semua | `Baik`, `Kurang Baik`, `Rusak Berat` |
| merk_model | VARCHAR(100) | tidak | semua | |
| no_seri_pabrik | VARCHAR(100) | tidak | semua | |
| bahan | VARCHAR(50) | tidak | semua | |
| tanggal_pembelian | DATE | tidak | semua | |
| sumber_dana | VARCHAR(50) | tidak | semua | mis. BOSDA, YYS (dari Kartu Ruangan) |
| jenis_detail | VARCHAR(100) | tidak | **khusus Elektronik** | mis. "Monitor", "CPU", "LCD" |
| status_hapus | BOOLEAN DEFAULT false | tidak | **khusus Elektronik** | true = sudah di-junk |
| tanggal_hapus | DATE | tidak | **khusus Elektronik** | |
| kapasitas_ket | VARCHAR(100) | tidak | **khusus Alat Kebersihan** | mis. "12 liter" |
| keterangan | TEXT | tidak | semua | catatan bebas |
| dibuat_pada | TIMESTAMP DEFAULT now() | ya | semua | audit |
| diubah_pada | TIMESTAMP | tidak | semua | audit |

### 3.6 `mutasi_log`
| Kolom | Tipe | Wajib | Keterangan |
|---|---|---|---|
| id | SERIAL PK | ya | |
| aset_id | INT, FK → aset | ya | |
| tanggal | DATE | ya | |
| jenis_mutasi | ENUM | ya | `Pindah Ruangan`, `Perbaikan`, `Penghapusan`, `Lainnya` |
| ruangan_asal_id | INT, FK → ruangan | tidak | |
| ruangan_tujuan_id | INT, FK → ruangan | tidak | |
| keterangan | TEXT | tidak | |
| dicatat_oleh | INT, FK → user | tidak | |

### 3.7 `user`
| Kolom | Tipe | Wajib | Keterangan |
|---|---|---|---|
| id | SERIAL PK | ya | |
| nama | VARCHAR(100) | ya | |
| email | VARCHAR(100) UNIQUE | ya | |
| role | ENUM | ya | `admin`, `staff_sarpras`, `kepala_unit`, `viewer` |

---

## 4. DDL SQL (PostgreSQL)

```sql
CREATE TABLE lokasi (
  kode CHAR(2) PRIMARY KEY,
  nama VARCHAR(50) NOT NULL
);

CREATE TABLE kategori (
  kode CHAR(2) PRIMARY KEY,
  nama VARCHAR(50) NOT NULL
);

CREATE TABLE subkategori (
  kategori_kode CHAR(2) NOT NULL REFERENCES kategori(kode),
  kode CHAR(3) NOT NULL,
  nama VARCHAR(100) NOT NULL,
  PRIMARY KEY (kategori_kode, kode)
);

CREATE TYPE tipe_ruangan AS ENUM ('unit_kerja', 'ruang_fisik');

CREATE TABLE ruangan (
  id SERIAL PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  tipe tipe_ruangan NOT NULL,
  lokasi_kode CHAR(2) REFERENCES lokasi(kode),
  unit_kerja_induk VARCHAR(100)
);

CREATE TYPE keadaan_barang AS ENUM ('Baik', 'Kurang Baik', 'Rusak Berat');

CREATE TABLE aset (
  id SERIAL PRIMARY KEY,
  nomor_aset VARCHAR(30) NOT NULL UNIQUE,
  lokasi_kode CHAR(2) NOT NULL REFERENCES lokasi(kode),
  kategori_kode CHAR(2) NOT NULL REFERENCES kategori(kode),
  subkategori_kode CHAR(3) NOT NULL,
  no_urut INT NOT NULL,
  tahun INT NOT NULL,
  ruangan_id INT REFERENCES ruangan(id),
  jumlah INT NOT NULL DEFAULT 1,
  keadaan keadaan_barang NOT NULL,
  merk_model VARCHAR(100),
  no_seri_pabrik VARCHAR(100),
  bahan VARCHAR(50),
  tanggal_pembelian DATE,
  sumber_dana VARCHAR(50),
  jenis_detail VARCHAR(100),
  status_hapus BOOLEAN NOT NULL DEFAULT false,
  tanggal_hapus DATE,
  kapasitas_ket VARCHAR(100),
  keterangan TEXT,
  dibuat_pada TIMESTAMP NOT NULL DEFAULT now(),
  diubah_pada TIMESTAMP,
  FOREIGN KEY (kategori_kode, subkategori_kode) REFERENCES subkategori(kategori_kode, kode)
);

CREATE INDEX idx_aset_lokasi ON aset(lokasi_kode);
CREATE INDEX idx_aset_kategori ON aset(kategori_kode);
CREATE INDEX idx_aset_ruangan ON aset(ruangan_id);
CREATE INDEX idx_aset_keadaan ON aset(keadaan);

CREATE TYPE jenis_mutasi_enum AS ENUM ('Pindah Ruangan', 'Perbaikan', 'Penghapusan', 'Lainnya');
CREATE TYPE role_user AS ENUM ('admin', 'staff_sarpras', 'kepala_unit', 'viewer');

CREATE TABLE app_user (
  id SERIAL PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  role role_user NOT NULL
);

CREATE TABLE mutasi_log (
  id SERIAL PRIMARY KEY,
  aset_id INT NOT NULL REFERENCES aset(id),
  tanggal DATE NOT NULL,
  jenis_mutasi jenis_mutasi_enum NOT NULL,
  ruangan_asal_id INT REFERENCES ruangan(id),
  ruangan_tujuan_id INT REFERENCES ruangan(id),
  keterangan TEXT,
  dicatat_oleh INT REFERENCES app_user(id)
);
```

---

## 5. Logika generate nomor aset (data baru)

```
input  : lokasi_kode, kategori_kode, subkategori_kode, tahun
proses :
  no_urut = (SELECT MAX(no_urut) FROM aset
             WHERE lokasi_kode = :lokasi_kode
               AND kategori_kode = :kategori_kode
               AND subkategori_kode = :subkategori_kode) + 1
  nomor_aset = f"{lokasi_kode}.{kategori_kode}.{subkategori_kode}.{no_urut:03d}.{tahun}"
output : nomor_aset, no_urut
```

Catatan: format pemisah pada data lama tidak selalu konsisten (titik pada file barang,
strip pada Kartu Ruangan). Simpan `nomor_aset` sesuai string asli hasil migrasi (jangan dipaksa
seragam), tapi untuk entri **baru**, selalu pakai format titik (`.`) sebagai standar ke depan.

---

## 6. Query laporan turunan (bukan tabel baru)

**Kartu Inventaris Ruangan** (per ruangan):
```sql
SELECT a.nomor_aset, s.nama AS jenis_barang, a.merk_model, a.no_seri_pabrik,
       a.bahan, a.tahun, a.jumlah, a.sumber_dana, a.keadaan, a.keterangan
FROM aset a
JOIN subkategori s ON s.kategori_kode = a.kategori_kode AND s.kode = a.subkategori_kode
WHERE a.ruangan_id = :ruangan_id
ORDER BY s.nama;
```

**Rekap kondisi per kategori:**
```sql
SELECT k.nama AS kategori, a.keadaan, COUNT(*) AS jumlah_baris, SUM(a.jumlah) AS total_unit
FROM aset a
JOIN kategori k ON k.kode = a.kategori_kode
GROUP BY k.nama, a.keadaan
ORDER BY k.nama, a.keadaan;
```

---

## 7. Catatan migrasi dari Excel

1. Import `lokasi`, `kategori`, `subkategori` dari `Panduan_Penomoran_Inventaris.xlsx` — gunakan daftar
   resmi ini sebagai satu-satunya sumber kebenaran kode, **jangan** menurunkan dari nama kolom di
   3 file barang (karena bisa berbeda, contoh: 008/009 Kamera Foto vs Handycam).
2. Bersihkan dahulu 3 file barang: samakan huruf besar/kecil pada `Keadaan Barang` (`v`/`V` → `Baik`),
   ganti `-`/`NaN` teks jadi NULL, cek duplikasi `no_urut`.
3. Pisahkan isi kolom "Ruangan" di 3 file barang menjadi baris-baris `ruangan` dengan
   `tipe = 'unit_kerja'`; isi "Ruangan" di Kartu Inventaris Ruangan menjadi `tipe = 'ruang_fisik'`.
4. Import baris barang ke `aset`, mapping `subkategori_kode` berdasarkan judul blok di dalam
   masing-masing file (mis. blok "002 KURSI" → subkategori_kode = '002').
5. Set `ruangan_id` pada `aset` dengan mencocokkan nama unit kerja/ruangan ke tabel `ruangan` yang
   sudah dibuat di langkah 3.
6. Validasi akhir: jumlah baris per kategori di database harus sama dengan jumlah baris (bukan header)
   di file Excel asal, dan setiap `nomor_aset` harus persis sama dengan yang tertulis di Excel.
