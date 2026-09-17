import { useCallback, useEffect, useState } from 'react';

import { useAuth } from '../auth/AuthContext';
import LifecycleConfirmDialog from '../components/LifecycleConfirmDialog';
import RoomFormModal from '../components/master-data/RoomFormModal';
import { api, ApiError } from '../lib/api';
import { CenteredState } from '../lib/assetFields';
import { useMasterData } from '../lib/useMasterData';

const SEARCH_DEBOUNCE_MS = 350;

/**
 * R7.1 — `/rooms`, a NEW dedicated entry point for `unit_admin`/`super_admin`
 * room management (Stage 6.9 R7's `rooms.manage` ability), reusing the
 * SCOPED per-location read endpoint (`GET /api/locations/{code}/rooms`,
 * R4/R7.1) rather than the legacy `can:admin`-only flat `GET /api/rooms`
 * `RoomsPanel.jsx` (inside `/master-data`) uses — neither `unit_admin` nor
 * `super_admin` can reach that one (confirmed by reading routes/api.php: it
 * was never migrated off the legacy Gate). Legacy `admin` already manages
 * rooms via `/master-data` and deliberately does NOT get this page too (see
 * `AppShell.jsx`'s nav `show` predicate) — one room-management UI per actor,
 * not two.
 *
 * For `unit_admin` the location is FIXED to their own assigned unit (no
 * selector at all — there is nothing else to pick). For `super_admin` (or
 * legacy `admin` navigating here directly by URL) every active location is
 * offered via `useMasterData()`'s UNSCOPED list (that hook only narrows
 * `locations` for a `unit_admin` actor) and exactly one is browsed at a
 * time, since the underlying endpoint is inherently per-location — there is
 * no flat "all locations, one page" call available to a non-admin actor
 * (see R7's own docblock on why `GET /api/rooms` stays `can:admin`-only).
 *
 * `?include_inactive=1` (R7.1) is what lets a deactivated room still show up
 * here at all, so "reactivate" is actually reachable — without it, a room a
 * unit_admin deactivated would vanish from every read path they have.
 *
 * Create/edit/deactivate/reactivate reuse `RoomFormModal` and
 * `LifecycleConfirmDialog` verbatim (same components `RoomsPanel.jsx`
 * uses) — both already just POST/PATCH `/api/rooms(/…)`, which R7 already
 * authorizes correctly per-actor; this page adds no new write logic.
 */
export default function Rooms() {
  const { canManageRooms, isUnitAdmin, locationCode: ownLocationCode } = useAuth();
  const { locations: globalLocations } = useMasterData();

  const [selectedLocation, setSelectedLocation] = useState('');
  const activeLocationCode = isUnitAdmin ? ownLocationCode : selectedLocation;

  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');

  const [result, setResult] = useState(null); // { data: [...] }
  const [phase, setPhase] = useState('idle'); // idle | loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success');

  const [formModal, setFormModal] = useState(null); // { mode: 'create'|'edit', room? }
  const [statusTarget, setStatusTarget] = useState(null);
  const [statusBusy, setStatusBusy] = useState(false);
  const [statusError, setStatusError] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setQ(qInput), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput]);

  const load = useCallback(() => {
    if (!activeLocationCode) {
      setResult(null);
      setPhase('idle');
      return undefined;
    }
    let alive = true;
    setPhase((p) => (result === null ? 'loading' : p));

    const params = new URLSearchParams({ include_inactive: '1' });
    if (q.trim()) params.set('q', q.trim());

    api
      .get(`/api/locations/${encodeURIComponent(activeLocationCode)}/rooms?${params.toString()}`)
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
  }, [activeLocationCode, q, retryKey]);

  useEffect(() => load(), [load]);

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  if (!canManageRooms) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk mengelola ruangan."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }

  const rooms = result?.data ?? [];
  // `RoomFormModal`'s create-mode selector — a single fixed option for
  // unit_admin (nothing else to choose), every active location for a global actor.
  const roomFormLocations = isUnitAdmin
    ? globalLocations.filter((l) => l.code === ownLocationCode)
    : globalLocations;

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
        if (e.status === 403) {
          setStatusError('Anda tidak memiliki izin untuk melakukan aksi ini.');
          return;
        }
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
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Ruangan</h1>
          <p className="mt-1 text-sm text-gray-500">
            {isUnitAdmin
              ? 'Kelola ruangan di unit Anda.'
              : 'Pilih unit untuk mengelola ruangannya.'}
          </p>
        </div>
        {activeLocationCode && (
          <button
            type="button"
            onClick={() => setFormModal({ mode: 'create' })}
            className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
          >
            <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Ruangan
          </button>
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

      <div className="flex flex-wrap gap-2">
        {/* unit_admin has exactly one location — no picker, just a fixed label */}
        {!isUnitAdmin && (
          <select
            value={selectedLocation}
            onChange={(e) => setSelectedLocation(e.target.value)}
            className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-900"
          >
            <option value="">Pilih unit…</option>
            {globalLocations.map((l) => (
              <option key={l.code} value={l.code}>
                {l.name}
              </option>
            ))}
          </select>
        )}
        {activeLocationCode && (
          <input
            type="search"
            value={qInput}
            onChange={(e) => setQInput(e.target.value)}
            placeholder="Cari nama ruangan…"
            className="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 sm:w-64"
          />
        )}
      </div>

      {!activeLocationCode ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">Pilih unit terlebih dahulu.</p>
        </div>
      ) : phase === 'error' ? (
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
          <p className="text-sm font-medium text-gray-700">Belum ada ruangan di unit ini.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2.5">Nama Ruangan</th>
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
        locations={roomFormLocations}
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
              <span className="font-medium text-gray-900">{statusTarget.name}</span>
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
