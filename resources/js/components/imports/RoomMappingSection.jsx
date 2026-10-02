import { useEffect, useMemo, useState } from 'react';

import { useAuth } from '../../auth/AuthContext';
import { api, ApiError } from '../../lib/api';
import { resolveRoomMapping, roomMappingGroupKey } from '../../lib/imports';

/**
 * Tahap 6.9 R9.2 — Import Room Mapping Resolution.
 *
 * Shown on the Imports page once a batch is loaded, right above the row
 * table (matches the existing "Import Result -> Room Mapping -> rows"
 * layout this stage's UX brief asked for). Lists every DISTINCT
 * (location, normalized raw room value) group this batch still has
 * unmapped, and lets an operator/admin/unit_admin resolve each one to a
 * room — either for this batch only, or permanently as a new alias.
 *
 * The backend (`RoomMappingResolver`) is the actual authority on scope,
 * duplicate aliases, and cross-location rejection — this component only
 * renders what the API returns and surfaces its errors; it never decides
 * on its own what a user "should" be allowed to map.
 *
 * Stage 6.9 R9.3 — per-ACTION visibility from `/api/me` abilities, never
 * hiding the section itself (anyone who can import can map to an existing
 * room): "+ Tambah Ruangan" needs `rooms.manage` (operator does not hold
 * it), and "Simpan sebagai alias" needs `roomAliases.resolve`. Both are
 * still enforced server-side (`POST /api/rooms`, `RoomMappingResolver`).
 *
 * Tahap 6.9 R9.4-A1 — state ownership:
 *   - SERVER state (the groups list, its load phase/error, and the feedback
 *     for groups resolved in this batch) is owned by `Imports.jsx`, which
 *     fetches it in parallel with the batch and rows and refreshes it in the
 *     BACKGROUND after a resolve or a promotion — this section never switches
 *     back to a loading placeholder once it has groups, so no card is ever
 *     unmounted just because a different group changed (R9.4-04/-05).
 *   - Master data (locations, rooms) comes from the page's single
 *     `useMasterData()` instance via props, so N cards share one cache
 *     instead of N (R9.4-03). Rooms created here are kept at section level so
 *     every card for that location sees them.
 *   - Each card owns only its own unsaved DRAFT (selected room, alias choice,
 *     new-room form, errors), keyed by `roomMappingGroupKey()` — the same key
 *     R9.2 used — so it survives every refresh of unrelated groups.
 */

function extractErrorMessage(e) {
  if (e instanceof ApiError) return e.message || 'Terjadi kesalahan. Coba lagi.';
  return 'Terjadi kesalahan tak terduga. Coba lagi.';
}

function RoomMappingGroupCard({ group, locationName, batchId, availableRooms, onRoomCreated, onResolved }) {
  const { canManageRooms, canResolveRoomAliases } = useAuth();
  const [selectedRoomId, setSelectedRoomId] = useState('');
  const [saveAsAlias, setSaveAsAlias] = useState(false);
  const [creatingRoom, setCreatingRoom] = useState(false);
  const [newRoomName, setNewRoomName] = useState('');
  const [newRoomPic, setNewRoomPic] = useState('');
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [applyBusy, setApplyBusy] = useState(false);
  const [applyError, setApplyError] = useState('');

  const selectedRoom = availableRooms.find((r) => String(r.id) === String(selectedRoomId));

  const handleCreateRoom = async () => {
    const name = newRoomName.trim();
    if (!name) {
      setCreateError('Nama ruangan wajib diisi.');
      return;
    }
    setCreateBusy(true);
    setCreateError('');
    try {
      const res = await api.post('/api/rooms', {
        location_code: group.location_code,
        name,
        pic: newRoomPic.trim() || null,
      });
      const room = res?.data;
      if (room) {
        onRoomCreated(group.location_code, room);
        setSelectedRoomId(String(room.id));
      }
      setCreatingRoom(false);
      setNewRoomName('');
      setNewRoomPic('');
    } catch (e) {
      setCreateError(extractErrorMessage(e));
    } finally {
      setCreateBusy(false);
    }
  };

  const handleApply = async () => {
    if (!selectedRoomId) return;
    setApplyBusy(true);
    setApplyError('');
    try {
      const res = await resolveRoomMapping(batchId, {
        locationCode: group.location_code,
        rawValue: group.raw_value,
        roomId: Number(selectedRoomId),
        saveAsAlias: canResolveRoomAliases && saveAsAlias,
      });
      // The parent records the feedback and drops this group (this card then
      // unmounts); every OTHER card keeps its own draft untouched.
      onResolved(group, res?.data ?? null, selectedRoom?.name ?? '');
    } catch (e) {
      setApplyError(extractErrorMessage(e));
    } finally {
      setApplyBusy(false);
    }
  };

  return (
    <div className="rounded-lg border border-gray-200 bg-white px-4 py-3">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <div>
          <p className="text-xs text-gray-500">{locationName}</p>
          <p className="text-sm font-medium text-gray-900">&quot;{group.raw_value}&quot;</p>
        </div>
        <p className="text-xs text-gray-500">{group.affected_rows} aset · Belum dipetakan</p>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <select
          value={selectedRoomId}
          onChange={(e) => setSelectedRoomId(e.target.value)}
          disabled={applyBusy}
          className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 disabled:bg-gray-50"
        >
          <option value="">Pilih ruangan existing…</option>
          {availableRooms.map((r) => (
            <option key={r.id} value={r.id}>
              {r.name}
            </option>
          ))}
        </select>
        {canManageRooms && (
          <button
            type="button"
            onClick={() => setCreatingRoom((v) => !v)}
            disabled={applyBusy}
            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-gray-400 disabled:opacity-60"
          >
            {creatingRoom ? 'Batal' : '+ Tambah Ruangan'}
          </button>
        )}
      </div>

      {canManageRooms && creatingRoom && (
        <div className="mt-3 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
          <div className="flex flex-wrap gap-2">
            <input
              type="text"
              placeholder="Nama ruangan"
              value={newRoomName}
              onChange={(e) => setNewRoomName(e.target.value)}
              disabled={createBusy}
              className="min-w-[10rem] flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900"
            />
            <input
              type="text"
              placeholder="PIC (opsional)"
              value={newRoomPic}
              onChange={(e) => setNewRoomPic(e.target.value)}
              disabled={createBusy}
              className="min-w-[8rem] flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900"
            />
            <button
              type="button"
              onClick={handleCreateRoom}
              disabled={createBusy}
              className="rounded-lg bg-gray-900 px-3.5 py-1.5 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {createBusy ? 'Menyimpan…' : 'Buat Ruangan'}
            </button>
          </div>
          {createError && <p className="text-sm text-red-600">{createError}</p>}
        </div>
      )}

      {canResolveRoomAliases ? (
        <div className="mt-3 flex flex-col gap-1.5 text-sm text-gray-700">
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name={`save-as-alias-${group.location_code}-${group.match_key}`}
              checked={!saveAsAlias}
              onChange={() => setSaveAsAlias(false)}
              disabled={applyBusy}
            />
            Gunakan untuk import ini saja
          </label>
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name={`save-as-alias-${group.location_code}-${group.match_key}`}
              checked={saveAsAlias}
              onChange={() => setSaveAsAlias(true)}
              disabled={applyBusy}
            />
            Simpan sebagai alias untuk import berikutnya
          </label>
        </div>
      ) : (
        <p className="mt-3 text-xs text-gray-500">Pemetaan hanya berlaku untuk import ini.</p>
      )}

      {selectedRoom && (
        <p className="mt-2 text-xs text-gray-500">
          Pratinjau: &quot;{group.raw_value}&quot; → {selectedRoom.name} · {group.affected_rows} aset terpengaruh
        </p>
      )}

      {applyError && <p className="mt-2 text-sm text-red-600">{applyError}</p>}

      <div className="mt-3">
        <button
          type="button"
          onClick={handleApply}
          disabled={!selectedRoomId || applyBusy}
          className="rounded-lg bg-gray-900 px-3.5 py-1.5 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {applyBusy ? 'Menerapkan…' : 'Terapkan'}
        </button>
      </div>
    </div>
  );
}

/** Feedback for one group resolved in this batch — built by Imports.jsx from the actual resolve response. */
function ResolvedNotice({ notice, locationName }) {
  return (
    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm">
      <p className="text-gray-500">{locationName}</p>
      <p className="mt-0.5 text-gray-900">
        &quot;{notice.rawValue}&quot; → <span className="font-medium">{notice.roomName}</span>
      </p>
      <p className="mt-1 text-emerald-800">
        ✓ {notice.updatedRows} baris dipetakan
        {notice.aliasCreated && ' · alias permanen dibuat'}
        {notice.aliasAlreadyExisted && ' · alias permanen sudah ada sebelumnya'}
      </p>
    </div>
  );
}

export default function RoomMappingSection({
  batchId,
  groups,
  phase, // loading | ready | error — initial load only; background refreshes never return to 'loading'
  error,
  onRetry,
  notices,
  locations,
  roomsByLocation,
  ensureRooms,
  onResolved,
}) {
  // Rooms created from any card in this batch, by location — merged into every
  // card of that location (the shared master-data cache isn't refetched for them).
  const [createdRooms, setCreatedRooms] = useState({});

  // One rooms request per DISTINCT location, not one per card; ensureRooms() also
  // dedupes against what the page's master-data cache already holds or is fetching.
  const locationCodesKey = useMemo(
    () => [...new Set(groups.map((g) => g.location_code))].sort().join(','),
    [groups],
  );
  useEffect(() => {
    if (locationCodesKey) ensureRooms(locationCodesKey.split(','));
  }, [locationCodesKey, ensureRooms]);

  const locationName = (code) => locations.find((l) => l.code === code)?.name ?? code;

  const roomsFor = (code) => {
    const merged = [...(roomsByLocation[code] ?? []), ...(createdRooms[code] ?? [])];
    return merged.filter((room, i) => merged.findIndex((r) => r.id === room.id) === i);
  };

  // keyed by the creating card's own group location (RoomResource nests it as
  // `location.code`; the card already knows it, so don't depend on that shape)
  const handleRoomCreated = (locationCode, room) => {
    setCreatedRooms((current) => ({
      ...current,
      [locationCode]: [...(current[locationCode] ?? []), room],
    }));
  };

  if (phase === 'loading') {
    return (
      <div className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-400">
        Memuat pemetaan ruangan…
      </div>
    );
  }

  if (phase === 'error' && groups.length === 0) {
    return (
      <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        {error}
        <button type="button" onClick={onRetry} className="ml-2 font-medium underline">
          Coba lagi
        </button>
      </div>
    );
  }

  if (groups.length === 0 && notices.length === 0) {
    return null; // nothing unmapped and nothing resolved in this batch — no section needed
  }

  return (
    <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
      <h2 className="text-sm font-semibold text-gray-800">Pemetaan Ruangan</h2>
      <p className="mt-0.5 text-xs text-gray-500">
        {groups.length > 0
          ? 'Nilai ruangan berikut belum dikenali. Aset tetap bisa dipromosikan tanpa dipetakan (ruangan tetap kosong), atau petakan dulu ke ruangan yang sesuai.'
          : 'Semua nilai ruangan yang belum dikenali pada batch ini sudah dipetakan.'}
      </p>

      {error && (
        <p className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
          <button type="button" onClick={onRetry} className="ml-2 font-medium underline">
            Coba lagi
          </button>
        </p>
      )}

      <div className="mt-3 space-y-3">
        {notices.map((n) => (
          <ResolvedNotice key={`resolved-${n.key}`} notice={n} locationName={locationName(n.locationCode)} />
        ))}
        {groups.map((g) => (
          <RoomMappingGroupCard
            key={roomMappingGroupKey(g)}
            group={g}
            locationName={locationName(g.location_code)}
            batchId={batchId}
            availableRooms={roomsFor(g.location_code)}
            onRoomCreated={handleRoomCreated}
            onResolved={onResolved}
          />
        ))}
      </div>
    </section>
  );
}
