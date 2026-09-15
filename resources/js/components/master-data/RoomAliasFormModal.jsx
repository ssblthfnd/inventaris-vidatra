import { useEffect, useMemo, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';
import { controlClass } from '../../lib/assetFields';

/**
 * Create/edit room alias modal (Tahap 6.8.2), mirrors `RoomFormModal`'s own
 * `mode="create"|"edit"` shape.
 *
 * Location is only selectable on create (an alias's location is immutable,
 * same reasoning as a room's own — shown read-only on edit).
 *
 * Room options come from `useMasterData().roomsByLocation` (active-only, via
 * the existing `ensureRooms()`), matching every other room dropdown in the
 * app. On edit, if the alias's CURRENT room is inactive (allowed — see
 * RoomAliasController's docblock) it is deliberately merged into the option
 * list so the form never silently loses the existing selection — no new
 * endpoint needed for this, the alias's own embedded `room` object already
 * carries what's needed.
 *
 * `source` is deliberately not exposed as a form field — every alias created
 * or edited through this UI is definitionally `manual` (the `tahap1_seed`
 * value only ever describes historical data), so there is nothing
 * meaningful for an operator to choose here; the backend already defaults
 * it correctly.
 */
export default function RoomAliasFormModal({
  open,
  mode,
  alias,
  locations,
  roomsByLocation,
  ensureRooms,
  onClose,
  onSuccess,
}) {
  const isEdit = mode === 'edit';

  const [locationCode, setLocationCode] = useState('');
  const [rawValue, setRawValue] = useState('');
  const [roomId, setRoomId] = useState('');
  const [notes, setNotes] = useState('');

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    const initialLocation = isEdit ? (alias?.location?.code ?? '') : '';
    setLocationCode(initialLocation);
    setRawValue(isEdit ? (alias?.raw_value ?? '') : '');
    setRoomId(isEdit ? String(alias?.room?.id ?? '') : '');
    setNotes(isEdit ? (alias?.notes ?? '') : '');
    setError('');
    setFieldErrors({});
    if (initialLocation) ensureRooms([initialLocation]);
  }, [open, isEdit, alias, ensureRooms]);

  const roomOptions = useMemo(() => {
    const active = roomsByLocation[locationCode] ?? [];
    if (isEdit && alias?.room?.id && !active.some((r) => r.id === alias.room.id)) {
      // the alias's current room is inactive — keep it selectable so editing
      // an existing alias never shows a blank/invalid room field.
      return [...active, { id: alias.room.id, name: alias.room.name, is_active: alias.room.is_active }];
    }
    return active;
  }, [roomsByLocation, locationCode, isEdit, alias]);

  if (!open) return null;

  const canSubmit =
    !busy && rawValue.trim() !== '' && roomId !== '' && (isEdit || locationCode !== '');

  const handleLocationChange = (value) => {
    setLocationCode(value);
    setRoomId('');
    if (value) ensureRooms([value]);
  };

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError('');
    setFieldErrors({});

    const payload = isEdit
      ? { raw_value: rawValue.trim(), room_id: Number(roomId), notes: notes.trim() }
      : { location_code: locationCode, raw_value: rawValue.trim(), room_id: Number(roomId), notes: notes.trim() };

    try {
      const res = isEdit
        ? await api.patch(`/api/room-aliases/${alias.id}`, payload)
        : await api.post('/api/room-aliases', payload);
      setBusy(false);
      onSuccess(res, isEdit ? 'update' : 'create');
    } catch (e) {
      setBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 403) {
          setError('Anda tidak memiliki izin untuk melakukan aksi ini.');
          return;
        }
        if (e.status === 404) {
          setError('Alias tidak ditemukan. Mungkin sudah diubah oleh pengguna lain.');
          return;
        }
        if (e.status === 422) {
          const mapped = {};
          for (const [key, msgs] of Object.entries(e.errors ?? {})) {
            mapped[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
          }
          setFieldErrors(mapped);
          setError(e.message || 'Periksa kembali data yang diisi.');
          return;
        }
        if (e.status >= 500) {
          setError('Terjadi kesalahan pada server. Tidak ada perubahan yang disimpan.');
          return;
        }
        setError(e.message || 'Gagal menyimpan. Coba lagi.');
        return;
      }
      setError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  return (
    <LifecycleConfirmDialog
      open={open}
      title={isEdit ? 'Edit Alias Ruangan' : 'Tambah Alias Ruangan'}
      confirmLabel={isEdit ? 'Simpan Perubahan' : 'Buat Alias'}
      confirmDisabled={!canSubmit}
      busy={busy}
      error={error}
      onConfirm={handleSubmit}
      onClose={onClose}
    >
      <div className="space-y-3 text-left">
        <div>
          <label htmlFor="alias-location" className="mb-1 block text-xs font-medium text-gray-600">
            Lokasi
          </label>
          {isEdit ? (
            <p className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
              {alias?.location?.name}
            </p>
          ) : (
            <select
              id="alias-location"
              value={locationCode}
              onChange={(e) => handleLocationChange(e.target.value)}
              className={controlClass(fieldErrors.location_code)}
            >
              <option value="">Pilih lokasi…</option>
              {locations.map((l) => (
                <option key={l.code} value={l.code}>
                  {l.name}
                </option>
              ))}
            </select>
          )}
          {fieldErrors.location_code && (
            <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.location_code}</p>
          )}
          {isEdit && <p className="mt-1 text-xs text-gray-400">Lokasi alias tidak dapat diubah.</p>}
        </div>

        <div>
          <label htmlFor="alias-raw-value" className="mb-1 block text-xs font-medium text-gray-600">
            Nilai Mentah (dari Excel)
          </label>
          <input
            id="alias-raw-value"
            type="text"
            value={rawValue}
            onChange={(e) => setRawValue(e.target.value)}
            className={controlClass(fieldErrors.raw_value)}
          />
          {fieldErrors.raw_value && (
            <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.raw_value}</p>
          )}
          <p className="mt-1 text-xs text-gray-400">
            Teks persis seperti yang muncul di kolom Ruangan pada file Excel.
          </p>
        </div>

        <div>
          <label htmlFor="alias-room" className="mb-1 block text-xs font-medium text-gray-600">
            Ruangan Tujuan
          </label>
          <select
            id="alias-room"
            value={roomId}
            onChange={(e) => setRoomId(e.target.value)}
            disabled={!isEdit && !locationCode}
            className={controlClass(fieldErrors.room_id)}
          >
            <option value="">Pilih ruangan…</option>
            {roomOptions.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
                {r.is_active === false ? ' (Nonaktif)' : ''}
              </option>
            ))}
          </select>
          {fieldErrors.room_id && (
            <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.room_id}</p>
          )}
          {!isEdit && !locationCode && (
            <p className="mt-1 text-xs text-gray-400">Pilih lokasi terlebih dahulu.</p>
          )}
        </div>

        <div>
          <label htmlFor="alias-notes" className="mb-1 block text-xs font-medium text-gray-600">
            Catatan
          </label>
          <textarea
            id="alias-notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            className={controlClass(fieldErrors.notes)}
          />
          {fieldErrors.notes && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.notes}</p>}
        </div>
      </div>
    </LifecycleConfirmDialog>
  );
}
