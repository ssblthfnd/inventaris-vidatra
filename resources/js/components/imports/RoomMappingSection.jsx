import { useEffect, useState } from 'react';

import { api, ApiError } from '../../lib/api';
import { getRoomMappings, resolveRoomMapping } from '../../lib/imports';
import { useMasterData } from '../../lib/useMasterData';

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
 */

function extractErrorMessage(e) {
  if (e instanceof ApiError) return e.message || 'Terjadi kesalahan. Coba lagi.';
  return 'Terjadi kesalahan tak terduga. Coba lagi.';
}

function RoomMappingGroupCard({ group, locationName, batchId, onResolved }) {
  const { roomsByLocation, ensureRooms } = useMasterData();
  const [selectedRoomId, setSelectedRoomId] = useState('');
  const [saveAsAlias, setSaveAsAlias] = useState(false);
  const [creatingRoom, setCreatingRoom] = useState(false);
  const [newRoomName, setNewRoomName] = useState('');
  const [newRoomPic, setNewRoomPic] = useState('');
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [extraRooms, setExtraRooms] = useState([]); // rooms created THIS session, not yet in the shared master-data cache
  const [applyBusy, setApplyBusy] = useState(false);
  const [applyError, setApplyError] = useState('');
  const [resolved, setResolved] = useState(null); // { roomName, aliasCreated, aliasAlreadyExisted } | null

  useEffect(() => {
    ensureRooms([group.location_code]);
  }, [group.location_code, ensureRooms]);

  const availableRooms = [...(roomsByLocation[group.location_code] ?? []), ...extraRooms];
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
        setExtraRooms((current) => [...current, room]);
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
        saveAsAlias,
      });
      const data = res?.data;
      setResolved({
        roomName: data?.room?.name ?? selectedRoom?.name ?? '',
        aliasCreated: Boolean(data?.alias_created),
        aliasAlreadyExisted: Boolean(data?.alias_already_existed),
      });
      onResolved?.();
    } catch (e) {
      setApplyError(extractErrorMessage(e));
    } finally {
      setApplyBusy(false);
    }
  };

  if (resolved) {
    return (
      <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm">
        <p className="text-gray-500">{locationName}</p>
        <p className="mt-0.5 text-gray-900">
          &quot;{group.raw_value}&quot; → <span className="font-medium">{resolved.roomName}</span>
        </p>
        <p className="mt-1 text-emerald-800">
          ✓ {group.affected_rows} aset akan dipetakan
          {resolved.aliasCreated && ' · alias permanen dibuat'}
          {resolved.aliasAlreadyExisted && ' · alias permanen sudah ada sebelumnya'}
        </p>
      </div>
    );
  }

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
        <button
          type="button"
          onClick={() => setCreatingRoom((v) => !v)}
          disabled={applyBusy}
          className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-gray-400 disabled:opacity-60"
        >
          {creatingRoom ? 'Batal' : '+ Tambah Ruangan'}
        </button>
      </div>

      {creatingRoom && (
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

export default function RoomMappingSection({ batchId, onResolved }) {
  const { locations } = useMasterData();
  const [groups, setGroups] = useState([]);
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');

  const load = () => {
    setPhase('loading');
    setError('');
    getRoomMappings(batchId)
      .then((res) => {
        setGroups(res?.data ?? []);
        setPhase('ready');
      })
      .catch((e) => {
        setError(extractErrorMessage(e));
        setPhase('error');
      });
  };

  useEffect(() => {
    if (batchId) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [batchId]);

  const locationName = (code) => locations.find((l) => l.code === code)?.name ?? code;

  const handleGroupResolved = () => {
    onResolved?.();
    // re-list so a group whose rows are now fully resolved disappears, and
    // any remaining groups reflect the current state — cheap (single small
    // GET) and avoids the resolved card silently going stale.
    load();
  };

  if (phase === 'loading') {
    return (
      <div className="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-400">
        Memuat pemetaan ruangan…
      </div>
    );
  }

  if (phase === 'error') {
    return (
      <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        {error}
        <button type="button" onClick={load} className="ml-2 font-medium underline">
          Coba lagi
        </button>
      </div>
    );
  }

  if (groups.length === 0) {
    return null; // nothing unmapped — no need to show an empty section
  }

  return (
    <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
      <h2 className="text-sm font-semibold text-gray-800">Pemetaan Ruangan</h2>
      <p className="mt-0.5 text-xs text-gray-500">
        Nilai ruangan berikut belum dikenali. Aset tetap bisa dipromosikan tanpa dipetakan (ruangan
        tetap kosong), atau petakan dulu ke ruangan yang sesuai.
      </p>
      <div className="mt-3 space-y-3">
        {groups.map((g) => (
          <RoomMappingGroupCard
            key={`${g.location_code}${g.match_key}`}
            group={g}
            locationName={locationName(g.location_code)}
            batchId={batchId}
            onResolved={handleGroupResolved}
          />
        ))}
      </div>
    </section>
  );
}
