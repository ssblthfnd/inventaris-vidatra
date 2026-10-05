import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import LifecycleConfirmDialog from '../components/LifecycleConfirmDialog';
import MultiSelectFilter from '../components/MultiSelectFilter';
import Pagination from '../components/Pagination';
import { api, ApiError } from '../lib/api';
import { CenteredState } from '../lib/assetFields';
import { buildQuery, emptyState, hasActiveFilters, parseQuery, ROOMLESS } from '../lib/inventoryQuery';
import { useMasterData } from '../lib/useMasterData';

/**
 * Sampah Aset — the asset Trash (Tahap 6.9 R9.4-14).
 *
 * Lists soft-deleted assets from `GET /api/assets/trash` so whoever may restore
 * (`assets.restore`) can find one first, and restores through the existing
 * `POST /api/assets/{id}/restore`. The backend is the authority on access and
 * scope (a unit_admin only ever receives its own location's deleted assets);
 * this page only hides itself from actors without the ability.
 *
 * URL state reuses `lib/inventoryQuery` (same `?q=` / `key[]=` encoding as the
 * Inventaris page), so refresh and Back/Forward keep the filters. Only the
 * filters that help find a deleted asset are offered: search, location,
 * category, subcategory and room. Order is fixed server-side: most recently
 * deleted first.
 */

const SEARCH_DEBOUNCE_MS = 350;

function formatDeletedAt(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

export default function Trash() {
  const { can, refreshUser } = useAuth();
  const allowed = can('assets.restore');
  const [searchParams, setSearchParams] = useSearchParams();
  const md = useMasterData();
  const { ensureSubcategories, ensureRooms } = md;

  const state = useMemo(() => parseQuery(searchParams), [searchParams]);
  const apiQuery = useMemo(() => buildQuery(state), [state]);

  const [result, setResult] = useState(null);
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [flash, setFlash] = useState('');

  const [restoring, setRestoring] = useState(null); // the asset in the confirmation dialog
  const [restoreBusy, setRestoreBusy] = useState(false);
  const [restoreError, setRestoreError] = useState('');

  const patchState = useCallback(
    (patch, { resetPage = true } = {}) => {
      const next = { ...state, ...patch };
      if (resetPage && !('page' in patch)) next.page = 1;
      setSearchParams(buildQuery(next));
    },
    [state, setSearchParams],
  );

  /* ---------------------------------------------------------------- search box */
  const [qInput, setQInput] = useState(state.q);
  useEffect(() => setQInput(state.q), [state.q]);
  useEffect(() => {
    if (qInput === state.q) return undefined;
    const t = setTimeout(() => patchState({ q: qInput }), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput, state.q, patchState]);

  /* ---------------------------------------------------------------- filter options */
  const allCategoryCodes = useMemo(() => md.categories.map((c) => c.code), [md.categories]);
  const allLocationCodes = useMemo(() => md.locations.map((l) => l.code), [md.locations]);

  useEffect(() => {
    if (!allowed) return;
    const codes = state.category_code.length ? state.category_code : allCategoryCodes;
    if (codes.length) ensureSubcategories(codes);
  }, [allowed, state.category_code, allCategoryCodes, ensureSubcategories]);

  useEffect(() => {
    if (!allowed) return;
    const codes = state.location_code.length ? state.location_code : allLocationCodes;
    if (codes.length) ensureRooms(codes);
  }, [allowed, state.location_code, allLocationCodes, ensureRooms]);

  const locationOptions = useMemo(
    () => md.locations.map((l) => ({ value: l.code, label: l.name, hint: l.alias })),
    [md.locations],
  );
  const categoryOptions = useMemo(
    () => md.categories.map((c) => ({ value: c.code, label: c.name })),
    [md.categories],
  );
  const subcategoryOptions = useMemo(() => {
    const cats = state.category_code.length ? state.category_code : allCategoryCodes;
    const out = [];
    for (const cat of cats) {
      for (const s of md.subcategoriesByCategory[cat] ?? []) {
        out.push({ value: `${s.category.code}.${s.code}`, label: `${s.name} — ${s.category.name}`, hint: `Kode ${s.code}` });
      }
    }
    return out.sort((a, b) => a.label.localeCompare(b.label));
  }, [state.category_code, allCategoryCodes, md.subcategoriesByCategory]);
  const roomOptions = useMemo(() => {
    const locs = state.location_code.length ? state.location_code : allLocationCodes;
    const out = [{ value: ROOMLESS, label: 'Tanpa Ruangan', hint: 'Aset yang tidak memiliki ruangan' }];
    for (const loc of locs) {
      for (const r of md.roomsByLocation[loc] ?? []) {
        out.push({ value: String(r.id), label: r.name, hint: r.location?.name });
      }
    }
    return out;
  }, [state.location_code, allLocationCodes, md.roomsByLocation]);

  /* ---------------------------------------------------------------- fetch */
  useEffect(() => {
    if (!allowed) return undefined;
    let alive = true;
    if (result === null) setPhase('loading');

    api
      .get(`/api/assets/trash?${apiQuery}`)
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
        setError(e instanceof ApiError ? e.message : 'Gagal memuat Sampah Aset.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allowed, apiQuery, reloadKey]);

  /* ---------------------------------------------------------------- restore */
  const openRestore = (asset) => {
    setRestoreError('');
    setRestoring(asset);
  };

  const doRestore = async () => {
    if (!restoring) return;
    const asset = restoring;
    setRestoreBusy(true);
    setRestoreError('');
    try {
      await api.post(`/api/assets/${encodeURIComponent(asset.id)}/restore`);
      // drop the row right away, then re-read the page so paging/totals stay exact
      setResult((current) =>
        current
          ? {
              ...current,
              data: current.data.filter((a) => a.id !== asset.id),
              meta: current.meta ? { ...current.meta, total: Math.max(0, current.meta.total - 1) } : current.meta,
            }
          : current,
      );
      setRestoring(null);
      setFlash(`Aset ${asset.asset_code} dipulihkan dan kembali muncul di Inventaris.`);
      const onlyRowOnPage = (result?.data?.length ?? 0) <= 1;
      if (onlyRowOnPage && state.page > 1) patchState({ page: state.page - 1 }, { resetPage: false });
      else setReloadKey((k) => k + 1);
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        refreshUser();
        return;
      }
      setRestoreError(
        e instanceof ApiError && e.status === 403
          ? 'Anda tidak memiliki izin untuk memulihkan aset ini.'
          : e?.message || 'Gagal memulihkan aset. Coba lagi.',
      );
    } finally {
      setRestoreBusy(false);
    }
  };

  if (!allowed) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk melihat Sampah Aset."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }

  const filterActive = hasActiveFilters(state);
  const meta = result?.meta;
  const assets = result?.data ?? [];
  const resetAll = () => {
    setQInput('');
    setSearchParams(buildQuery(emptyState()));
  };

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-gray-900">Sampah Aset</h1>
          <p className="mt-0.5 text-sm text-gray-500">
            Aset yang telah dihapus dan masih dapat dipulihkan. Aset yang dipulihkan kembali muncul di
            Inventaris dengan kode aset yang sama.
          </p>
        </div>
        <Link
          to="/inventory"
          className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:border-gray-400"
        >
          Kembali ke Inventaris
        </Link>
      </header>

      {flash && (
        <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800">
          {flash}
        </div>
      )}

      <div className="space-y-3">
        <input
          type="search"
          value={qInput}
          onChange={(e) => setQInput(e.target.value)}
          placeholder="Cari kode aset, nama, serial number, atau ruangan…"
          className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900"
        />
        <div className="flex flex-wrap items-center gap-2">
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
          {filterActive && (
            <button
              type="button"
              onClick={resetAll}
              className="rounded-md px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200"
            >
              Reset filter
            </button>
          )}
        </div>
      </div>

      <p className="text-sm text-gray-500">
        {phase === 'loading'
          ? 'Memuat…'
          : meta && meta.total > 0
            ? `${meta.total} aset dihapus`
            : ''}
      </p>

      {phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat Sampah Aset.'}</p>
          <button
            type="button"
            onClick={() => setReloadKey((k) => k + 1)}
            className="mt-3 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            Coba lagi
          </button>
        </div>
      ) : phase === 'ready' && assets.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">
            {filterActive ? 'Tidak ada aset dihapus yang cocok dengan filter.' : 'Tidak ada aset yang dihapus.'}
          </p>
        </div>
      ) : (
        <>
          <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead>
                  <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                    <th className="px-4 py-3">Kode Aset</th>
                    <th className="px-4 py-3">Aset</th>
                    <th className="px-4 py-3">Lokasi</th>
                    <th className="px-4 py-3">Ruangan</th>
                    <th className="px-4 py-3">Dihapus</th>
                    <th className="px-4 py-3 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {phase === 'loading' ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-8 text-center text-sm text-gray-400">
                        Memuat…
                      </td>
                    </tr>
                  ) : (
                    assets.map((asset) => (
                      <tr key={asset.id} className="border-t border-gray-100 hover:bg-gray-50/60">
                        <td className="px-4 py-3">
                          <Link
                            to={`/inventory/${asset.id}`}
                            state={{ fromTrash: searchParams.toString() }}
                            className="font-mono text-[13px] font-medium text-gray-900 hover:underline"
                          >
                            {asset.asset_code}
                          </Link>
                        </td>
                        <td className="px-4 py-3">
                          <span className="block text-gray-900">{asset.subcategory?.name ?? '—'}</span>
                          <span className="block text-xs text-gray-500">
                            {[asset.category?.name, asset.brand_model].filter(Boolean).join(' · ')}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-gray-700">{asset.location?.name ?? asset.location?.code}</td>
                        <td className="px-4 py-3">
                          {asset.room ? (
                            <span className="text-gray-700">{asset.room.name}</span>
                          ) : (
                            <span className="text-gray-400">Tanpa ruangan</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-gray-700">{formatDeletedAt(asset.deleted_at)}</td>
                        <td className="px-4 py-3 text-right">
                          <button
                            type="button"
                            onClick={() => openRestore(asset)}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-gray-400"
                          >
                            Pulihkan
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>

          <Pagination meta={meta} onPageChange={(p) => patchState({ page: p }, { resetPage: false })} />
        </>
      )}

      <LifecycleConfirmDialog
        open={restoring !== null}
        title="Pulihkan aset ini?"
        confirmLabel="Pulihkan"
        busy={restoreBusy}
        error={restoreError}
        onConfirm={doRestore}
        onClose={() => !restoreBusy && setRestoring(null)}
      >
        {restoring && (
          <p>
            Aset <span className="font-mono text-gray-900">{restoring.asset_code}</span> akan kembali muncul
            di Inventaris. Kode aset dan nomor urut tidak berubah.
          </p>
        )}
      </LifecycleConfirmDialog>
    </div>
  );
}
