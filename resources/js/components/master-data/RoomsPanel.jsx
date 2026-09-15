import { useCallback, useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import RoomFormModal from './RoomFormModal';
import { api, ApiError } from '../../lib/api';
import { useMasterData } from '../../lib/useMasterData';

const SEARCH_DEBOUNCE_MS = 350;

/**
 * Rooms tab/panel for the Master Data page (Tahap 6.8.1). Admin-only — the
 * parent `MasterData.jsx` page already gates the whole page on `isAdmin`, so
 * this component assumes it is only ever rendered for an admin.
 *
 * List is a plain unpaginated `{ data: [...] }` collection from the new
 * `GET /api/rooms` admin endpoint (active AND inactive, every location) —
 * deliberately no pagination UI, matching every other master-data list in
 * this app (docs/api_convention.md).
 */
export default function RoomsPanel() {
  const { locations } = useMasterData();

  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');
  const [locationCode, setLocationCode] = useState('');

  const [result, setResult] = useState(null); // { data: [...] }
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success');

  const [formModal, setFormModal] = useState(null); // { mode: 'create'|'edit', room? }
  const [statusTarget, setStatusTarget] = useState(null); // room (toggle confirm)
  const [statusBusy, setStatusBusy] = useState(false);
  const [statusError, setStatusError] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setQ(qInput), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput]);

  const load = useCallback(() => {
    let alive = true;
    setPhase((p) => (result === null ? 'loading' : p));

    const params = new URLSearchParams();
    if (q.trim()) params.set('q', q.trim());
    if (locationCode) params.set('location_code', locationCode);

    api
      .get(`/api/rooms?${params.toString()}`)
      .then((res) => {
        if (!alive) return;
        setResult(res);
        setPhase('ready');
        setError('');
      })
      .catch((e) => {
        if (!alive) return;
        setError(e instanceof ApiError ? e.message : 'Gagal memuat daftar ruangan.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q, locationCode, retryKey]);

  useEffect(() => load(), [load]);

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  const rooms = result?.data ?? [];

  const handleFormSuccess = (res, action) => {
    setFormModal(null);
    setFlashTone('success');
    setFlash(action === 'create' ? 'Ruangan berhasil dibuat.' : 'Perubahan berhasil disimpan.');
    setRetryKey((k) => k + 1);
  };

  const openStatusToggle = (room) => {
    setStatusError('');
    setStatusTarget(room);
  };

  const confirmStatusToggle = async () => {
    if (!statusTarget) return;
    setStatusBusy(true);
    setStatusError('');
    try {
      await api.patch(`/api/rooms/${statusTarget.id}`, { is_active: !statusTarget.is_active });
      setStatusBusy(false);
      setStatusTarget(null);
      setFlashTone('success');
      setFlash(statusTarget.is_active ? 'Ruangan dinonaktifkan.' : 'Ruangan diaktifkan kembali.');
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
          <h2 className="text-base font-semibold text-gray-900">Ruangan</h2>
          <p className="mt-0.5 text-sm text-gray-500">Kelola master ruangan per lokasi.</p>
        </div>
        <button
          type="button"
          onClick={() => setFormModal({ mode: 'create' })}
          className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Ruangan
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
          placeholder="Cari nama ruangan…"
          className="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 sm:w-64"
        />
        <select
          value={locationCode}
          onChange={(e) => setLocationCode(e.target.value)}
          className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-900"
        >
          <option value="">Semua lokasi</option>
          {locations.map((l) => (
            <option key={l.code} value={l.code}>
              {l.name}
            </option>
          ))}
        </select>
      </div>

      {phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat daftar ruangan.'}</p>
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
      ) : rooms.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">Tidak ada ruangan yang ditemukan.</p>
          <p className="mt-1 text-sm text-gray-500">Coba ubah kata pencarian atau filter lokasi.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2.5">Nama Ruangan</th>
                <th className="px-4 py-2.5">Lokasi</th>
                <th className="px-4 py-2.5">PIC</th>
                <th className="px-4 py-2.5">Catatan</th>
                <th className="px-4 py-2.5">Status</th>
                <th className="px-4 py-2.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {rooms.map((r) => (
                <tr key={r.id} className="border-t border-gray-100">
                  <td className="px-4 py-2.5 font-medium text-gray-900">{r.name}</td>
                  <td className="px-4 py-2.5 text-gray-700">{r.location?.name}</td>
                  <td className="px-4 py-2.5 text-gray-700">{r.pic || '—'}</td>
                  <td className="px-4 py-2.5 text-gray-500">{r.notes || '—'}</td>
                  <td className="px-4 py-2.5">
                    <span
                      className={[
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                        r.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500',
                      ].join(' ')}
                    >
                      {r.is_active ? 'Aktif' : 'Nonaktif'}
                    </span>
                  </td>
                  <td className="px-4 py-2.5">
                    <div className="flex justify-end gap-1.5">
                      <button
                        type="button"
                        onClick={() => setFormModal({ mode: 'edit', room: r })}
                        className="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-gray-400"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => openStatusToggle(r)}
                        className={[
                          'rounded-md border px-2.5 py-1 text-xs font-medium',
                          r.is_active
                            ? 'border-red-300 text-red-700 hover:border-red-400'
                            : 'border-emerald-300 text-emerald-700 hover:border-emerald-400',
                        ].join(' ')}
                      >
                        {r.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <RoomFormModal
        open={formModal !== null}
        mode={formModal?.mode}
        room={formModal?.room}
        locations={locations}
        onClose={() => setFormModal(null)}
        onSuccess={handleFormSuccess}
      />

      <LifecycleConfirmDialog
        open={statusTarget !== null}
        title={statusTarget?.is_active ? 'Nonaktifkan ruangan ini?' : 'Aktifkan ruangan ini?'}
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
              ({statusTarget.location?.name})
            </p>
            <p className="text-xs text-gray-500">
              {statusTarget.is_active
                ? 'Ruangan tidak akan tersedia untuk entri aset atau import baru, tetapi data aset yang sudah ada tidak akan berubah.'
                : 'Ruangan akan tersedia kembali untuk entri aset dan import baru.'}
            </p>
          </>
        )}
      </LifecycleConfirmDialog>
    </div>
  );
}
