# Panduan Pengguna — Sumber Aset

Status: berlaku sejak Tahap 2 (Sumber Aset). Dokumen ini untuk pengguna aplikasi
(operator, admin unit, admin). Rincian teknis API ada di `docs/api_convention.md` §17.

---

## 1. Apa itu "Sumber"?

Setiap aset sekarang memiliki **Sumber**, yaitu kategori asal pendanaan aset yang
dipilih dari daftar tetap. Sumber dipakai untuk mengelompokkan, memfilter, dan
menganalisis aset lintas unit (Inventaris, Laporan, Dashboard, dan Export Excel).

| Pilihan | Arti |
|---|---|
| **BOSDA** | Bantuan Operasional Sekolah Daerah. |
| **BOSP** | Bantuan Operasional Satuan Pendidikan. |
| **BOSTK** | Dana BOSTK, sesuai istilah yang digunakan unit. |
| **Yayasan** | Dibiayai oleh yayasan. |
| **Lainnya** | Sumber lain di luar kategori di atas. Jelaskan di **Keterangan Sumber**. |
| **Belum diberi keterangan** | Sumbernya belum diketahui saat aset dicatat. |

### "Belum diberi keterangan" bukan kesalahan

Aset boleh dicatat sebelum sumber pendanaannya diketahui. Pilih **Belum diberi
keterangan** untuk kondisi tersebut, lalu ubah kemudian melalui **Edit Aset** atau
**Edit Massal** setelah sumbernya diketahui.

- Pilihan ini **tidak sama dengan Yayasan**. Sistem tidak pernah mengisi Yayasan secara otomatis.
- Semua aset yang sudah tercatat sebelum fitur ini diterapkan (termasuk aset di
  Sampah Aset) bersumber **Belum diberi keterangan**, bukan Yayasan. Lengkapi
  sumbernya secara bertahap.

## 2. Sumber vs Keterangan Sumber

| | **Sumber** | **Keterangan Sumber** |
|---|---|---|
| Jenis | Pilihan tetap (6 kategori di atas) | Teks bebas, opsional (maks. 80 karakter) |
| Wajib? | Wajib dipilih saat menambah aset | Tidak wajib |
| Dipakai untuk | Filter, laporan, dashboard, export | Catatan rinci, mis. nama program atau donatur |
| Contoh | `Lainnya` | `Hibah alumni angkatan 2010` |

"Keterangan Sumber" adalah nama baru untuk kolom yang dulu berlabel **Sumber Dana**.
Isinya tidak diubah, tidak dipindahkan, dan tidak digabung dengan Sumber. Riwayat
perubahan lama yang menyebut "Sumber Dana" kini tampil dengan label "Keterangan Sumber".

## 3. Mengisi Sumber di formulir

- **Tambah Aset** dan **Tambah Banyak Aset**: pilih Sumber secara eksplisit. Tidak
  ada pilihan yang terpilih otomatis. Pada Tambah Banyak Aset, Sumber yang dipilih
  berlaku untuk semua aset yang dibuat.
- **Edit Aset**: Sumber saat ini ditampilkan. Sumber hanya berubah jika Anda memilih
  nilai lain. Sumber tidak bisa dikosongkan; gunakan "Belum diberi keterangan".
- **Edit Massal** (pilih beberapa aset di Inventaris → Edit): centang **Sumber**, lalu
  pilih nilainya. Jika Sumber tidak dicentang, sumber aset yang dipilih tidak berubah.
- Setiap perubahan Sumber tercatat di **Riwayat Perubahan** pada halaman detail aset,
  lengkap dengan nilai sebelum dan sesudah, dan dapat di-revert seperti perubahan lain.

## 4. Inventaris, Export, Laporan, dan Dashboard

- **Inventaris**: kolom **Sumber** tampil di antara Kategori dan Ruangan. Di layar HP,
  Sumber tampil sebagai label kecil di bawah nama aset.
- **Filter Sumber**: pilih satu atau beberapa sumber. Filter tersimpan di alamat
  halaman (URL), jadi tetap berlaku saat halaman dimuat ulang atau tautannya dibagikan.
- **Urutkan**: pilih "Sumber" pada pengurutan (urutan: BOSDA, BOSP, BOSTK, Yayasan,
  Lainnya, Belum diberi keterangan).
- **Export hasil saat ini**: file Excel mengikuti filter yang aktif, termasuk filter
  Sumber. Kolom **U = SUMBER** dan **V = KETERANGAN SUMBER**. Urutan dan arti kolom
  lama (A–T) tidak berubah.
- **Laporan**: tersedia filter Sumber dan tabel **Distribusi Aset per Sumber**.
- **Dashboard**: tabel **Aset per Sumber** menampilkan keenam kategori, termasuk yang
  berjumlah 0.
- Semua angka per Sumber dihitung dengan aturan yang sama seperti total aset: aset di
  Sampah Aset tidak dihitung, aset write-off tetap dihitung, dan admin unit hanya
  melihat aset di unitnya sendiri.

## 5. Import Excel

### Template baru

Template import (unduh di halaman Import Excel) kini memiliki kolom **U — SUMBER**
dengan pilihan dropdown. Daftar nilai yang sah juga tercantum di sheet **REFERENSI**.
Huruf besar/kecil dan spasi berlebih tidak berpengaruh (mis. `bosda`, ` BOSDA `, dan
`Bosda` sama-sama dikenali). "Belum diberi keterangan" boleh ditulis seperti itu.

### File lama tanpa kolom SUMBER

File lama (tanpa judul **SUMBER** di kolom U pada baris judul) **tetap dapat diimpor**.
Sumber setiap baris dicatat **Belum diberi keterangan**, tanpa menambah peringatan.
Di pratinjau, kolom Sumber menampilkan keterangan "File tanpa kolom SUMBER".

### Arti hasil validasi Sumber

| Isi kolom SUMBER | Hasil | Sumber yang disimpan saat dipromosikan |
|---|---|---|
| Nilai yang dikenali | Tidak ada peringatan | Nilai tersebut |
| Kosong | **Warning**, baris tetap bisa dipromosikan | Belum diberi keterangan |
| Nilai tidak dikenali (mis. `Dana Desa`) | **Warning**, nilai asli ditampilkan. **Batch tidak dapat dipromosikan** sampai Anda memilih sumbernya. | Pilihan Anda di pratinjau |

Nilai yang tidak dikenali **tidak pernah** diubah otomatis — tidak menjadi Yayasan,
dan juga tidak menjadi "Belum diberi keterangan" tanpa Anda memilihnya.

### Memperbaiki Sumber di pratinjau (wajib sebelum promosi, tanpa mengulang import)

1. Buka batch di halaman Import Excel. Jika ada nilai Sumber yang tidak dikenali,
   muncul pemberitahuan berisi jumlah barisnya.
2. Klik **Tampilkan baris** (atau pilih filter **Sumber belum dipilih**) untuk
   melihat hanya baris yang perlu dipilih sumbernya. Nilai aslinya tampil dengan
   tanda "(tidak dikenali)".
3. Pilih sumber yang benar pada kolom **Sumber** di setiap baris. Bila memang belum
   diketahui, pilih **Belum diberi keterangan** secara eksplisit. Pada filter
   **Sumber belum dipilih**, Anda juga dapat memilih satu sumber untuk semua baris
   yang tampil di halaman tersebut, lalu klik **Terapkan**. Pilihan langsung
   tersimpan di server.
4. Jika baris itu tidak memiliki peringatan lain, statusnya berubah dari Warning
   menjadi Valid.
5. Klik **Promote**. Selama masih ada nilai yang tidak dikenali dan belum dipilih,
   tombol konfirmasi tidak dapat diklik, dan server juga menolak promosi. Tidak ada
   satu baris pun yang dipromosikan sampai semua nilai tersebut dipilih.

Pilihan Sumber juga dapat diubah untuk baris yang nilainya kosong atau sudah dikenali,
selama baris itu belum dipromosikan.

### Import ulang

Deteksi duplikat tidak berubah. Jika file berisi aset yang sudah ada (kombinasi
Lokasi.Kategori.Subkategori.No. Urut.Tahun sama), baris tersebut ditolak sebagai
duplikat, dan **Sumber aset yang sudah tersimpan tidak berubah**, meskipun kolom
SUMBER di file kosong atau berbeda.

### Keterangan Sumber dan import

Template import tidak memuat Keterangan Sumber. Kolom V (KETERANGAN SUMBER) pada file
hasil export tidak dibaca saat file itu diimpor kembali. Lengkapi Keterangan Sumber
melalui Edit Aset.

## 6. Hak akses

Fitur ini tidak mengubah hak akses. Siapa pun yang boleh menambah atau mengedit aset
boleh mengisi atau mengubah Sumber, sesuai cakupan lokasinya. Admin unit hanya dapat
mengubah, memfilter, dan melihat angka Sumber untuk aset di unitnya sendiri.
Memperbaiki Sumber di pratinjau import mengikuti hak akses Import Excel yang sama.
