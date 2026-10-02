/**
 * Tahap 6.9 R9.4-17 — Indonesian presentation of import row messages.
 *
 * The importer stores each row's `validation_messages` as
 * `{ code, severity, field, message }`, with `message` a technical English
 * string written at staging time (App\Import\Validation\RowValidator,
 * App\Import\Parsing\RowParser, App\Import\Promotion\AssetPromoter). Those
 * strings stay as they are — they're persisted, logged and asserted by tests —
 * so translation happens HERE, at display time, keyed by the stable `code`.
 * That also covers rows staged long before this change.
 *
 * Values the English message embeds in `[...]` (room, location, year, …) are
 * reused verbatim. An unknown code, or one whose message no longer has the
 * expected shape, falls back to the original text rather than guessing.
 * Messages that are already Indonesian (e.g. `location_out_of_scope`) need no
 * entry. Only wording changes — not a status, severity or code
 * (the R9.4-01 `room_unmapped` semantics are deliberately untouched).
 */

/** Every `[...]` value of a message, in order. */
function bracketValues(message) {
  return [...String(message ?? '').matchAll(/\[([^\]]*)\]/g)].map((m) => m[1]);
}

/** The text after the first ': ' (the list part of "... value(s): a, b"). */
function afterColon(message) {
  const s = String(message ?? '');
  const i = s.indexOf(': ');
  return i >= 0 ? s.slice(i + 2) : '';
}

const ROW_MESSAGES = {
  // --- RowValidator
  location_missing: () => 'Kode lokasi (kolom B) kosong.',
  invalid_location: (msg, [loc]) => loc !== undefined && `Lokasi "${loc}" tidak terdaftar di master lokasi.`,
  category_missing: () => 'Kode kategori (kolom C) kosong.',
  invalid_category: (msg, [cat]) => cat !== undefined && `Kategori "${cat}" tidak terdaftar di master kategori.`,
  category_mismatch: (msg, [row, batch]) =>
    batch !== undefined && `Kategori baris (${row}) berbeda dengan kategori batch (${batch}).`,
  subcategory_missing: () => 'Kode subkategori (kolom D / header blok) kosong.',
  invalid_subcategory: (msg) => {
    const m = String(msg).match(/\(([^,]+), ([^)]+)\)/);
    return m && `Subkategori ${m[2]} untuk kategori ${m[1]} tidak terdaftar di master subkategori.`;
  },
  missing_sequence: () => 'Nomor urut (kolom E) kosong.',
  sequence_too_long: (msg, [seq]) => seq !== undefined && `Nomor urut "${seq}" melebihi 10 karakter.`,
  invalid_year: (msg, [year]) =>
    year !== undefined ? `Tahun aset ${year} di luar rentang 1980–2100.` : 'Tahun aset tidak dapat ditentukan.',
  quantity_not_one: (msg, [qty]) =>
    qty !== undefined && `Jumlah ${qty} tidak didukung — satu baris harus mewakili tepat 1 unit aset.`,
  room_missing: () => 'Nilai ruangan (kolom O) kosong.',
  room_unmapped: (msg, [room, loc]) =>
    loc !== undefined && `Ruangan "${room}" belum dikenali untuk lokasi ${loc}.`,
  duplicate_in_batch: (msg, [identity]) => {
    const row = String(msg).match(/row (\d+)/);
    return identity !== undefined && row && `Identitas aset ${identity} sama dengan baris ${row[1]} dalam batch ini.`;
  },
  duplicate_existing_asset: (msg, [identity]) => {
    if (identity === undefined) {
      // ImportManager's redacted variant for a scoped actor (no identity / id shown)
      return 'Identitas aset ini sudah terdaftar sebagai aset lain (detail tidak ditampilkan karena berada di luar unit Anda).';
    }
    const id = String(msg).match(/asset id (\d+)/);
    return id && `Identitas aset ${identity} sudah terdaftar sebagai aset lain (ID ${id[1]}).`;
  },

  // --- RowParser
  subcategory_from_block_header: (msg, [code]) =>
    code !== undefined && `Kolom D kosong; subkategori diambil dari header blok (${code}).`,
  subcategory_block_mismatch: (msg, [row, block]) =>
    block !== undefined && `Subkategori baris (${row}) berbeda dengan header blok (${block}).`,
  written_off_date_unparseable: (msg, [raw]) => raw !== undefined && `Tanggal write-off tidak dikenali: "${raw}".`,
  purchase_date_unparseable: (msg, [raw]) => raw !== undefined && `Tanggal pembelian tidak dikenali: "${raw}".`,
  quantity_non_numeric: (msg, [raw]) => raw !== undefined && `Jumlah bukan angka ("${raw}") — dianggap 1.`,
  asset_year_suspicious: (msg, [raw]) => raw !== undefined && `Tahun bukan 4 digit: ${raw}.`,
  asset_year_unparseable: (msg, [raw]) => raw !== undefined && `Tahun bukan angka: "${raw}".`,
  asset_year_missing: () => 'Tahun tidak diisi di kolom F atau J.',
  condition_raw_unrecognized: (msg) => afterColon(msg) && `Nilai Keadaan Barang tidak dikenali: ${afterColon(msg)}.`,
  condition_missing: () => 'Tanda Keadaan Barang (B/KB/RB) kosong semua.',
  condition_ambiguous: (msg) => afterColon(msg) && `Lebih dari satu tanda Keadaan Barang: ${afterColon(msg)}.`,

  // --- AssetPromoter (per-row promotion failure, "promotion failed: <detail>")
  promotion_failed: (msg) => `Gagal dipromosikan: ${promotionErrorText(String(msg).replace(/^promotion failed:\s*/, ''))}`,
};

/** Indonesian text for one stored row message `{ code, message }`; falls back to the original message. */
export function importRowMessageText(entry) {
  const format = ROW_MESSAGES[entry?.code];
  if (!format) return entry?.message ?? '';
  const text = format(entry.message, bracketValues(entry.message));

  return text || entry.message;
}

const PROMOTION_ERRORS = [
  [/^identity already exists as asset id (\d+)$/, (m) => `identitas aset sudah terdaftar sebagai aset ID ${m[1]}.`],
  [
    /^matched room id \d+ is no longer active/,
    () => 'ruangan yang dipetakan sudah tidak aktif — aktifkan kembali ruangan tersebut, lalu promosikan lagi.',
  ],
  [/^import_row was promoted concurrently$/, () => 'baris ini sedang dipromosikan oleh proses lain. Muat ulang halaman.'],
  [/^raw_payload has no "parsed" section$/, () => 'data staging baris ini tidak lengkap.'],
  [/SQLSTATE/, () => 'data baris ini melanggar aturan penyimpanan aset.'],
];

/**
 * Indonesian text for one promotion failure detail — the `errors[].message` of
 * `POST /imports/{batch}/promote` (an exception message from AssetPromoter).
 * A database error is summarised rather than shown raw. Unknown details pass through.
 */
export function promotionErrorText(detail) {
  const s = String(detail ?? '');
  for (const [pattern, format] of PROMOTION_ERRORS) {
    const m = s.match(pattern);
    if (m) return format(m);
  }

  return s;
}
