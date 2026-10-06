import { MAX_YEAR } from './assetFields';

/**
 * Full inventory number = the asset code the system prints on labels
 * (Tahap 6.9 R10): `lokasi.kategori.subkategori.nomor_urut.tahun`,
 * e.g. `02.01.003.005.2026`.
 *
 * Segment widths are the master-data columns: location 2, category 2,
 * subcategory 3 (`CHAR(2)/CHAR(2)/CHAR(3)`), sequence 1–10 (the `sequence_no`
 * column; a suffix such as `005A` is valid, as in the importer), year 4 digits.
 * Only `.` separates segments. Nothing is normalised: another separator, a
 * missing/extra segment, whitespace or a wrong width is rejected, never guessed
 * (a sequence containing `.` can therefore never be entered this way — the code
 * would be ambiguous).
 *
 * The parsed parts are what the form sends; `asset_code` itself is never sent.
 */

export const INVENTORY_CODE_EXAMPLE = '02.01.003.005.2026';

const PATTERN = /^([^.\s]{2})\.([^.\s]{2})\.([^.\s]{3})\.([^.\s]{1,10})\.(\d{4})$/;

const FORMAT_MESSAGE = `Format nomor inventaris: lokasi.kategori.subkategori.nomor urut.tahun, mis. ${INVENTORY_CODE_EXAMPLE}.`;

/**
 * @returns {{ ok: true, parts: { location_code: string, category_code: string, subcategory_code: string, sequence_no: string, asset_year: string } }
 *          | { ok: false, error: string }}
 */
export function parseInventoryCode(input) {
  const value = String(input ?? '');
  const match = PATTERN.exec(value);
  if (!match) {
    if (/\s/.test(value.trim()) || value !== value.trim()) {
      return { ok: false, error: 'Nomor inventaris tidak boleh mengandung spasi. ' + FORMAT_MESSAGE };
    }
    if (!value.includes('.') && /[-/]/.test(value)) {
      return { ok: false, error: 'Gunakan titik (.) sebagai pemisah. ' + FORMAT_MESSAGE };
    }
    return { ok: false, error: FORMAT_MESSAGE };
  }

  const [, location, category, subcategory, sequence, year] = match;
  const yearNumber = Number(year);
  if (yearNumber < 1980 || yearNumber > MAX_YEAR) {
    return { ok: false, error: `Tahun pada nomor inventaris harus antara 1980 dan ${MAX_YEAR}.` };
  }

  return {
    ok: true,
    parts: {
      location_code: location,
      category_code: category,
      subcategory_code: subcategory,
      sequence_no: sequence,
      asset_year: year,
    },
  };
}
