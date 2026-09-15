import { useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';
import { controlClass } from '../../lib/assetFields';

/**
 * Create/edit subcategory modal (Tahap 6.8.4), mirrors `RoomFormModal`'s own
 * shape (a parent selector on create, read-only parent display on edit).
 *
 * `activeCategories` (passed by `SubcategoriesPanel`) is deliberately
 * ACTIVE-ONLY — a new subcategory can never be created under an inactive
 * category (matches the backend's own `StoreSubcategoryRequest` rule).
 */
export default function SubcategoryFormModal({ open, mode, subcategory, activeCategories, onClose, onSuccess }) {
  const isEdit = mode === 'edit';

  const [categoryCode, setCategoryCode] = useState('');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [guideName, setGuideName] = useState('');
  const [isActive, setIsActive] = useState(true);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setCategoryCode(isEdit ? (subcategory?.category?.code ?? '') : '');
    setCode(isEdit ? (subcategory?.code ?? '') : '');
    setName(isEdit ? (subcategory?.name ?? '') : '');
    setGuideName(isEdit ? (subcategory?.guide_name ?? '') : '');
    setIsActive(isEdit ? (subcategory?.is_active ?? true) : true);
    setError('');
    setFieldErrors({});
  }, [open, isEdit, subcategory]);

  if (!open) return null;

  const canSubmit =
    !busy && name.trim() !== '' && (isEdit || (categoryCode !== '' && code.trim() !== ''));

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError('');
    setFieldErrors({});

    const payload = isEdit
      ? { name: name.trim(), guide_name: guideName.trim(), is_active: isActive }
      : { category_code: categoryCode, code: code.trim(), name: name.trim(), guide_name: guideName.trim() };

    try {
      const res = isEdit
        ? await api.patch(`/api/subcategories/${subcategory.id}`, payload)
        : await api.post('/api/subcategories', payload);
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
          setError('Subkategori tidak ditemukan. Mungkin sudah diubah oleh pengguna lain.');
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
      title={isEdit ? 'Edit Subkategori' : 'Tambah Subkategori'}
      confirmLabel={isEdit ? 'Simpan Perubahan' : 'Buat Subkategori'}
      confirmDisabled={!canSubmit}
      busy={busy}
      error={error}
      onConfirm={handleSubmit}
      onClose={onClose}
    >
      <div className="space-y-3 text-left">
        <div>
          <label htmlFor="subcategory-category" className="mb-1 block text-xs font-medium text-gray-600">
            Kategori
          </label>
          {isEdit ? (
            <p className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
              {subcategory?.category?.name}
            </p>
          ) : (
            <select
              id="subcategory-category"
              value={categoryCode}
              onChange={(e) => setCategoryCode(e.target.value)}
              className={controlClass(fieldErrors.category_code)}
            >
              <option value="">Pilih kategori…</option>
              {activeCategories.map((c) => (
                <option key={c.code} value={c.code}>
                  {c.name}
                </option>
              ))}
            </select>
          )}
          {fieldErrors.category_code && (
            <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.category_code}</p>
          )}
          {isEdit && <p className="mt-1 text-xs text-gray-400">Kategori subkategori tidak dapat diubah.</p>}
        </div>

        <div>
          <label htmlFor="subcategory-code" className="mb-1 block text-xs font-medium text-gray-600">
            Kode Subkategori
          </label>
          {isEdit ? (
            <p className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
              {subcategory?.code}
            </p>
          ) : (
            <input
              id="subcategory-code"
              type="text"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              maxLength={3}
              placeholder="mis. 001"
              className={controlClass(fieldErrors.code)}
            />
          )}
          {fieldErrors.code && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.code}</p>}
          {isEdit ? (
            <p className="mt-1 text-xs text-gray-400">Kode subkategori tidak dapat diubah.</p>
          ) : (
            <p className="mt-1 text-xs text-gray-400">Tepat 3 karakter. Tidak dapat diubah setelah dibuat.</p>
          )}
        </div>

        <div>
          <label htmlFor="subcategory-name" className="mb-1 block text-xs font-medium text-gray-600">
            Nama Subkategori
          </label>
          <input
            id="subcategory-name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={controlClass(fieldErrors.name)}
          />
          {fieldErrors.name && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.name}</p>}
        </div>

        <div>
          <label htmlFor="subcategory-guide-name" className="mb-1 block text-xs font-medium text-gray-600">
            Nama Panduan
          </label>
          <input
            id="subcategory-guide-name"
            type="text"
            value={guideName}
            onChange={(e) => setGuideName(e.target.value)}
            className={controlClass(fieldErrors.guide_name)}
          />
          {fieldErrors.guide_name && (
            <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.guide_name}</p>
          )}
          <p className="mt-1 text-xs text-gray-400">Opsional — nama versi Panduan (historis), jika berbeda dari nama di atas.</p>
        </div>

        {isEdit && (
          <div>
            <span className="mb-1 block text-xs font-medium text-gray-600">Status</span>
            <div className="flex gap-4 text-sm text-gray-700">
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="subcategory-status"
                  checked={isActive === true}
                  onChange={() => setIsActive(true)}
                  className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
                />
                Aktif
              </label>
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="subcategory-status"
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
