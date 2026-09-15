import { useCallback, useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import LocationFormModal from './LocationFormModal';
import { api, ApiError } from '../../lib/api';

const SEARCH_DEBOUNCE_MS = 350;

/**
 * Locations panel for the Master Data page (Tahap 6.8.3) — same shape as
 * `RoomsPanel`. Admin-only, matching the backend's `can:admin` gate.
 *
 * Deliberately does NOT use `useMasterData()` for its list — that hook's
 * `locations` state is the plain active-only `GET /api/locations` result
 * (shared by Inventory/Reports/AssetForm), which never includes inactive
 * rows and must never be made to by this panel (see `LocationController`'s
 * docblock). This panel fetches its own admin view directly via
 * `?include_inactive=1`, exactly like `RoomsPanel`/`RoomAliasesPanel` already
 * fetch their own admin-scoped lists independently of the shared hook.
 */
export default function LocationsPanel() {
  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');

  const [result, setResult] = useState(null); // { data: [...] }
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success');

  const [formModal, setFormModal] = useState(null); // { mode: 'create'|'edit', location? }
  const [statusTarget, setStatusTarget] = useState(null); // location (toggle confirm)
  const [statusBusy, setStatusBusy] = useState(false);
  const [statusError, setStatusError] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setQ(qInput), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput]);

  const load = useCallback(() => {
    let alive = true;
    setPhase((p) => (result === null ? 'loading' : p));

    const params = new URLSearchParams({ include_inactive: '1' });
    if (q.trim()) params.set('q', q.trim());

    api
      .get(`/api/locations?${params.toString()}`)
      .then((res) => {
        if (!alive) return;
        setResult(res);
        setPhase('ready');
        setError('');
      })
      .catch((e) => {
        if (!alive) return;
        setError(e instanceof ApiError ? e.message : 'Gagal memuat daftar lokasi.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q, retryKey]);

  useEffect(() => load(), [load]);

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  const locations = result?.data ?? [];

  const handleFormSuccess = (res, action) => {
    setFormModal(null);
    setFlashTone('success');
    setFlash(action === 'create' ? 'Lokasi berhasil dibuat.' : 'Perubahan berhasil disimpan.');
    setRetryKey((k) => k + 1);
  };

  const openStatusToggle = (location) => {
    setStatusError('');
    setStatusTarget(location);
  };

  const confirmStatusToggle = async () => {
    if (!statusTarget) return;
    setStatusBusy(true);
    setStatusError('');
    try {
      await api.patch(`/api/locations/${statusTarget.code}`, { is_active: !statusTarget.is_active });
      setStatusBusy(false);
      setStatusTarget(null);
      setFlashTone('success');
      setFlash(statusTarget.is_active ? 'Lokasi dinonaktifkan.' : 'Lokasi diaktifkan kembali.');
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
          <h2 className="text-base font-semibold text-gray-900">Lokasi</h2>
          <p className="mt-0.5 text-sm text-gray-500">Kelola master lokasi struktural.</p>
        </div>
        <button
          type="button"
          onClick={() => setFormModal({ mode: 'create' })}
          className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Lokasi
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

      <input
        type="search"
        value={qInput}
        onChange={(e) => setQInput(e.target.value)}
        placeholder="Cari kode, nama, atau alias…"
        className="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 sm:w-64"
      />

      {phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat daftar lokasi.'}</p>
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
      ) : locations.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">Tidak ada lokasi yang ditemukan.</p>
          <p className="mt-1 text-sm text-gray-500">Coba ubah kata pencarian yang digunakan.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2.5">Kode</th>
                <th className="px-4 py-2.5">Nama Lokasi</th>
                <th className="px-4 py-2.5">Alias</th>
                <th className="px-4 py-2.5">Status</th>
                <th className="px-4 py-2.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {locations.map((l) => (
                <tr key={l.code} className="border-t border-gray-100">
                  <td className="px-4 py-2.5 font-mono text-xs text-gray-500">{l.code}</td>
                  <td className="px-4 py-2.5 font-medium text-gray-900">{l.name}</td>
                  <td className="px-4 py-2.5 text-gray-700">{l.alias || '—'}</td>
                  <td className="px-4 py-2.5">
                    <span
                      className={[
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                        l.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500',
                      ].join(' ')}
                    >
                      {l.is_active ? 'Aktif' : 'Nonaktif'}
                    </span>
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex justify-end gap-1.5">
                      <button
                        type="button"
                        onClick={() => setFormModal({ mode: 'edit', location: l })}
                        className="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-gray-400"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => openStatusToggle(l)}
                        className={[
                          'rounded-md border px-2.5 py-1 text-xs font-medium',
                          l.is_active
                            ? 'border-red-300 text-red-700 hover:border-red-400'
                            : 'border-emerald-300 text-emerald-700 hover:border-emerald-400',
                        ].join(' ')}
                      >
                        {l.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <LocationFormModal
        open={formModal !== null}
        mode={formModal?.mode}
        location={formModal?.location}
        onClose={() => setFormModal(null)}
        onSuccess={handleFormSuccess}
      />

      <LifecycleConfirmDialog
        open={statusTarget !== null}
        title={statusTarget?.is_active ? 'Nonaktifkan lokasi ini?' : 'Aktifkan lokasi ini?'}
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
              ({statusTarget.code})
            </p>
            <p className="text-xs text-gray-500">
              {statusTarget.is_active
                ? 'Ruangan, alias, dan aset yang sudah ada di bawah lokasi ini tidak akan dihapus atau diubah — lokasi hanya tidak akan tersedia untuk entri ruangan, alias, atau import baru.'
                : 'Lokasi akan tersedia kembali untuk entri ruangan, alias, dan import baru.'}
            </p>
          </>
        )}
      </LifecycleConfirmDialog>
    </div>
  );
}
