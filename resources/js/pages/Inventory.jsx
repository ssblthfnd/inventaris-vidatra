import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import MultiSelectFilter from '../components/MultiSelectFilter';
import PrintLabelMenu from '../components/PrintLabelMenu';
import BatchDeleteDialog from '../components/inventory/BatchDeleteDialog';
import BatchEditModal from '../components/inventory/BatchEditModal';
import ExportMenu from '../components/inventory/ExportMenu';
import InventoryTable from '../components/inventory/InventoryTable';
import { api, ApiError } from '../lib/api';
import { downloadAssetExport } from '../lib/exports';
import { printBatchLabels } from '../lib/labels';
import {
  buildQuery,
  emptyState,
  hasActiveFilters,
  parseQuery,
  PER_PAGE_OPTIONS,
  sortValue,
  SORTS,
} from '../lib/inventoryQuery';
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

const SEARCH_DEBOUNCE_MS = 350;

function PageButton({ children, active, disabled, onClick, ariaLabel }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={ariaLabel}
      aria-current={active ? 'page' : undefined}
      className={[
        'min-w-9 rounded-md border px-3 py-1.5 text-sm transition-colors',
        active
          ? 'border-gray-900 bg-gray-900 text-white'
          : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-gray-300',
      ].join(' ')}
    >
      {children}
    </button>
  );
}

function pageWindow(current, last) {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  const pages = new Set([1, last, current, current - 1, current + 1]);
  const sorted = [...pages].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out = [];
  let prev = 0;
  for (const p of sorted) {
    if (p - prev > 1) out.push(`gap-${p}`);
    out.push(p);
    prev = p;
  }
  return out;
}

export default function Inventory() {
  const { refreshUser, canWriteInventory, canExportAssets, canPrintLabels } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const md = useMasterData();

  const { ensureSubcategories, ensureRooms } = md;

  const state = useMemo(() => parseQuery(searchParams), [searchParams]);
  const apiQuery = useMemo(() => buildQuery(state), [state]);

  const [result, setResult] = useState(null);
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  /* ---------------------------------------------------------------- batch selection */
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [showBatchModal, setShowBatchModal] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success'); // 'success' | 'error'
  const [printingLabel, setPrintingLabel] = useState(false);
  const [exportingExcel, setExportingExcel] = useState(false);

  // Selection only ever covers the current result set. Any change to the query
  // (search/filter/sort/page) invalidates it rather than silently keeping stale ids
  // for rows that may no longer be visible.
  useEffect(() => {
    setSelectedIds(new Set());
  }, [apiQuery]);

  const patchState = useCallback(
    (patch, { resetPage = true } = {}) => {
      const next = { ...state, ...patch };
      if (resetPage && !('page' in patch)) next.page = 1;
      setSearchParams(buildQuery(next), { replace: false });
    },
    [state, setSearchParams],
  );

  /* ---------------------------------------------------------------- search box */
  const [qInput, setQInput] = useState(state.q);

  useEffect(() => {
    setQInput(state.q);
  }, [state.q]);

  useEffect(() => {
    if (qInput === state.q) return undefined;
    const t = setTimeout(() => patchState({ q: qInput }), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput, state.q, patchState]);

  /* ---------------------------------------------------------------- master data loading */
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

  /* ---------------------------------------------------------------- filter options */
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

  const subcategoriesLoading =
    subcategoryOptions.length === 0 &&
    (state.category_code.length ? state.category_code : allCategoryCodes).some(
      (c) => !md.subcategoriesByCategory[c],
    );
  const roomsLoading =
    roomOptions.length === 0 &&
    (state.location_code.length ? state.location_code : allLocationCodes).some(
      (l) => !md.roomsByLocation[l],
    );

  /* ---------------------------------------------------------------- dependency pruning */
  useEffect(() => {
    if (state.category_code.length === 0 || state.subcategory_code.length === 0) return;
    const allowed = new Set(state.category_code);
    const kept = state.subcategory_code.filter((v) => allowed.has(v.split('.')[0]));
    if (kept.length !== state.subcategory_code.length) {
      patchState({ subcategory_code: kept });
    }
  }, [state.category_code, state.subcategory_code, patchState]);

  useEffect(() => {
    if (state.location_code.length === 0 || state.room_id.length === 0) return;
    const allowed = new Set(state.location_code);
    const locOf = {};
    for (const [loc, rooms] of Object.entries(md.roomsByLocation)) {
      for (const r of rooms) locOf[String(r.id)] = loc;
    }
    const kept = state.room_id.filter((id) => !(id in locOf) || allowed.has(locOf[id]));
    if (kept.length !== state.room_id.length) {
      patchState({ room_id: kept });
    }
  }, [state.location_code, state.room_id, md.roomsByLocation, patchState]);

  /* ---------------------------------------------------------------- fetch assets */
  useEffect(() => {
    let alive = true;
    setRefreshing((r) => r || result !== null);
    if (result === null) setPhase('loading');

    api
      .get(`/api/assets?${apiQuery}`)
      .then((res) => {
        if (!alive) return;
        setResult(res);
        setPhase('ready');
        setError('');
      })
      .catch((e) => {
        if (!alive) return;
        if (e instanceof ApiError && (e.status === 401 || e.status === 403)) {
          refreshUser();
          return;
        }
        setError(e instanceof ApiError ? e.message : 'Gagal memuat data inventaris.');
        setPhase('error');
      })
      .finally(() => {
        if (alive) setRefreshing(false);
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apiQuery, retryKey]);

  /* ---------------------------------------------------------------- active chips */
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
        default:
          return value;
      }
    },
    [locationOptions, categoryOptions, subcategoryOptions, roomOptions],
  );

  const chips = useMemo(() => {
    const filters = [
      'location_code',
      'category_code',
      'subcategory_code',
      'room_id',
      'condition',
      'is_written_off',
    ];
    const out = [];
    for (const filter of filters) {
      for (const value of state[filter]) {
        out.push({ filter, value, label: chipLabel(filter, value) });
      }
    }
    return out;
  }, [state, chipLabel]);

  const removeChip = (filter, value) =>
    patchState({ [filter]: state[filter].filter((v) => v !== value) });

  const resetAll = () => {
    setQInput('');
    setSearchParams(buildQuery(emptyState()), { replace: false });
  };

  const filterActive = hasActiveFilters(state);
  const meta = result?.meta;
  const assets = result?.data ?? [];

  const toggleRow = (id) => {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleAll = () => {
    setSelectedIds((current) => {
      const allSelected = assets.length > 0 && assets.every((a) => current.has(a.id));
      if (allSelected) return new Set();
      return new Set(assets.map((a) => a.id));
    });
  };

  const selectedAssets = useMemo(
    () => assets.filter((a) => selectedIds.has(a.id)),
    [assets, selectedIds],
  );

  const handleBatchSuccess = (res) => {
    setShowBatchModal(false);
    setSelectedIds(new Set());
    setFlashTone('success');
    setFlash(res?.message || 'Aset berhasil diperbarui.');
    setRetryKey((k) => k + 1);
  };

  const handleDeleteSuccess = (res) => {
    setShowDeleteDialog(false);
    setSelectedIds(new Set());
    setFlashTone('success');
    setFlash(res?.message || 'Aset dipindahkan ke Trash.');
    setRetryKey((k) => k + 1);
  };

  const handlePrintLabels = async (size, mode) => {
    setPrintingLabel(true);
    try {
      await printBatchLabels(selectedAssets, size, mode);
    } catch (e) {
      setFlashTone('error');
      setFlash(e?.message || 'Gagal membuat label. Coba lagi.');
    } finally {
      setPrintingLabel(false);
    }
  };

  const handleExport = async (query) => {
    setExportingExcel(true);
    try {
      await downloadAssetExport(query);
    } catch (e) {
      setFlashTone('error');
      setFlash(e?.message || 'Gagal membuat file export. Coba lagi.');
    } finally {
      setExportingExcel(false);
    }
  };

  const handleDeleteStale = (message) => {
    setShowDeleteDialog(false);
    setSelectedIds(new Set());
    setFlashTone('error');
    setFlash(message);
    setRetryKey((k) => k + 1);
  };

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  // Pagination edge case: a batch delete (or any mutation) can empty out the
  // current page entirely (e.g. deleting all 10 assets on the last page). Laravel's
  // paginator doesn't clamp an out-of-range page itself, so step back to the real
  // last page rather than leaving the user stranded on a blank page.
  useEffect(() => {
    if (!result?.meta) return;
    const { current_page: currentPage, last_page: lastPage, total } = result.meta;
    if (total > 0 && currentPage > lastPage) {
      patchState({ page: lastPage }, { resetPage: false });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [result]);

  /* ---------------------------------------------------------------- render */
  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Inventaris</h1>
          <p className="mt-1 text-sm text-gray-500">
            Kelola dan cari aset Yayasan Vidatra dengan mudah.
          </p>
        </div>
        {(canWriteInventory || canExportAssets) && (
          <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-end">
            {canWriteInventory && (
              <>
                <Link
                  to="/inventory/new"
                  state={{ from: searchParams.toString() }}
                  className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                  <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Aset
                </Link>
                <Link
                  to="/inventory/batch"
                  state={{ from: searchParams.toString() }}
                  className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:border-gray-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
                >
                  <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Banyak Aset
                </Link>
              </>
            )}
            {/* R7.1 — export stays on the legacy can:operator Gate (admin/
                operator only); unit_admin/super_admin don't have it yet, so
                it's gated separately from the create actions above. */}
            {canExportAssets && (
              <ExportMenu
                categories={md.categories}
                currentQuery={apiQuery}
                currentCount={result?.meta?.total ?? 0}
                hasActiveFilters={filterActive}
                onSelect={handleExport}
                busy={exportingExcel}
                align="right"
                buttonClassName="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:border-gray-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
              />
            )}
          </div>
        )}
      </header>

      {flash && (
        <div
          role="status"
          className={
            flashTone === 'error'
              ? 'rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800'
              : 'rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800'
          }
        >
          {flash}
        </div>
      )}

      {/* batch selection toolbar */}
      {canWriteInventory && selectedIds.size > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-900 bg-gray-900 px-3.5 py-2.5 text-sm text-white">
          <span className="font-medium">{selectedIds.size} aset dipilih</span>
          <div className="ml-auto flex gap-2">
            <button
              type="button"
              onClick={() => setShowBatchModal(true)}
              className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-900 hover:bg-gray-100"
            >
              Edit massal
            </button>
            {/* R7.1 — label printing stays admin/operator-only (legacy can:operator Gate) */}
            {canPrintLabels && (
              <PrintLabelMenu
                onSelect={handlePrintLabels}
                busy={printingLabel}
                align="right"
                showModeSelector
                selectionCount={selectedIds.size}
                buttonClassName="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-900 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-60"
              />
            )}
            <button
              type="button"
              onClick={() => setShowDeleteDialog(true)}
              className="rounded-md border border-red-500 bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
            >
              Hapus
            </button>
            <button
              type="button"
              onClick={() => setSelectedIds(new Set())}
              className="rounded-md border border-gray-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-800"
            >
              Batal pilih
            </button>
          </div>
        </div>
      )}

      {/* search + filters */}
      <div className="space-y-3">
        <div className="relative">
          <svg
            viewBox="0 0 20 20"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            aria-hidden="true"
          >
            <circle cx="9" cy="9" r="6" />
            <path d="M14 14l3 3" strokeLinecap="round" />
          </svg>
          <input
            type="search"
            value={qInput}
            onChange={(e) => setQInput(e.target.value)}
            placeholder="Cari kode aset, nama, serial number, atau ruangan…"
            className="w-full rounded-lg border border-gray-300 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900"
          />
        </div>

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
            loading={subcategoriesLoading}
            searchable
            emptyText="Pilih kategori dahulu atau tunggu data dimuat"
          />
          <MultiSelectFilter
            label="Ruangan"
            options={roomOptions}
            selected={state.room_id}
            onChange={(v) => patchState({ room_id: v })}
            loading={roomsLoading}
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
        </div>

        {chips.length > 0 && (
          <div className="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 p-2.5">
            <span className="text-xs font-medium uppercase tracking-wide text-gray-400">
              Filter aktif
            </span>
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

      {/* toolbar: count + sort + page size */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-gray-500">
          {phase === 'loading'
            ? 'Memuat…'
            : meta && meta.total > 0
              ? `Menampilkan ${(meta.current_page - 1) * meta.per_page + 1}–${Math.min(
                  meta.current_page * meta.per_page,
                  meta.total,
                )} dari ${meta.total} aset`
              : phase === 'ready'
                ? 'Tidak ada aset'
                : ''}
        </p>
        <div className="flex items-center gap-2 text-sm">
          <label className="flex items-center gap-1.5 text-gray-500">
            <span className="hidden sm:inline">Urutkan</span>
            <select
              value={sortValue(state)}
              onChange={(e) => {
                const opt = SORTS.find((s) => s.value === e.target.value);
                if (opt) patchState({ sort: opt.sort, direction: opt.direction });
              }}
              className="rounded-md border border-gray-300 bg-white px-2 py-1.5 outline-none focus:border-gray-900"
            >
              {SORTS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex items-center gap-1.5 text-gray-500">
            <span className="hidden sm:inline">Per halaman</span>
            <select
              value={state.per_page}
              onChange={(e) => patchState({ per_page: Number(e.target.value) })}
              className="rounded-md border border-gray-300 bg-white px-2 py-1.5 outline-none focus:border-gray-900"
            >
              {PER_PAGE_OPTIONS.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        </div>
      </div>

      {/* body */}
      {phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat data inventaris.'}</p>
          <p className="mt-1 text-sm text-red-600">Silakan coba lagi.</p>
          <button
            type="button"
            onClick={() => {
              setPhase(result ? 'ready' : 'loading');
              setRetryKey((k) => k + 1);
            }}
            className="mt-3 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            Coba lagi
          </button>
        </div>
      ) : phase === 'ready' && assets.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">Tidak ada aset yang ditemukan.</p>
          <p className="mt-1 text-sm text-gray-500">
            Coba ubah kata pencarian atau filter yang digunakan.
          </p>
          {filterActive && (
            <button
              type="button"
              onClick={resetAll}
              className="mt-3 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-gray-400"
            >
              Reset semua
            </button>
          )}
        </div>
      ) : (
        <>
          <InventoryTable
            assets={assets}
            loading={phase === 'loading'}
            refreshing={refreshing}
            listSearch={searchParams.toString()}
            selectable={canWriteInventory}
            selectedIds={selectedIds}
            onToggleRow={toggleRow}
            onToggleAll={toggleAll}
          />

          {meta && meta.total > 0 && meta.last_page > 1 && (
            <nav className="flex flex-wrap items-center justify-center gap-1.5" aria-label="Paginasi">
              <PageButton
                onClick={() => patchState({ page: meta.current_page - 1 }, { resetPage: false })}
                disabled={meta.current_page <= 1}
                ariaLabel="Halaman sebelumnya"
              >
                ‹ Sebelumnya
              </PageButton>
              {pageWindow(meta.current_page, meta.last_page).map((p) =>
                typeof p === 'string' ? (
                  <span key={p} className="px-1 text-gray-400">
                    …
                  </span>
                ) : (
                  <PageButton
                    key={p}
                    active={p === meta.current_page}
                    onClick={() => patchState({ page: p }, { resetPage: false })}
                    ariaLabel={`Halaman ${p}`}
                  >
                    {p}
                  </PageButton>
                ),
              )}
              <PageButton
                onClick={() => patchState({ page: meta.current_page + 1 }, { resetPage: false })}
                disabled={meta.current_page >= meta.last_page}
                ariaLabel="Halaman berikutnya"
              >
                Berikutnya ›
              </PageButton>
            </nav>
          )}
        </>
      )}

      {canWriteInventory && (
        <BatchEditModal
          open={showBatchModal}
          assets={selectedAssets}
          roomsByLocation={md.roomsByLocation}
          ensureRooms={md.ensureRooms}
          onClose={() => setShowBatchModal(false)}
          onSuccess={handleBatchSuccess}
        />
      )}

      {canWriteInventory && (
        <BatchDeleteDialog
          open={showDeleteDialog}
          assets={selectedAssets}
          onClose={() => setShowDeleteDialog(false)}
          onSuccess={handleDeleteSuccess}
          onStale={handleDeleteStale}
        />
      )}
    </div>
  );
}
