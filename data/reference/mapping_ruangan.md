# Kamus Normalisasi Ruangan — Inventaris Vidatra (Tahap 1B)

Status: **dokumen referensi untuk review**. Bukan tabel database, bukan migration.
Sumber: kolom `Ruangan` pada 3 file aset di `excel/` (394 baris, semua lokasi `01` / YAYASAN-PH).

## Keputusan yang diterapkan

- Daftar ruangan kanonik lokasi `01` ditetapkan user, ditambah 5 ruangan baru hasil konfirmasi
  (Ruangan Cikini, Ruangan PLS, Gudang, WC Pria, WC Wanita).
- Seluruh 59 string mentah kini terpetakan ke ruangan kanonik. **Tidak ada lagi bucket `Lainnya`.**
- `Kartu Inventaris Ruangan.xlsx` diabaikan.
- Mapping di bawah = **keputusan final untuk referensi Tahap 1**.

### Konfirmasi terakhir user (menggantikan `Lainnya` sebelumnya)

| # | nama_mentah | ruangan_kanonik |
|---|---|---|
| 1 | `PERSO DAN UMUM` | Ruangan Personalia & SARPRAS |
| 2 | `SARANA DAN HUMAS` | Ruangan Personalia & SARPRAS |
| 3 | `SARANA & HUMAS` | Ruangan Personalia & SARPRAS |
| 4 | `SARANA DAN HUM` | Ruangan Personalia & SARPRAS |
| 5 | `ADM UMUM` | Ruangan Personalia & SARPRAS |
| 6 | `umum` | Ruangan Personalia & SARPRAS |
| 7 | `CIKINI` | Ruangan Cikini *(baru)* |
| 8 | `Adm PLS` | Ruangan PLS *(baru)* |
| 9 | `Kabid PLS` | Ruangan PLS |
| 10 | `GUDANG` | Gudang *(baru)* |
| 11 | `PERSO DAN HUMAS` | Ruangan Personalia & SARPRAS |
| 12 | `WC PRIA` | WC Pria *(baru)* |
| 13 | `WC WANITA` | WC Wanita *(baru)* |

## Cara baca

Tabel utama (bagian 3): `nama_mentah_excel` → `ruangan_kanonik` → `lokasi` → `catatan`.
`Σ` = jumlah baris aset yang memakai string itu. `file`: M = Meubelair, E = Elektronik, K = Alat Kebersihan.

---

## 1. Daftar Ruangan Kanonik (lokasi `01` — YAYASAN / PH)

| # | ruangan_kanonik | pengguna / PIC (per keputusan user) | Σ baris termapping |
|---|---|---|---:|
| 1 | Ruangan Ketua Harian | Ketua Harian | 41 |
| 2 | Ruangan Personalia & SARPRAS | Kabid SARPRAS | 97 |
| 3 | Ruangan Admin Personalia / SARPRAS | Admin Personalia / SARPRAS | 9 |
| 4 | Ruangan Pendidikan | Kabid Pendidikan & Admin Pendidikan | 41 |
| 5 | Ruangan Keuangan | Kabag Keuangan & Admin Keuangan | 53 |
| 6 | Ruangan Rapat | – | 91 |
| 7 | Ruangan Humas IT | Admin IT | 6 |
| 8 | Ruangan Usaha | – | 40 |
| 9 | Musholla | – | 2 |
| 10 | CS | – | 1 |
| 11 | Ruangan Cikini | – | 4 |
| 12 | Ruangan PLS | – | 5 |
| 13 | Gudang | – | 2 |
| 14 | WC Pria | – | 1 |
| 15 | WC Wanita | – | 1 |
| | **Total** | | **394** |

Semua ruangan di atas berada di lokasi `01`. Tidak ada ruangan kanonik bernama `Lainnya`.

---

## 2. Ringkasan mapping per ruangan kanonik

| ruangan_kanonik | string mentah yang digabung (Σ) |
|---|---|
| Ruangan Ketua Harian | `KETUA HARIAN` (35), `KETUA HARIAN1` (2), `KETUA HARIAN2` (2), `Ketua Harian` (1), `ADM KA HARIAN` (1) |
| Ruangan Personalia & SARPRAS | `PERSO DAN UMUM` (35), `SARANA DAN HUMAS` (20), `SARANA & HUMAS` (11), `ADM UMUM` (6), `PERSONALIA` (3), `SARANA` (3), `Sarpras` (3), `SARANA DAN HUM` (3), `umum` (3), `Personalia` (2), `PERSO DAN HUMAS` (2), `SARANA PRASARANA` (4), `Sarana` (1), `KABIS SARPRAS` (1) |
| Ruangan Admin Personalia / SARPRAS | `Adm Personalia` (4), `ADM PERSONALIA` (2), `ADM PERSO` (1), `Adm Sarpras` (2) |
| Ruangan Pendidikan | `PENDIDIKAN` (36), `PENDIDIKAN1` (2), `PENDIDIKAN2` (2), `KABID PENDIDIKAN` (1) |
| Ruangan Keuangan | `KEUANGAN` (23), `BAG. KEUANGAN` (7), `Adm Keuangan` (7), `BAG. KUANGAN` (6), `KABAG KEUANGAN` (3), `ADM KEUANGAN` (2), `Keuangan` (1), `Kabag Keuangan` (1), `Kabag keuangan` (1), `Adm keuangan` (1), `R. KEUANGAN` (1) |
| Ruangan Rapat | `R. RAPAT` (38), `R. RAPAT YAYASAN` (21), `R.RAPAT` (14), `RAPAT MEETING` (8), `RUANG RAPAT` (5), `R. MEETING` (5) |
| Ruangan Humas IT | `HUMAS IT` (6) |
| Ruangan Usaha | `USAHA` (33), `Adm Usaha` (2), `Kabag Usaha` (2), `ADM USAHA` (1), `ADM Usaha` (1), `KABAG Usaha` (1) |
| Musholla | `MUSHOLLAH` (2) |
| CS | `CS` (1) |
| Ruangan Cikini | `CIKINI` (4) |
| Ruangan PLS | `Adm PLS` (3), `Kabid PLS` (2) |
| Gudang | `GUDANG` (2) |
| WC Pria | `WC PRIA` (1) |
| WC Wanita | `WC WANITA` (1) |

---

## 3. Tabel mapping lengkap (59 string)

### 3.1 Ruangan Ketua Harian

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `KETUA HARIAN` | 35 | M,E,K | Ruangan Ketua Harian | 01 | – |
| `KETUA HARIAN1` | 2 | E | Ruangan Ketua Harian | 01 | Sufiks `1` diduga penanda unit AC, bukan ruangan berbeda. |
| `KETUA HARIAN2` | 2 | E | Ruangan Ketua Harian | 01 | Idem. |
| `Ketua Harian` | 1 | K | Ruangan Ketua Harian | 01 | Beda kapitalisasi. |
| `ADM KA HARIAN` | 1 | E | Ruangan Ketua Harian | 01 | "Admin Ketua Harian" — pengguna admin, ruangan sama. |

### 3.2 Ruangan Personalia & SARPRAS

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `PERSO DAN UMUM` | 35 | M,E,K | Ruangan Personalia & SARPRAS | 01 | Keputusan final user: "Personalia dan Umum" = unit Personalia & SARPRAS. |
| `SARANA DAN HUMAS` | 20 | M,E | Ruangan Personalia & SARPRAS | 01 | Keputusan final user: bagian Sarana & Humas dicatat sebagai Ruangan Personalia & SARPRAS. |
| `SARANA & HUMAS` | 11 | M | Ruangan Personalia & SARPRAS | 01 | Varian dari `SARANA DAN HUMAS`. |
| `ADM UMUM` | 6 | E | Ruangan Personalia & SARPRAS | 01 | Keputusan final user: "Admin Umum" = Personalia & SARPRAS. |
| `SARANA PRASARANA` | 4 | E | Ruangan Personalia & SARPRAS | 01 | "Sarana Prasarana" = SARPRAS. |
| `PERSONALIA` | 3 | E | Ruangan Personalia & SARPRAS | 01 | – |
| `SARANA` | 3 | M,K | Ruangan Personalia & SARPRAS | 01 | SARPRAS. |
| `Sarpras` | 3 | E | Ruangan Personalia & SARPRAS | 01 | SARPRAS. |
| `SARANA DAN HUM` | 3 | E | Ruangan Personalia & SARPRAS | 01 | Varian terpotong dari `SARANA DAN HUMAS`. |
| `umum` | 3 | E | Ruangan Personalia & SARPRAS | 01 | Keputusan final user: "umum" = Personalia & SARPRAS. |
| `Personalia` | 2 | E | Ruangan Personalia & SARPRAS | 01 | Beda kapitalisasi. |
| `PERSO DAN HUMAS` | 2 | E | Ruangan Personalia & SARPRAS | 01 | Keputusan final user: "Personalia dan Humas" = Personalia & SARPRAS. |
| `Sarana` | 1 | E | Ruangan Personalia & SARPRAS | 01 | SARPRAS. |
| `KABIS SARPRAS` | 1 | E | Ruangan Personalia & SARPRAS | 01 | "KABIS" diduga salah ketik "KABID"; bagian SARPRAS. |

### 3.3 Ruangan Admin Personalia / SARPRAS

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `Adm Personalia` | 4 | E | Ruangan Admin Personalia / SARPRAS | 01 | – |
| `ADM PERSONALIA` | 2 | E | Ruangan Admin Personalia / SARPRAS | 01 | Beda kapitalisasi. |
| `ADM PERSO` | 1 | E | Ruangan Admin Personalia / SARPRAS | 01 | Singkatan "Personalia". |
| `Adm Sarpras` | 2 | E | Ruangan Admin Personalia / SARPRAS | 01 | "Admin SARPRAS" → ruang admin gabungan Personalia/SARPRAS. |

### 3.4 Ruangan Pendidikan

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `PENDIDIKAN` | 36 | M,E,K | Ruangan Pendidikan | 01 | – |
| `PENDIDIKAN1` | 2 | E | Ruangan Pendidikan | 01 | Sufiks `1` diduga penanda unit AC. |
| `PENDIDIKAN2` | 2 | E | Ruangan Pendidikan | 01 | Idem. |
| `KABID PENDIDIKAN` | 1 | E | Ruangan Pendidikan | 01 | Pengguna = Kabid Pendidikan. |

### 3.5 Ruangan Keuangan

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `KEUANGAN` | 23 | M,E,K | Ruangan Keuangan | 01 | – |
| `BAG. KEUANGAN` | 7 | M | Ruangan Keuangan | 01 | "Bagian Keuangan". |
| `Adm Keuangan` | 7 | E | Ruangan Keuangan | 01 | Pengguna = Admin Keuangan. |
| `BAG. KUANGAN` | 6 | M | Ruangan Keuangan | 01 | Salah ketik "KEUANGAN". |
| `KABAG KEUANGAN` | 3 | E | Ruangan Keuangan | 01 | Pengguna = Kabag Keuangan. |
| `ADM KEUANGAN` | 2 | E | Ruangan Keuangan | 01 | Beda kapitalisasi. |
| `Keuangan` | 1 | E | Ruangan Keuangan | 01 | Beda kapitalisasi. |
| `Kabag Keuangan` | 1 | E | Ruangan Keuangan | 01 | – |
| `Kabag keuangan` | 1 | E | Ruangan Keuangan | 01 | – |
| `Adm keuangan` | 1 | E | Ruangan Keuangan | 01 | – |
| `R. KEUANGAN` | 1 | M | Ruangan Keuangan | 01 | "Ruang Keuangan". |

### 3.6 Ruangan Rapat

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `R. RAPAT` | 38 | M,E | Ruangan Rapat | 01 | – |
| `R. RAPAT YAYASAN` | 21 | M,E | Ruangan Rapat | 01 | Ditandai "Yayasan"; diasumsikan ruang rapat utama yang sama. Perlu konfirmasi bila ada >1 ruang rapat fisik. |
| `R.RAPAT` | 14 | M,E | Ruangan Rapat | 01 | Tanpa spasi. |
| `RAPAT MEETING` | 8 | E | Ruangan Rapat | 01 | – |
| `RUANG RAPAT` | 5 | E | Ruangan Rapat | 01 | – |
| `R. MEETING` | 5 | E,K | Ruangan Rapat | 01 | "Meeting" = rapat. |

### 3.7 Ruangan Humas IT

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `HUMAS IT` | 6 | E | Ruangan Humas IT | 01 | Pengguna = Admin IT. Semua aset kamera/lensa/tripod. |

### 3.8 Ruangan Usaha

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `USAHA` | 33 | M,E,K | Ruangan Usaha | 01 | – |
| `Adm Usaha` | 2 | E | Ruangan Usaha | 01 | – |
| `Kabag Usaha` | 2 | E | Ruangan Usaha | 01 | – |
| `ADM USAHA` | 1 | E | Ruangan Usaha | 01 | – |
| `ADM Usaha` | 1 | E | Ruangan Usaha | 01 | – |
| `KABAG Usaha` | 1 | E | Ruangan Usaha | 01 | – |

### 3.9 Musholla

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `MUSHOLLAH` | 2 | E | Musholla | 01 | Ejaan varian. Aset = AC indoor/outdoor. |

### 3.10 CS

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `CS` | 1 | E | CS | 01 | Sesuai daftar ruangan user. Aset = kabel roll. |

### 3.11 Ruangan Cikini *(ruangan baru)*

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `CIKINI` | 4 | E | Ruangan Cikini | 01 | Keputusan final user: dibuat ruangan tersendiri. Semua asetnya (printer) berstatus "Di Junk" pada data Excel. |

### 3.12 Ruangan PLS *(ruangan baru)*

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `Adm PLS` | 3 | E | Ruangan PLS | 01 | Keputusan final user: dibuat ruangan tersendiri. |
| `Kabid PLS` | 2 | E | Ruangan PLS | 01 | Idem. Pengguna = Kabid PLS. |

### 3.13 Gudang *(ruangan baru)*

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `GUDANG` | 2 | K | Gudang | 01 | Keputusan final user: dibuat ruangan tersendiri. Aset = vacuum cleaner & high pressure. |

### 3.14 WC Pria *(ruangan baru)*

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `WC PRIA` | 1 | K | WC Pria | 01 | Keputusan final user: dibuat ruangan tersendiri. Aset = tempat sampah. |

### 3.15 WC Wanita *(ruangan baru)*

| nama_mentah_excel | Σ | file | ruangan_kanonik | lokasi | catatan |
|---|---:|---|---|---|---|
| `WC WANITA` | 1 | K | WC Wanita | 01 | Keputusan final user: dibuat ruangan tersendiri. Aset = tempat sampah. |

---

## 4. Verifikasi jumlah

| ruangan_kanonik | Σ |
|---|---:|
| Ruangan Personalia & SARPRAS | 97 |
| Ruangan Rapat | 91 |
| Ruangan Keuangan | 53 |
| Ruangan Ketua Harian | 41 |
| Ruangan Pendidikan | 41 |
| Ruangan Usaha | 40 |
| Ruangan Admin Personalia / SARPRAS | 9 |
| Ruangan Humas IT | 6 |
| Ruangan PLS | 5 |
| Ruangan Cikini | 4 |
| Musholla | 2 |
| Gudang | 2 |
| CS | 1 |
| WC Pria | 1 |
| WC Wanita | 1 |
| **Total** | **394** ✅ |

Semua 394 baris terpetakan. Tidak ada `Lainnya`.

---

## 5. Requirement desain aplikasi ke depan (dicatat, bukan diimplementasi di Tahap 1)

1. **Fitur Kelola Ruangan** — aplikasi harus menyediakan CRUD ruangan: tambah, ubah, dan
   **nonaktifkan** (soft-disable, bukan hapus permanen bila sudah dipakai aset).
2. **Ruangan = master data**, dikelola dari aplikasi. **Tidak boleh hard-code** daftar ruangan
   di kode (frontend maupun backend). Dokumen ini hanya data awal untuk seeding, bukan sumber
   runtime.
3. **Ruangan berelasi dengan lokasi** (setiap ruangan wajib punya `lokasi`). Semua ruangan di
   dokumen ini milik lokasi `01`.
4. Atribut minimum yang tersirat dari kebutuhan: `nama`, `lokasi`, `status_aktif`,
   opsional `pengguna/PIC` dan `catatan`.
5. **`Lainnya` bukan ruangan kanonik.** Saat import data aset, string `Ruangan` yang tidak
   cocok dengan master ruangan / kamus ini **ditandai "belum terpetakan" untuk ditinjau
   manual** — jangan otomatis membuat master ruangan bernama `Lainnya` atau membuat ruangan
   baru tanpa persetujuan.
6. Kamus di bagian 3 sebaiknya menjadi **tabel alias** (nama_mentah → ruangan) yang juga bisa
   ditambah dari aplikasi, sehingga import berikutnya bisa mengenali varian penulisan baru
   tanpa ubah kode.

---

## 6. Catatan kecil yang masih terbuka (tidak memblokir Tahap 1)

- `R. RAPAT YAYASAN` (21) diperlakukan sama dengan `Ruangan Rapat`. Bila ternyata ada lebih dari
  satu ruang rapat fisik, pisahkan pada tahap seeding.
- Sufiks angka pada `KETUA HARIAN1/2` dan `PENDIDIKAN1/2` diperlakukan sebagai penanda unit AC,
  bukan ruangan berbeda.
- Pengguna/PIC untuk Ruangan Cikini, PLS, Gudang, WC Pria, WC Wanita belum ditentukan
  (kolom PIC dikosongkan).

Tidak ada perubahan pada file Excel maupun kode aplikasi. Dokumen ini murni untuk review.
