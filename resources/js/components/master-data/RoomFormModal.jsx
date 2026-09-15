import { useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';
import { controlClass } from '../../lib/assetFields';

/**
 * Create/edit room modal (Tahap 6.8.1), one component for both via `mode`
 * (mirrors `UserFormModal`'s own `mode="create"|"edit"` convention).
 *
 * Location is only selectable on create (from the existing active-only
 * `useMasterData().locations` list — never free text); on edit it is shown
 * read-only, since the backend rejects a `location_code` change outright.
 */
export default function RoomFormModal({ open, mode, room, locations, onClose, onSuccess }) {
  const isEdit = mode === 'edit';

  const [locationCode, setLocationCode] = useState('');
  const [name, setName] = useState('');
  const [pic, setPic] = useState('');
  const [notes, setNotes] = useState('');
  const [isActive, setIsActive] = useState(true);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setLocationCode(isEdit ? (room?.location?.code ?? '') : '');
    setName(isEdit ? (room?.name ?? '') : '');
    setPic(isEdit ? (room?.pic ?? '') : '');
    setNotes(isEdit ? (room?.notes ?? '') : '');
    setIsActive(isEdit ? (room?.is_active ?? true) : true);
    setError('');
    setFieldErrors({});
  }, [open, isEdit, room]);

  if (!open) return null;

  const canSubmit = !busy && name.trim() !== '' && (isEdit || locationCode !== '');

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError('');
    setFieldErrors({});

    const payload = isEdit
      ? { name: name.trim(), pic: pic.trim(), notes: notes.trim(), is_active: isActive }
      : { location_code: locationCode, name: name.trim(), pic: pic.trim(), notes: notes.trim() };

    try {
      const res = isEdit
        ? await api.patch(`/api/rooms/${room.id}`, payload)
        : await api.post('/api/rooms', payload);
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
          setError('Ruangan tidak ditemukan. Mungkin sudah diubah oleh pengguna lain.');
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
      title={isEdit ? 'Edit Ruangan' : 'Tambah Ruangan'}
      confirmLabel={isEdit ? 'Simpan Perubahan' : 'Buat Ruangan'}
      confirmDisabled={!canSubmit}
      busy={busy}
      error={error}
      onConfirm={handleSubmit}
      onClose={onClose}
    >
      <div className="space-y-3 text-left">
        <div>
          <label htmlFor="room-location" className="mb-1 block text-xs font-medium text-gray-600">
            Lokasi
          </label>
          {isEdit ? (
            <p className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
              {room?.location?.name}
            </p>
          ) : (
            <select
              id="room-location"
              value={locationCode}
              onChange={(e) => setLocationCode(e.target.value)}
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
          {isEdit && <p className="mt-1 text-xs text-gray-400">Lokasi ruangan tidak dapat diubah.</p>}
        </div>

        <div>
          <label htmlFor="room-name" className="mb-1 block text-xs font-medium text-gray-600">
            Nama Ruangan
          </label>
          <input
            id="room-name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={controlClass(fieldErrors.name)}
          />
          {fieldErrors.name && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.name}</p>}
        </div>

        <div>
          <label htmlFor="room-pic" className="mb-1 block text-xs font-medium text-gray-600">
            PIC
          </label>
          <input
            id="room-pic"
            type="text"
            value={pic}
            onChange={(e) => setPic(e.target.value)}
            className={controlClass(fieldErrors.pic)}
          />
          {fieldErrors.pic && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.pic}</p>}
        </div>

        <div>
          <label htmlFor="room-notes" className="mb-1 block text-xs font-medium text-gray-600">
            Catatan
          </label>
          <textarea
            id="room-notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            className={controlClass(fieldErrors.notes)}
          />
          {fieldErrors.notes && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.notes}</p>}
        </div>

        {isEdit && (
          <div>
            <span className="mb-1 block text-xs font-medium text-gray-600">Status</span>
            <div className="flex gap-4 text-sm text-gray-700">
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="room-status"
                  checked={isActive === true}
                  onChange={() => setIsActive(true)}
                  className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                Aktif
              </label>
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="room-status"
                  checked={isActive === false}
                  onChange={() => setIsActive(false)}
                  className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                Nonaktif
              </label>
            </div>
            {fieldErrors.is_active && (
              <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.is_active}</p>
            )}
          </div>
        )}
      </div>
    </LifecycleConfirmDialog>
  );
}
