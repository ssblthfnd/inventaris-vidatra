import { useCallback, useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import SubcategoryFormModal from './SubcategoryFormModal';
import { api, ApiError } from '../../lib/api';

const SEARCH_DEBOUNCE_MS = 350;

/**
 * Subcategories panel for the Master Data page (Tahap 6.8.4) — same shape
 * as `RoomsPanel`. Admin-only.
 *
 * Fetches its own admin-scoped category list (`?include_inactive=1`) once,
 * used for TWO different purposes: the category FILTER dropdown here shows
 * every category (active + inactive, so an admin can browse subcategories
 * under a since-deactivated category), while only the ACTIVE subset is
 * passed to `SubcategoryFormModal` for the create-mode category selector —
 * a new subcategory can never be created under an inactive category. One
 * fetch, two derived views; no second network round trip.
 */
export default function SubcategoriesPanel() {
  const [categories, setCategories] = useState([]);

  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');
  const [categoryCode, setCategoryCode] = useState('');

  const [result, setResult] = useState(null); // { data: [...] }
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success');

  const [formModal, setFormModal] = useState(null); // { mode: 'create'|'edit', subcategory? }
  const [statusTarget, setStatusTarget] = useState(null); // subcategory (toggle confirm)
  const [statusBusy, setStatusBusy] = useState(false);
  const [statusError, setStatusError] = useState('');

  useEffect(() => {
    let alive = true;
    api
      .get('/api/categories?include_inactive=1')
      .then((res) => {
        if (alive) setCategories(res?.data ?? []);
      })
      .catch(() => {
        /* the category filter/selector simply stays empty; the list below
           still loads and surfaces its own error independently */
      });
    return () => {
      alive = false;
    };
  }, []);

  useEffect(() => {
    const t = setTimeout(() => setQ(qInput), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput]);

  const load = useCallback(() => {
    let alive = true;
    setPhase((p) => (result === null ? 'loading' : p));

    const params = new URLSearchParams();
    if (q.trim()) params.set('q', q.trim());
    if (categoryCode) params.set('category_code', categoryCode);

    api
      .get(`/api/subcategories?${params.toString()}`)
      .then((res) => {
        if (!alive) return;
        setResult(res);
        setPhase('ready');
        setError('');
      })
      .catch((e) => {
        if (!alive) return;
        setError(e instanceof ApiError ? e.message : 'Gagal memuat daftar subkategori.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q, categoryCode, retryKey]);

  useEffect(() => load(), [load]);

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  const subcategories = result?.data ?? [];
  const activeCategories = categories.filter((c) => c.is_active);

  const handleFormSuccess = (res, action) => {
    setFormModal(null);
    setFlashTone('success');
    setFlash(action === 'create' ? 'Subkategori berhasil dibuat.' : 'Perubahan berhasil disimpan.');
    setRetryKey((k) => k + 1);
  };

  const openStatusToggle = (subcategory) => {
    setStatusError('');
    setStatusTarget(subcategory);
  };

  const confirmStatusToggle = async () => {
    if (!statusTarget) return;
    setStatusBusy(true);
    setStatusError('');
    try {
      await api.patch(`/api/subcategories/${statusTarget.id}`, { is_active: !statusTarget.is_active });
      setStatusBusy(false);
      setStatusTarget(null);
      setFlashTone('success');
      setFlash(statusTarget.is_active ? 'Subkategori dinonaktifkan.' : 'Subkategori diaktifkan kembali.');
      setRetryKey((k) => k + 1);
    } catch (e) {
      setStatusBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 422) {
          setStatusError(
            Object.values(e.errors ?? {})[0]?.[0] || e.message || 'Aksi ini tidak diizinkan.',
          );
          return;
        }
        setStatusError(e.message || 'Gagal menyimpan perubahan. Coba lagi.');
        return;
      }
      setStatusError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-base font-semibold text-gray-900">Subkategori</h2>
          <p className="mt-0.5 text-sm text-gray-500">Kelola master subkategori per kategori.</p>
        </div>
        <button
          type="button"
          onClick={() => setFormModal({ mode: 'create' })}
          className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Subkategori
        </button>
      </div>

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

      <div className="flex flex-wrap gap-2">
        <input
          type="search"
          value={qInput}
          onChange={(e) => setQInput(e.target.value)}
          placeholder="Cari kode atau nama…"
          className="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 sm:w-64"
        />
        <select
          value={categoryCode}
          onChange={(e) => setCategoryCode(e.target.value)}
          className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-900"
        >
          <option value="">Semua kategori</option>
          {categories.map((c) => (
            <option key={c.code} value={c.code}>
              {c.name}
              {c.is_active === false ? ' (Nonaktif)' : ''}
            </option>
          ))}
        </select>
      </div>

      {phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat daftar subkategori.'}</p>
          <button
            type="button"
            onClick={() => setRetryKey((k) => k + 1)}
            className="mt-3 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            Coba lagi
          </button>
        </div>
      ) : phase === 'loading' && result === null ? (
        <p className="text-sm text-gray-500">Memuat…</p>
      ) : subcategories.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">Tidak ada subkategori yang ditemukan.</p>
          <p className="mt-1 text-sm text-gray-500">Coba ubah kata pencarian atau filter kategori.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
          <table className="w-full min-w-[760px] text-sm">
            <thead>
              <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2.5">Kategori</th>
                <th className="px-4 py-2.5">Kode</th>
                <th className="px-4 py-2.5">Nama Subkategori</th>
                <th className="px-4 py-2.5">Nama Panduan</th>
                <th className="px-4 py-2.5">Status</th>
                <th className="px-4 py-2.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {subcategories.map((s) => (
                <tr key={s.id} className="border-t border-gray-100">
                  <td className="px-4 py-2.5 text-gray-700">{s.category?.name}</td>
                  <td className="px-4 py-2.5 font-mono text-xs text-gray-500">{s.code}</td>
                  <td className="px-4 py-2.5 font-medium text-gray-900">{s.name}</td>
                  <td className="px-4 py-2.5 text-gray-500">{s.guide_name || '—'}</td>
                  <td className="px-4 py-2.5">
                    <span
                      className={[
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                        s.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500',
                      ].join(' ')}
                    >
                      {s.is_active ? 'Aktif' : 'Nonaktif'}
                    </span>
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex justify-end gap-1.5">
                      <button
                        type="button"
                        onClick={() => setFormModal({ mode: 'edit', subcategory: s })}
                        className="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-gray-400"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => openStatusToggle(s)}
                        className={[
                          'rounded-md border px-2.5 py-1 text-xs font-medium',
                          s.is_active
                            ? 'border-red-300 text-red-700 hover:border-red-400'
                            : 'border-emerald-300 text-emerald-700 hover:border-emerald-400',
                        ].join(' ')}
                      >
                        {s.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <SubcategoryFormModal
        open={formModal !== null}
        mode={formModal?.mode}
        subcategory={formModal?.subcategory}
        activeCategories={activeCategories}
        onClose={() => setFormModal(null)}
        onSuccess={handleFormSuccess}
      />

      <LifecycleConfirmDialog
        open={statusTarget !== null}
        title={statusTarget?.is_active ? 'Nonaktifkan subkategori ini?' : 'Aktifkan subkategori ini?'}
        tone={statusTarget?.is_active ? 'danger' : 'default'}
        confirmLabel={statusTarget?.is_active ? 'Nonaktifkan' : 'Aktifkan'}
        busy={statusBusy}
        error={statusError}
        onConfirm={confirmStatusToggle}
        onClose={() => !statusBusy && setStatusTarget(null)}
      >
        {statusTarget && (
          <>
            <p>
              <span className="font-medium text-gray-900">{statusTarget.name}</span>{' '}
              ({statusTarget.category?.name} · {statusTarget.code})
            </p>
            <p className="text-xs text-gray-500">
              {statusTarget.is_active
                ? 'Aset yang sudah ada di bawah subkategori ini tidak akan dihapus atau diubah — subkategori hanya tidak akan tersedia untuk entri aset atau import baru.'
                : 'Subkategori akan tersedia kembali untuk entri aset dan import baru.'}
            </p>
          </>
        )}
      </LifecycleConfirmDialog>
    </div>
  );
}
