/**
 * Sumber Aset (Tahap 2) — the six fixed asset-source categories, mirroring the
 * backend `App\Enums\AssetSource` exactly (same values, same order = the
 * database ENUM order). The stored value is the source of truth; labels are
 * display only.
 *
 * `belum_diberi_keterangan` is a real, selectable category ("the source is not
 * known yet") — never an error, never a synonym of Yayasan. Nothing in the UI
 * pre-selects a category for a new asset.
 *
 * Not to be confused with the free-text `funding_source` column, labelled
 * "Keterangan Sumber" in the UI.
 */

export const ASSET_SOURCES = [
  { value: 'bosda', label: 'BOSDA' },
  { value: 'bosp', label: 'BOSP' },
  { value: 'bostk', label: 'BOSTK' },
  { value: 'yayasan', label: 'Yayasan' },
  { value: 'lainnya', label: 'Lainnya' },
  { value: 'belum_diberi_keterangan', label: 'Belum diberi keterangan' },
];

export const UNSPECIFIED_SOURCE = 'belum_diberi_keterangan';

const LABELS = Object.fromEntries(ASSET_SOURCES.map((s) => [s.value, s.label]));

/** Label for a stored value; unknown/empty values fall back to the raw value / em dash. */
export function assetSourceLabel(value) {
  if (!value) return '—';
  return LABELS[value] ?? value;
}

/** Helper text shown under the free-text "Keterangan Sumber" input everywhere. */
export const FUNDING_NOTE_HINT =
  'Keterangan bebas (opsional), mis. nama program atau donatur — bukan kategori sumber aset.';

/** Compact badge used in the Inventaris table and the detail page. */
export function AssetSourceBadge({ value, className = '' }) {
  const unspecified = value === UNSPECIFIED_SOURCE || !value;
  return (
    <span
      className={[
        'inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
        unspecified
          ? 'bg-amber-50 text-amber-800 ring-amber-200'
          : 'bg-gray-50 text-gray-700 ring-gray-200',
        className,
      ].join(' ')}
    >
      {assetSourceLabel(value)}
    </span>
  );
}
