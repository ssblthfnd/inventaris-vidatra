import { useCallback, useEffect, useId, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import BarListCard from '../components/charts/BarListCard';
import KpiRow from '../components/charts/KpiRow';
import RoomCountTable from '../components/charts/RoomCountTable';
import StackedBarCard from '../components/charts/StackedBarCard';
import YearColumnsCard from '../components/charts/YearColumnsCard';
import { CenteredState } from '../lib/assetFields';
import { api, ApiError } from '../lib/api';
import { ASSET_SOURCES } from '../lib/assetSources';
import { CONDITIONS, conditionItems, countActive, masterBars, sourceItems } from '../lib/chartData';
import { buildReportQuery, emptyReportState, hasActiveReportFilters, parseReportQuery } from '../lib/reportQuery';
import { downloadReportPdf } from '../lib/reportPdf';
import { useMasterData } from '../lib/useMasterData';

const STATUS_OPTIONS = [
  { value: '0', label: 'Aktif' },
  { value: '1', label: 'Ditulis off' },
];

/** The six single-choice filters of the design, in display order. */
const FILTERS = [
  { key: 'location_code', label: 'Lokasi', all: 'Semua lokasi' },
  { key: 'category_code', label: 'Kategori', all: 'Semua kategori' },
  { key: 'condition', label: 'Kondisi', all: 'Semua kondisi' },
  { key: 'is_written_off', label: 'Status', all: 'Semua status' },
  { key: 'asset_year', label: 'Tahun', all: 'Semua tahun' },
  { key: 'asset_source', label: 'Sumber', all: 'Semua sumber' },
];

/**
 * Filters the API still accepts (and older links may carry) but the page no longer
 * offers as a control: shown as chips only, cleared by their chip or "Reset semua".
 */
const LEGACY_FILTERS = [
  { key: 'subcategory_code', label: 'Subkategori' },
  { key: 'room_id', label: 'Ruangan' },
];

/** Select value standing for "several values" (from an older link); never sent anywhere. */
const MULTIPLE = '__multiple__';

const controlClass =
  'h-11 w-full rounded-[10px] border border-field-border bg-field px-2.5 text-sm text-ink focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none';
const outlineButton =
  'min-h-11 cursor-pointer rounded-[10px] border border-field-border px-4 text-sm font-semibold text-ink hover:bg-gray-50 disabled:cursor-default disabled:opacity-45';

function DownloadIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 3v12M7 10l5 5 5-5M4 21h16" />
    </svg>
  );
}

/**
 * Laporan Inventaris (design: docs/mockups/laporan.html). The filters live in the
 * URL (lib/reportQuery.js, `key[]=value`), so the page, a shared link and "Unduh
 * PDF" all use the same query string against `GET /api/reports/inventory[/pdf]`.
 * Each control picks one value; an older link with several values for a filter (or
 * with Subkategori/Ruangan) still works — every value shows as a chip and in the PDF.
 */
export default function Reports() {
  const { refreshUser, canViewReports, isUnitAdmin, locationCode } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const md = useMasterData();
  const { ensureSubcategories, ensureRooms } = md;
  const id = useId();

  const state = useMemo(() => parseReportQuery(searchParams), [searchParams]);
  const apiQuery = useMemo(() => buildReportQuery(state), [state]);

  const [data, setData] = useState(null);
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);
  const [pdfBusy, setPdfBusy] = useState(false);
  const [pdfError, setPdfError] = useState('');

  const patchState = useCallback(
    (patch) => setSearchParams(buildReportQuery({ ...state, ...patch }), { replace: false }),
    [state, setSearchParams],
  );
  const resetAll = () => setSearchParams(buildReportQuery(emptyReportState()), { replace: false });

  // names for the legacy chips: only fetched when such a filter is actually present
  useEffect(() => {
    // only category-qualified values ("02.001") say which category's list to load
    const categories = state.subcategory_code.filter((v) => v.includes('.')).map((v) => v.split('.')[0]);
    if (categories.length) ensureSubcategories([...new Set(categories)]);
  }, [state.subcategory_code, ensureSubcategories]);
  useEffect(() => {
    if (state.room_id.length && md.locations.length) ensureRooms(md.locations.map((l) => l.code));
  }, [state.room_id, md.locations, ensureRooms]);

  /* ---------------------------------------------------------------- fetch report */
  useEffect(() => {
    if (!canViewReports) return undefined;
    let alive = true;
    setPhase((p) => (data === null ? 'loading' : p));

    api
      .get(`/api/reports/inventory?${apiQuery}`)
      .then((res) => {
        if (!alive) return;
        setData(res?.data ?? null);
        setPhase('ready');
        setError('');
      })
      .catch((e) => {
        if (!alive) return;
        if (e instanceof ApiError && (e.status === 401 || e.status === 403)) {
          refreshUser();
          return;
        }
        setError(e instanceof ApiError ? e.message : 'Gagal memuat laporan inventaris.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiQuery, retryKey, canViewReports]);

  /* ---------------------------------------------------------------- options & labels */
  const options = useMemo(
    () => ({
      // a unit_admin only has data in their own location, so that is the only useful choice
      location_code: md.locations
        .filter((l) => !isUnitAdmin || l.code === locationCode)
        .map((l) => ({ value: l.code, label: l.name })),
      category_code: md.categories.map((c) => ({ value: c.code, label: c.name })),
      condition: CONDITIONS.map((c) => ({ value: c.value, label: c.label })),
      is_written_off: STATUS_OPTIONS,
      asset_year: (data?.available_years ?? []).map((y) => ({ value: String(y), label: String(y) })),
      asset_source: ASSET_SOURCES,
    }),
    [md.locations, md.categories, isUnitAdmin, locationCode, data?.available_years],
  );

  const valueLabel = useCallback(
    (key, value) => {
      if (key === 'subcategory_code') {
        const [cat, code] = value.split('.');
        const sub = code && (md.subcategoriesByCategory[cat] ?? []).find((s) => s.code === code);
        return sub ? `${sub.name} — ${sub.category?.name ?? cat}` : value;
      }
      if (key === 'room_id') {
        if (value === 'none') return 'Tanpa Ruangan';
        const room = Object.values(md.roomsByLocation).flat().find((r) => String(r.id) === value);
        return room ? `${room.name} — ${room.location?.name ?? ''}` : `Ruangan #${value}`;
      }
      return options[key]?.find((o) => o.value === value)?.label ?? value;
    },
    [options, md.subcategoriesByCategory, md.roomsByLocation],
  );

  const chips = useMemo(
    () =>
      [...FILTERS, ...LEGACY_FILTERS].flatMap(({ key, label }) =>
        state[key].map((value) => ({ key, value, text: `${label}: ${valueLabel(key, value)}` })),
      ),
    [state, valueLabel],
  );

  const handleDownload = async () => {
    setPdfBusy(true);
    setPdfError('');
    try {
      await downloadReportPdf(apiQuery);
    } catch (e) {
      setPdfError(e instanceof ApiError ? e.message : 'Gagal membuat PDF laporan. Coba lagi.');
    } finally {
      setPdfBusy(false);
    }
  };

  /* ---------------------------------------------------------------- access gate */
  if (!canViewReports) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk mengakses Laporan Inventaris."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }

  const summary = data?.summary ?? { total_assets: 0, active_assets: 0, written_off_assets: 0 };
  const byLocation = data?.by_location ?? [];
  const byCategory = data?.by_category ?? [];

  return (
    <div className="flex flex-col gap-5 text-ink">
      <header className="flex flex-col gap-1.5">
        <h1 className="text-[22px] font-bold tracking-tight">Laporan Inventaris</h1>
        <p className="text-sm text-muted">Rekap dan gambaran umum kondisi inventaris Yayasan Vidatra.</p>
      </header>

      {/* filters */}
      <section className="flex flex-col gap-3.5 rounded-[14px] border border-border bg-surface px-5 py-4">
        <div className="flex flex-wrap items-end gap-3">
          {FILTERS.map(({ key, label, all }) => {
            const selected = state[key];
            const known = options[key];
            // a single value the list does not offer (e.g. an inactive location) still shows
            const extra = selected.length === 1 && !known.some((o) => o.value === selected[0]) ? selected[0] : null;

            return (
              <label
                key={key}
                htmlFor={`${id}-${key}`}
                className="flex min-w-0 flex-[1_1_150px] flex-col gap-1.5 text-[13px] text-muted max-[480px]:basis-full"
              >
                <span>{label}</span>
                <select
                  id={`${id}-${key}`}
                  value={selected.length > 1 ? MULTIPLE : (selected[0] ?? '')}
                  onChange={(e) => patchState({ [key]: e.target.value === '' ? [] : [e.target.value] })}
                  className={controlClass}
                >
                  <option value="">{all}</option>
                  {selected.length > 1 && (
                    <option value={MULTIPLE} disabled>
                      {selected.length} dipilih (lihat Filter aktif)
                    </option>
                  )}
                  {extra && <option value={extra}>{valueLabel(key, extra)}</option>}
                  {known.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              </label>
            );
          })}
          <button type="button" onClick={resetAll} disabled={!hasActiveReportFilters(state)} className={`${outlineButton} max-[480px]:basis-full`}>
            Reset semua
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <div className="flex min-w-0 flex-[1_1_260px] flex-wrap items-center gap-2 text-[13px]">
            <span className="text-muted">Filter aktif:</span>
            {chips.length === 0 ? (
              <span className="text-muted">Semua data</span>
            ) : (
              chips.map((chip) => (
                <button
                  key={`${chip.key}:${chip.value}`}
                  type="button"
                  onClick={() => patchState({ [chip.key]: state[chip.key].filter((v) => v !== chip.value) })}
                  className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-chip py-[5px] pr-2 pl-2.5 font-semibold text-chip-fg hover:opacity-85"
                >
                  {chip.text}
                  <span aria-hidden="true">✕</span>
                  <span className="sr-only">(hapus filter ini)</span>
                </button>
              ))
            )}
          </div>
          <button
            type="button"
            onClick={handleDownload}
            disabled={pdfBusy || phase !== 'ready'}
            className="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] bg-accent px-4 text-sm font-bold text-accent-fg hover:bg-accent-hover disabled:cursor-default disabled:opacity-60 max-[480px]:w-full max-[480px]:justify-center"
          >
            <DownloadIcon />
            {pdfBusy ? 'Menyiapkan PDF…' : 'Unduh PDF'}
          </button>
        </div>
        {pdfError && (
          <p role="alert" className="text-sm text-red-700">
            {pdfError}
          </p>
        )}
      </section>

      {/* body */}
      {phase === 'loading' && data === null ? (
        <p className="text-sm text-muted">Memuat laporan…</p>
      ) : phase === 'error' ? (
        <div className="rounded-[14px] border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat laporan inventaris.'}</p>
          <button
            type="button"
            onClick={() => setRetryKey((k) => k + 1)}
            className="mt-3 rounded-md bg-danger px-3 py-1.5 text-sm font-medium text-white hover:bg-danger-hover"
          >
            Coba lagi
          </button>
        </div>
      ) : (
        <>
          {summary.total_assets === 0 && (
            <div className="rounded-[10px] border border-border bg-surface px-3.5 py-2.5 text-sm text-muted">
              Tidak ada aset yang sesuai dengan filter saat ini.
              {hasActiveReportFilters(state) && (
                <button type="button" onClick={resetAll} className="ml-2 cursor-pointer font-semibold text-ink underline">
                  Reset semua filter
                </button>
              )}
            </div>
          )}

          {/* R9.4-15 (D15-1) — both populations explicit: the charts below add up to
              "Total Aset" (written-off included), not to "Aset Aktif". */}
          <div className="flex flex-col gap-2">
            <KpiRow
              items={[
                { label: 'Total Aset', value: summary.total_assets },
                { label: 'Aset Aktif', value: summary.active_assets },
                { label: 'Aset Ditulis Off', value: summary.written_off_assets },
                { label: 'Lokasi Terdaftar', value: countActive(byLocation) },
                { label: 'Kategori Terdaftar', value: countActive(byCategory) },
              ]}
            />
            <p className="text-xs text-muted">
              Grafik di bawah menghitung Total Aset (termasuk aset yang ditulis off). Pilih Status Aktif untuk melihat
              aset aktif saja.
            </p>
          </div>

          <div className="flex flex-wrap gap-4">
            <StackedBarCard title="Aset per Sumber" items={sourceItems(data?.by_source)} className="flex-[1_1_230px]" />
            <StackedBarCard title="Kondisi Aset" items={conditionItems(data?.by_condition)} className="flex-[1_1_230px]" />
            <BarListCard title="Aset per Lokasi" rows={masterBars(byLocation)} className="flex-[1_1_230px]" />
          </div>

          <div className="flex flex-wrap gap-4">
            <BarListCard title="Aset per Kategori" rows={masterBars(byCategory)} className="flex-[1_1_320px]" />
            <YearColumnsCard title="Aset per Tahun Perolehan" rows={data?.by_year ?? []} className="flex-[1_1_320px]" />
          </div>

          {/* every matching room — no search or paging here (README: "tampilkan semua baris") */}
          <section className="min-w-0 overflow-hidden rounded-[14px] border border-border bg-surface">
            <h2 className="px-5 py-4 text-[15px] font-bold">Aset per Ruangan</h2>
            <RoomCountTable rows={data?.by_room ?? []} emptyText="Tidak ada aset yang sesuai dengan filter." />
          </section>
        </>
      )}
    </div>
  );
}
