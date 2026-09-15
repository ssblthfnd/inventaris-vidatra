import { useCallback, useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import RoomAliasFormModal from './RoomAliasFormModal';
import { api, ApiError } from '../../lib/api';
import { useMasterData } from '../../lib/useMasterData';

const SEARCH_DEBOUNCE_MS = 350;

/**
 * Room Aliases panel for the Master Data page (Tahap 6.8.2) — same shape as
 * `RoomsPanel`, but operator (not just admin) can reach every write action
 * here, matching the backend's `can:operator` gate (see
 * `RoomAliasController`'s docblock). The parent `MasterData.jsx` page only
 * gates the whole page on `isAdmin`, so an operator never sees this panel at
 * all today — it is still built role-correctly (not hardcoded admin-only)
 * so a future stage can surface it to operators without touching this file.
 *
 * List is a plain unpaginated `{ data: [...] }` collection from
 * `GET /api/room-aliases` (no `is_active` concept for aliases — see the
 * backend docblock), filterable by `location_code`, `room_id`, `q`.
 *
 * Fetches its own admin-scoped location list (`?include_inactive=1`) once,
 * same reasoning and shape as `SubcategoriesPanel`'s category list and
 * `RoomsPanel`'s location list: the location FILTER dropdown here shows
 * every location (active + inactive, so an admin can browse aliases under a
 * since-deactivated location), while only the ACTIVE subset is passed to
 * `RoomAliasFormModal` for the create-mode location selector — a new alias
 * can never be created under an inactive location.
 */
export default function RoomAliasesPanel() {
  const { roomsByLocation, ensureRooms } = useMasterData();
  const [locations, setLocations] = useState([]);

  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');
  const [locationCode, setLocationCode] = useState('');
  const [roomId, setRoomId] = useState('');

  const [result, setResult] = useState(null); // { data: [...] }
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success');

  const [formModal, setFormModal] = useState(null); // { mode: 'create'|'edit', alias? }
  const [deleteTarget, setDeleteTarget] = useState(null); // alias
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  useEffect(() => {
    let alive = true;
    api
      .get('/api/locations?include_inactive=1')
      .then((res) => {
        if (alive) setLocations(res?.data ?? []);
      })
      .catch(() => {
        /* the location filter/selector simply stays empty; the list below
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

  useEffect(() => {
    if (locationCode) ensureRooms([locationCode]);
  }, [locationCode, ensureRooms]);

  const load = useCallback(() => {
    let alive = true;
    setPhase((p) => (result === null ? 'loading' : p));

    const params = new URLSearchParams();
    if (q.trim()) params.set('q', q.trim());
    if (locationCode) params.set('location_code', locationCode);
    if (roomId) params.set('room_id', roomId);

    api
      .get(`/api/room-aliases?${params.toString()}`)
      .then((res) => {
        if (!alive) return;
        setResult(res);
        setPhase('ready');
        setError('');
      })
      .catch((e) => {
        if (!alive) return;
        setError(e instanceof ApiError ? e.message : 'Gagal memuat daftar alias ruangan.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q, locationCode, roomId, retryKey]);

  useEffect(() => load(), [load]);

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  const aliases = result?.data ?? [];
  const roomFilterOptions = roomsByLocation[locationCode] ?? [];
  const activeLocations = locations.filter((l) => l.is_active);

  const handleLocationFilterChange = (value) => {
    setLocationCode(value);
    setRoomId(''); // room filter is scoped to a location — clear it on change
  };

  const handleFormSuccess = (res, action) => {
    setFormModal(null);
    setFlashTone('success');
    setFlash(action === 'create' ? 'Alias berhasil dibuat.' : 'Perubahan berhasil disimpan.');
    setRetryKey((k) => k + 1);
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setDeleteBusy(true);
    setDeleteError('');
    try {
      await api.delete(`/api/room-aliases/${deleteTarget.id}`);
      setDeleteBusy(false);
      setDeleteTarget(null);
      setFlashTone('success');
      setFlash('Alias berhasil dihapus.');
      setRetryKey((k) => k + 1);
    } catch (e) {
      setDeleteBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 404) {
          setDeleteTarget(null);
          setFlashTone('error');
          setFlash('Alias sudah dihapus sebelumnya.');
          setRetryKey((k) => k + 1);
          return;
        }
        setDeleteError(e.message || 'Gagal menghapus alias. Coba lagi.');
        return;
      }
      setDeleteError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-base font-semibold text-gray-900">Alias Ruangan</h2>
          <p className="mt-0.5 text-sm text-gray-500">
            Petakan variasi teks ruangan dari Excel ke ruangan master.
          </p>
        </div>
        <button
          type="button"
          onClick={() => setFormModal({ mode: 'create' })}
          className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Alias
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
          placeholder="Cari nilai mentah…"
          className="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 sm:w-64"
        />
        <select
          value={locationCode}
          onChange={(e) => handleLocationFilterChange(e.target.value)}
          className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-900"
        >
          <option value="">Semua lokasi</option>
          {locations.map((l) => (
            <option key={l.code} value={l.code}>
              {l.name}
            </option>
          ))}
        </select>
        <select
          value={roomId}
          onChange={(e) => setRoomId(e.target.value)}
          disabled={!locationCode}
          className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-900 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400"
        >
          <option value="">Semua ruangan</option>
          {roomFilterOptions.map((r) => (
            <option key={r.id} value={r.id}>
              {r.name}
            </option>
          ))}
        </select>
      </div>

      {phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat daftar alias ruangan.'}</p>
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
      ) : aliases.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">Tidak ada alias yang ditemukan.</p>
          <p className="mt-1 text-sm text-gray-500">Coba ubah kata pencarian atau filter yang digunakan.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
          <table className="w-full min-w-[860px] text-sm">
            <thead>
              <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2.5">Nilai Mentah</th>
                <th className="px-4 py-2.5">Kunci Normalisasi</th>
                <th className="px-4 py-2.5">Lokasi</th>
                <th className="px-4 py-2.5">Ruangan</th>
                <th className="px-4 py-2.5">Sumber</th>
                <th className="px-4 py-2.5">Catatan</th>
                <th className="px-4 py-2.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {aliases.map((a) => (
                <tr key={a.id} className="border-t border-gray-100">
                  <td className="px-4 py-2.5 font-medium text-gray-900">{a.raw_value}</td>
                  <td className="px-4 py-2.5 font-mono text-xs text-gray-500">{a.match_key}</td>
                  <td className="px-4 py-2.5 text-gray-700">{a.location?.name}</td>
                  <td className="px-4 py-2.5 text-gray-700">
                    {a.room?.name}
                    {a.room?.is_active === false && (
                      <span className="ml-1.5 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">
                        Nonaktif
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-gray-500">
                    {a.source === 'tahap1_seed' ? 'Seed awal' : 'Manual'}
                  </td>
                  <td className="px-4 py-2.5 text-gray-500">{a.notes || '—'}</td>
                  <td className="px-4 py-2.5">
                    <div className="flex justify-end gap-1.5">
                      <button
                        type="button"
                        onClick={() => setFormModal({ mode: 'edit', alias: a })}
                        className="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-gray-400"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setDeleteError('');
                          setDeleteTarget(a);
                        }}
                        className="rounded-md border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:border-red-400"
                      >
                        Hapus
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <RoomAliasFormModal
        open={formModal !== null}
        mode={formModal?.mode}
        alias={formModal?.alias}
        locations={activeLocations}
        roomsByLocation={roomsByLocation}
        ensureRooms={ensureRooms}
        onClose={() => setFormModal(null)}
        onSuccess={handleFormSuccess}
      />

      <LifecycleConfirmDialog
        open={deleteTarget !== null}
        title="Hapus alias ini?"
        tone="danger"
        confirmLabel="Hapus"
        busy={deleteBusy}
        error={deleteError}
        onConfirm={confirmDelete}
        onClose={() => !deleteBusy && setDeleteTarget(null)}
      >
        {deleteTarget && (
          <>
            <p>
              <span className="font-medium text-gray-900">{deleteTarget.raw_value}</span>{' '}
              → {deleteTarget.room?.name} ({deleteTarget.location?.name})
            </p>
            <p className="text-xs text-gray-500">
              Menghapus alias ini tidak memengaruhi aset atau ruangan yang ada — hanya
              memutus pemetaan teks Excel ini untuk import berikutnya.
            </p>
          </>
        )}
      </LifecycleConfirmDialog>
    </div>
  );
}
