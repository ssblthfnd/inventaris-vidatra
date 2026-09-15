import { useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';
import { controlClass } from '../../lib/assetFields';

/**
 * Create/edit location modal (Tahap 6.8.3), mirrors `RoomFormModal`'s own
 * `mode="create"|"edit"` shape.
 *
 * `code` is only ever editable on create — shown read-only on edit, since
 * the backend rejects a `code` change outright (it is baked into every
 * historical `asset_code` copied onto the `assets` table).
 */
export default function LocationFormModal({ open, mode, location, onClose, onSuccess }) {
  const isEdit = mode === 'edit';

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [alias, setAlias] = useState('');
  const [isActive, setIsActive] = useState(true);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setCode(isEdit ? (location?.code ?? '') : '');
    setName(isEdit ? (location?.name ?? '') : '');
    setAlias(isEdit ? (location?.alias ?? '') : '');
    setIsActive(isEdit ? (location?.is_active ?? true) : true);
    setError('');
    setFieldErrors({});
  }, [open, isEdit, location]);

  if (!open) return null;

  const canSubmit = !busy && name.trim() !== '' && (isEdit || code.trim() !== '');

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError('');
    setFieldErrors({});

    const payload = isEdit
      ? { name: name.trim(), alias: alias.trim(), is_active: isActive }
      : { code: code.trim(), name: name.trim(), alias: alias.trim() };

    try {
      const res = isEdit
        ? await api.patch(`/api/locations/${location.code}`, payload)
        : await api.post('/api/locations', payload);
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
          setError('Lokasi tidak ditemukan. Mungkin sudah diubah oleh pengguna lain.');
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
      title={isEdit ? 'Edit Lokasi' : 'Tambah Lokasi'}
      confirmLabel={isEdit ? 'Simpan Perubahan' : 'Buat Lokasi'}
      confirmDisabled={!canSubmit}
      busy={busy}
      error={error}
      onConfirm={handleSubmit}
      onClose={onClose}
    >
      <div className="space-y-3 text-left">
        <div>
          <label htmlFor="location-code" className="mb-1 block text-xs font-medium text-gray-600">
            Kode Lokasi
          </label>
          {isEdit ? (
            <p className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
              {location?.code}
            </p>
          ) : (
            <input
              id="location-code"
              type="text"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              maxLength={2}
              placeholder="mis. 05"
              className={controlClass(fieldErrors.code)}
            />
          )}
          {fieldErrors.code && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.code}</p>}
          {isEdit ? (
            <p className="mt-1 text-xs text-gray-400">Kode lokasi tidak dapat diubah.</p>
          ) : (
            <p className="mt-1 text-xs text-gray-400">Tepat 2 karakter, mis. "05". Tidak dapat diubah setelah dibuat.</p>
          )}
        </div>

        <div>
          <label htmlFor="location-name" className="mb-1 block text-xs font-medium text-gray-600">
            Nama Lokasi
          </label>
          <input
            id="location-name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={controlClass(fieldErrors.name)}
          />
          {fieldErrors.name && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.name}</p>}
        </div>

        <div>
          <label htmlFor="location-alias" className="mb-1 block text-xs font-medium text-gray-600">
            Alias
          </label>
          <input
            id="location-alias"
            type="text"
            value={alias}
            onChange={(e) => setAlias(e.target.value)}
            className={controlClass(fieldErrors.alias)}
          />
          {fieldErrors.alias && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.alias}</p>}
        </div>

        {isEdit && (
          <div>
            <span className="mb-1 block text-xs font-medium text-gray-600">Status</span>
            <div className="flex gap-4 text-sm text-gray-700">
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="location-status"
                  checked={isActive === true}
                  onChange={() => setIsActive(true)}
                  className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                Aktif
              </label>
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="location-status"
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
