import { useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';
import { controlClass } from '../../lib/assetFields';

const ROLE_OPTIONS = [
  { value: 'admin', label: 'Admin' },
  { value: 'operator', label: 'Operator' },
  { value: 'viewer', label: 'Viewer' },
];

/**
 * Create/edit user modal (Tahap 6.4), one component for both via `mode`
 * (mirrors `AssetForm`'s own `mode="create"|"edit"` convention). Password is
 * deliberately NOT a field here in edit mode — resetting it is a separate,
 * explicit dialog ({@see ResetPasswordDialog}) so saving a name/role change
 * can never accidentally touch the password.
 *
 * Self-protection is mirrored client-side (role locked to Admin, status
 * locked to Aktif when editing your own account) purely as a UX convenience —
 * same "UI-only helper, backend stays authoritative" philosophy as every
 * other role-based UI check in this app; the backend's own validation is what
 * actually enforces it (tested independently).
 */
export default function UserFormModal({ open, mode, user, currentUserId, onClose, onSuccess }) {
  const isEdit = mode === 'edit';
  const isSelf = isEdit && user && currentUserId === user.id;

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState('viewer');
  const [isActive, setIsActive] = useState(true);
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setName(isEdit ? (user?.name ?? '') : '');
    setEmail(isEdit ? (user?.email ?? '') : '');
    setRole(isEdit ? (user?.role ?? 'viewer') : 'viewer');
    setIsActive(isEdit ? (user?.is_active ?? true) : true);
    setPassword('');
    setPasswordConfirmation('');
    setError('');
    setFieldErrors({});
  }, [open, isEdit, user]);

  if (!open) return null;

  const canSubmit =
    !busy &&
    name.trim() !== '' &&
    email.trim() !== '' &&
    (isEdit || (password !== '' && passwordConfirmation !== ''));

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError('');
    setFieldErrors({});

    const payload = isEdit
      ? { name: name.trim(), email: email.trim(), role, is_active: isActive }
      : {
          name: name.trim(),
          email: email.trim(),
          role,
          is_active: isActive,
          password,
          password_confirmation: passwordConfirmation,
        };

    try {
      const res = isEdit
        ? await api.put(`/api/users/${user.id}`, payload)
        : await api.post('/api/users', payload);
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
          setError('Pengguna tidak ditemukan. Mungkin sudah dihapus.');
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
      title={isEdit ? 'Edit Pengguna' : 'Tambah Pengguna'}
      confirmLabel={isEdit ? 'Simpan Perubahan' : 'Buat Pengguna'}
      confirmDisabled={!canSubmit}
      busy={busy}
      error={error}
      onConfirm={handleSubmit}
      onClose={onClose}
    >
      <div className="space-y-3 text-left">
        <div>
          <label htmlFor="user-name" className="mb-1 block text-xs font-medium text-gray-600">
            Nama
          </label>
          <input
            id="user-name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={controlClass(fieldErrors.name)}
          />
          {fieldErrors.name && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.name}</p>}
        </div>

        <div>
          <label htmlFor="user-email" className="mb-1 block text-xs font-medium text-gray-600">
            Email
          </label>
          <input
            id="user-email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className={controlClass(fieldErrors.email)}
          />
          {fieldErrors.email && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.email}</p>}
        </div>

        <div>
          <label htmlFor="user-role" className="mb-1 block text-xs font-medium text-gray-600">
            Role
          </label>
          <select
            id="user-role"
            value={role}
            onChange={(e) => setRole(e.target.value)}
            disabled={isSelf}
            className={controlClass(fieldErrors.role)}
          >
            {ROLE_OPTIONS.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </select>
          {fieldErrors.role && <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.role}</p>}
          {isSelf && (
            <p className="mt-1 text-xs text-gray-400">
              Anda tidak dapat mengubah role akun Anda sendiri.
            </p>
          )}
        </div>

        <div>
          <span className="mb-1 block text-xs font-medium text-gray-600">Status</span>
          <div className="flex gap-4 text-sm text-gray-700">
            <label className="flex items-center gap-1.5">
              <input
                type="radio"
                name="user-status"
                checked={isActive === true}
                disabled={isSelf}
                onChange={() => setIsActive(true)}
                className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
              />
              Aktif
            </label>
            <label className="flex items-center gap-1.5">
              <input
                type="radio"
                name="user-status"
                checked={isActive === false}
                disabled={isSelf}
                onChange={() => setIsActive(false)}
                className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
              />
              Nonaktif
            </label>
          </div>
          {fieldErrors.is_active && (
            <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.is_active}</p>
          )}
          {isSelf && (
            <p className="mt-1 text-xs text-gray-400">
              Anda tidak dapat menonaktifkan akun Anda sendiri.
            </p>
          )}
        </div>

        {!isEdit && (
          <>
            <div>
              <label htmlFor="user-password" className="mb-1 block text-xs font-medium text-gray-600">
                Password
              </label>
              <input
                id="user-password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="new-password"
                className={controlClass(fieldErrors.password)}
              />
            </div>
            <div>
              <label htmlFor="user-password-confirmation" className="mb-1 block text-xs font-medium text-gray-600">
                Konfirmasi Password
              </label>
              <input
                id="user-password-confirmation"
                type="password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                autoComplete="new-password"
                className={controlClass(fieldErrors.password)}
              />
              {fieldErrors.password && (
                <p className="mt-1 text-xs text-red-600" role="alert">{fieldErrors.password}</p>
              )}
            </div>
          </>
        )}
      </div>
    </LifecycleConfirmDialog>
  );
}
