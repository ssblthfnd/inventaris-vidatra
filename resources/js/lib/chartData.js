/**
 * Shared data shaping for the Dashboard and Laporan charts (docs/mockups/README.md).
 * Colours are the theme tokens from resources/css/app.css, so every chart follows
 * light/dark mode without extra code.
 */

import { ASSET_SOURCES } from './assetSources';

/** Sumber colours — fixed order and colour per category (colour-blind checked). */
const SOURCE_COLORS = {
  bosda: 'var(--color-src-bosda)',
  bosp: 'var(--color-src-bosp)',
  bostk: 'var(--color-src-bostk)',
  yayasan: 'var(--color-src-yayasan)',
  lainnya: 'var(--color-src-lainnya)',
  belum_diberi_keterangan: 'var(--color-src-belum)',
};

export const CONDITIONS = [
  { value: 'baik', label: 'Baik', color: 'var(--color-st-baik)' },
  { value: 'kurang_baik', label: 'Kurang Baik', color: 'var(--color-st-kurang)' },
  { value: 'rusak_berat', label: 'Rusak Berat', color: 'var(--color-st-rusak)' },
  { value: 'unknown', label: 'Tidak diketahui', color: 'var(--color-st-unknown)' },
];

/** Every source in the fixed order, zero ones included, from the API's `by_source` rows. */
export function sourceItems(bySource = []) {
  const counts = Object.fromEntries(bySource.map((r) => [r.value, r.asset_count]));
  return ASSET_SOURCES.map((s) => ({ label: s.label, value: counts[s.value] ?? 0, color: SOURCE_COLORS[s.value] }));
}

/** Every condition bucket, zero ones included, from a `{ baik, kurang_baik, rusak_berat, unknown }` map. */
export function conditionItems(byCondition = {}) {
  return CONDITIONS.map((c) => ({ label: c.label, value: byCondition[c.value] ?? 0, color: c.color }));
}

/** Location/category rows as bar rows; an inactive one is listed only while it holds assets (D15-5). */
export function masterBars(rows = []) {
  return rows.map((r) => ({
    key: r.code,
    label: r.is_active === false ? `${r.name} (nonaktif)` : r.name,
    value: r.asset_count,
  }));
}

/** "Terdaftar" KPI: active master rows only (D15-4). */
export const countActive = (rows = []) => rows.filter((r) => r.is_active !== false).length;

const numberFormat = new Intl.NumberFormat('id-ID');
const percentFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });

export const formatNumber = (value) => numberFormat.format(value ?? 0);

export const formatPercent = (value, total) => `${total ? percentFormat.format((value / total) * 100) : '0'}%`;
