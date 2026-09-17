import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import MultiSelectFilter from '../components/MultiSelectFilter';
import { CenteredState } from '../lib/assetFields';
import { api, ApiError } from '../lib/api';
import {
  ARRAY_FILTERS,
  buildReportQuery,
  emptyReportState,
  hasActiveReportFilters,
  parseReportQuery,
} from '../lib/reportQuery';
import { useMasterData } from '../lib/useMasterData';

const CONDITION_OPTIONS = [
  { value: 'baik', label: 'Baik' },
  { value: 'kurang_baik', label: 'Kurang Baik' },
  { value: 'rusak_berat', label: 'Rusak Berat' },
  { value: 'unknown', label: 'Tidak diketahui' },
];

const STATUS_OPTIONS = [
  { value: '0', label: 'Aktif' },
  { value: '1', label: 'Written-off' },
];

const CONDITION_LABELS = {
  baik: 'Baik',
  kurang_baik: 'Kurang Baik',
  rusak_berat: 'Rusak Berat',
  unknown: 'Tidak diketahui',
};

function StatCard({ label, value }) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function CountTable({ title, rows, labelKey, headers, secondaryKey }) {
  return (
    <section className="rounded-xl border border-gray-200 bg-white">
      <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">{title}</h2>
      {rows.length === 0 ? (
        <p className="px-4 py-6 text-sm text-gray-400">Tidak ada data untuk ditampilkan.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2 font-medium">{headers[0]}</th>
                <th className="px-4 py-2 text-right font-medium">{headers[1]}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, i) => (
                <tr key={row.id ?? row.code ?? row.year ?? i} className="border-t border-gray-100">
                  <td className="px-4 py-2">
                    {row[labelKey]}
                    {secondaryKey && row[secondaryKey] && (
                      <span className="text-gray-400"> — {row[secondaryKey]}</span>
                    )}
                  </td>
                  <td className="px-4 py-2 text-right tabular-nums">{row.asset_count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

export default function Reports() {
  const { refreshUser, canViewReports } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const md = useMasterData();
  const { ensureSubcategories, ensureRooms } = md;

  const state = useMemo(() => parseReportQuery(searchParams), [searchParams]);
  const apiQuery = useMemo(() => buildReportQuery(state), [state]);

  const [data, setData] = useState(null);
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  // Year options have no master table — they come from the data itself. Grown
  // (never shrunk) across every response so switching other filters never
  // makes a previously-seen year disappear from the picker.
  const seenYears = useRef(new Set());
  const [yearOptions, setYearOptions] = useState([]);

  const patchState = useCallback(
    (patch) => {
      setSearchParams(buildReportQuery({ ...state, ...patch }), { replace: false });
    },
    [state, setSearchParams],
  );

  const allCategoryCodes = useMemo(() => md.categories.map((c) => c.code), [md.categories]);
  const allLocationCodes = useMemo(() => md.locations.map((l) => l.code), [md.locations]);

  useEffect(() => {
    const codes = state.category_code.length ? state.category_code : allCategoryCodes;
    if (codes.length) ensureSubcategories(codes);
  }, [state.category_code, allCategoryCodes, ensureSubcategories]);

  useEffect(() => {
    const codes = state.location_code.length ? state.location_code : allLocationCodes;
    if (codes.length) ensureRooms(codes);
  }, [state.location_code, allLocationCodes, ensureRooms]);

  const locationOptions = useMemo(
    () => md.locations.map((l) => ({ value: l.code, label: l.name, hint: l.alias })),
    [md.locations],
  );
  const categoryOptions = useMemo(
    () => md.categories.map((c) => ({ value: c.code, label: c.name })),
    [md.categories],
  );
  const subcategoryOptions = useMemo(() => {
    const activeCats = state.category_code.length ? state.category_code : allCategoryCodes;
    const seen = new Set();
    const out = [];
    for (const cat of activeCats) {
      for (const s of md.subcategoriesByCategory[cat] ?? []) {
        const value = `${s.category.code}.${s.code}`;
        if (seen.has(value)) continue;
        seen.add(value);
        out.push({ value, label: `${s.name} — ${s.category.name}`, hint: `Kode ${s.code}` });
      }
    }
    return out.sort((a, b) => a.label.localeCompare(b.label));
  }, [state.category_code, allCategoryCodes, md.subcategoriesByCategory]);
  const roomOptions = useMemo(() => {
    const activeLocs = state.location_code.length ? state.location_code : allLocationCodes;
    const seen = new Set();
    const out = [];
    for (const loc of activeLocs) {
      for (const r of md.roomsByLocation[loc] ?? []) {
        const value = String(r.id);
        if (seen.has(value)) continue;
        seen.add(value);
        out.push({ value, label: r.name, hint: r.location?.name });
      }
    }
    return out.sort((a, b) => a.label.localeCompare(b.label));
  }, [state.location_code, allLocationCodes, md.roomsByLocation]);

  /* ---------------------------------------------------------------- dependency pruning */
  useEffect(() => {
    if (state.category_code.length === 0 || state.subcategory_code.length === 0) return;
    const allowed = new Set(state.category_code);
    const kept = state.subcategory_code.filter((v) => allowed.has(v.split('.')[0]));
    if (kept.length !== state.subcategory_code.length) patchState({ subcategory_code: kept });
  }, [state.category_code, state.subcategory_code, patchState]);

  useEffect(() => {
    if (state.location_code.length === 0 || state.room_id.length === 0) return;
    const allowed = new Set(state.location_code);
    const locOf = {};
    for (const [loc, rooms] of Object.entries(md.roomsByLocation)) {
      for (const r of rooms) locOf[String(r.id)] = loc;
    }
    const kept = state.room_id.filter((id) => !(id in locOf) || allowed.has(locOf[id]));
    if (kept.length !== state.room_id.length) patchState({ room_id: kept });
  }, [state.location_code, state.room_id, md.roomsByLocation, patchState]);

  /* ---------------------------------------------------------------- fetch report */
  useEffect(() => {
    if (!canViewReports) return undefined;
    let alive = true;
    setPhase((p) => (data === null ? 'loading' : p));

    api
      .get(`/api/reports/inventory?${apiQuery}`)
      .then((res) => {
        if (!alive) return;
        const payload = res?.data ?? null;
        setData(payload);
        setPhase('ready');
        setError('');
        for (const row of payload?.by_year ?? []) seenYears.current.add(row.year);
        setYearOptions(
          [...seenYears.current]
            .sort((a, b) => b - a)
            .map((y) => ({ value: String(y), label: String(y) })),
        );
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

  /* ---------------------------------------------------------------- chips */
  const chipLabel = useCallback(
    (filter, value) => {
      switch (filter) {
        case 'location_code':
          return locationOptions.find((o) => o.value === value)?.label ?? value;
        case 'category_code':
          return categoryOptions.find((o) => o.value === value)?.label ?? value;
        case 'subcategory_code':
          return subcategoryOptions.find((o) => o.value === value)?.label ?? value;
        case 'room_id':
          return roomOptions.find((o) => o.value === value)?.label ?? `Ruangan #${value}`;
        case 'condition':
          return CONDITION_OPTIONS.find((o) => o.value === value)?.label ?? value;
        case 'is_written_off':
          return STATUS_OPTIONS.find((o) => o.value === value)?.label ?? value;
        case 'asset_year':
          return value;
        default:
          return value;
      }
    },
    [locationOptions, categoryOptions, subcategoryOptions, roomOptions],
  );

  const chips = useMemo(() => {
    const out = [];
    for (const filter of ARRAY_FILTERS) {
      for (const value of state[filter]) out.push({ filter, value, label: chipLabel(filter, value) });
    }
    return out;
  }, [state, chipLabel]);

  const removeChip = (filter, value) => patchState({ [filter]: state[filter].filter((v) => v !== value) });
  const resetAll = () => setSearchParams(buildReportQuery(emptyReportState()), { replace: false });
  const filterActive = hasActiveReportFilters(state);

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
  const byCondition = data?.by_condition ?? {};
  const byLocation = data?.by_location ?? [];
  const byCategory = data?.by_category ?? [];
  const byRoom = (data?.by_room ?? []).map((r) => ({ ...r, location_label: r.location_name }));
  const byYear = (data?.by_year ?? []).map((r) => ({ ...r, id: r.year }));

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Laporan Inventaris</h1>
        <p className="mt-1 text-sm text-gray-500">
          Rekap dan gambaran umum kondisi inventaris Yayasan Vidatra.
        </p>
      </header>

      {/* filters */}
      <div className="space-y-3">
        <div className="flex flex-wrap gap-2">
          <MultiSelectFilter
            label="Lokasi"
            options={locationOptions}
            selected={state.location_code}
            onChange={(v) => patchState({ location_code: v })}
            loading={md.locations.length === 0 && !md.ready}
          />
          <MultiSelectFilter
            label="Kategori"
            options={categoryOptions}
            selected={state.category_code}
            onChange={(v) => patchState({ category_code: v })}
            loading={md.categories.length === 0 && !md.ready}
          />
          <MultiSelectFilter
            label="Subkategori"
            options={subcategoryOptions}
            selected={state.subcategory_code}
            onChange={(v) => patchState({ subcategory_code: v })}
            searchable
            emptyText="Pilih kategori dahulu atau tunggu data dimuat"
          />
          <MultiSelectFilter
            label="Ruangan"
            options={roomOptions}
            selected={state.room_id}
            onChange={(v) => patchState({ room_id: v })}
            searchable
          />
          <MultiSelectFilter
            label="Kondisi"
            options={CONDITION_OPTIONS}
            selected={state.condition}
            onChange={(v) => patchState({ condition: v })}
            searchable={false}
          />
          <MultiSelectFilter
            label="Status"
            options={STATUS_OPTIONS}
            selected={state.is_written_off}
            onChange={(v) => patchState({ is_written_off: v })}
            searchable={false}
          />
          <MultiSelectFilter
            label="Tahun"
            options={yearOptions}
            selected={state.asset_year}
            onChange={(v) => patchState({ asset_year: v })}
            searchable
            emptyText="Belum ada data tahun"
          />
        </div>

        {chips.length > 0 && (
          <div className="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2.5">
            <span className="text-xs font-medium uppercase tracking-wide text-gray-400">Filter aktif</span>
            {chips.map((chip) => (
              <button
                key={`${chip.filter}:${chip.value}`}
                type="button"
                onClick={() => removeChip(chip.filter, chip.value)}
                className="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white py-1 pl-2.5 pr-1.5 text-xs text-gray-700 hover:border-gray-300"
              >
                {chip.label}
                <span aria-hidden="true" className="text-gray-400">✕</span>
                <span className="sr-only">Hapus filter</span>
              </button>
            ))}
            <button
              type="button"
              onClick={resetAll}
              className="ml-auto rounded-md px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200"
            >
              Reset semua
            </button>
          </div>
        )}
      </div>

      {/* body */}
      {phase === 'loading' && data === null ? (
        <p className="text-sm text-gray-500">Memuat laporan…</p>
      ) : phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat laporan inventaris.'}</p>
          <button
            type="button"
            onClick={() => setRetryKey((k) => k + 1)}
            className="mt-3 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            Coba lagi
          </button>
        </div>
      ) : (
        <>
          {summary.total_assets === 0 && (
            <div className="rounded-lg border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600">
              Tidak ada aset yang sesuai dengan filter saat ini.
              {filterActive && (
                <button type="button" onClick={resetAll} className="ml-2 font-medium underline">
                  Reset semua filter
                </button>
              )}
            </div>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Total Aset Aktif" value={summary.active_assets} />
            <StatCard label="Written-off" value={summary.written_off_assets} />
            <StatCard label="Lokasi" value={byLocation.length} />
            <StatCard label="Kategori" value={byCategory.length} />
          </div>

          <section className="rounded-xl border border-gray-200 bg-white">
            <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">
              Kondisi Aset
            </h2>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody>
                  {['baik', 'kurang_baik', 'rusak_berat', 'unknown'].map((key) => (
                    <tr key={key} className="border-t border-gray-100 first:border-t-0">
                      <td className="px-4 py-2">{CONDITION_LABELS[key]}</td>
                      <td className="px-4 py-2 text-right tabular-nums">{byCondition[key] ?? 0}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <CountTable
              title="Distribusi Aset per Lokasi"
              rows={byLocation}
              labelKey="name"
              headers={['Lokasi', 'Jumlah']}
            />
            <CountTable
              title="Distribusi Aset per Kategori"
              rows={byCategory}
              labelKey="name"
              headers={['Kategori', 'Jumlah']}
            />
          </div>

          <CountTable
            title="Aset per Ruangan"
            rows={byRoom}
            labelKey="name"
            secondaryKey="location_label"
            headers={['Ruangan', 'Jumlah']}
          />

          <CountTable
            title="Aset per Tahun Perolehan"
            rows={byYear}
            labelKey="year"
            headers={['Tahun', 'Jumlah']}
          />
        </>
      )}
    </div>
  );
}
