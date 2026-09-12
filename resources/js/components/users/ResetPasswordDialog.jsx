import { useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';
import { controlClass } from '../../lib/assetFields';

/**
 * Reset-password dialog (Tahap 6.4) — a deliberate, separate action from
 * editing a user (see `UserFormModal`'s own docblock). No email-based reset
 * flow exists in this app; this directly sets a new password, matching the
 * stage's explicit "minimum mechanism, no email reset infra" instruction.
 */
export default function ResetPasswordDialog({ open, user, onClose, onSuccess }) {
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setPassword('');
    setPasswordConfirmation('');
    setError('');
    setFieldErrors({});
  }, [open]);

  if (!open) return null;

  const canSubmit = !busy && password !== '' && passwordConfirmation !== '';

  const handleSubmit = async () => {
    if (!canSubmit || !user) return;
    setBusy(true);
    setError('');
    setFieldErrors({});

    try {
      const res = await api.post(`/api/users/${user.id}/reset-password`, {
        password,
        password_confirmation: passwordConfirmation,
      });
      setBusy(false);
      onSuccess(res);
    } catch (e) {
      setBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 403) {
          setError('Anda tidak memiliki izin untuk melakukan aksi ini.');
          return;
        }
        if (e.status === 404) {
          setError('Pengguna tidak ditemukan.');
          return;
        }
        if (e.status === 422) {
          const mapped = {};
          for (const [key, msgs] of Object.entries(e.errors ?? {})) {
            mapped[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
          }
          setFieldErrors(mapped);
          setError(e.message || 'Periksa kembali kata sandi yang diisi.');
          return;
        }
        if (e.status >= 500) {
          setError('Terjadi kesalahan pada server. Kata sandi belum diubah.');
          return;
        }
        setError(e.message || 'Gagal mereset kata sandi. Coba lagi.');
        return;
      }
      setError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  return (
    <LifecycleConfirmDialog
      open={open}
      title={`Reset Kata Sandi — ${user?.name ?? ''}`}
      confirmLabel="Reset Kata Sandi"
      confirmDisabled={!canSubmit}
      busy={busy}
      error={error}
      onConfirm={handleSubmit}
      onClose={onClose}
    >
      <p className="text-gray-600">
        Kata sandi baru berlaku segera setelah disimpan. Bagikan kata sandi ini kepada
        pengguna melalui jalur yang aman.
      </p>
      <div className="space-y-3 text-left">
        <div>
          <label htmlFor="reset-password" className="mb-1 block text-xs font-medium text-gray-600">
            Kata Sandi Baru
          </label>
          <input
            id="reset-password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="new-password"
            className={controlClass(fieldErrors.password)}
          />
        </div>
        <div>
          <label htmlFor="reset-password-confirmation" className="mb-1 block text-xs font-medium text-gray-600">
            Konfirmasi Kata Sandi
          </label>
          <input
            id="reset-password-confirmation"
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
      </div>
    </LifecycleConfirmDialog>
  );
}
