import { useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';

/**
 * Batch soft-delete confirmation (Tahap 5.8.7) — one atomic
 * `DELETE /api/assets/batch` request for every selected asset. Reuses
 * `LifecycleConfirmDialog` exactly like the single-asset delete on AssetDetail, so
 * Escape/backdrop-blocked-while-busy and the disabled-while-busy confirm button come
 * for free.
 *
 * Not a hard delete: every asset is soft-deleted (moved to Trash), stays in the
 * database, and can be restored via the existing per-asset restore endpoint — the
 * dialog copy says so explicitly so an operator never mistakes this for permanent
 * deletion.
 */

const PREVIEW_LIMIT = 10;

export default function BatchDeleteDialog({ open, assets, onClose, onSuccess, onStale }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (open) setError('');
  }, [open]);

  if (!open) return null;

  const preview = assets.slice(0, PREVIEW_LIMIT);
  const remaining = assets.length - preview.length;

  const handleConfirm = async () => {
    setBusy(true);
    setError('');
    try {
      const res = await api.delete('/api/assets/batch', { asset_ids: assets.map((a) => a.id) });
      setBusy(false);
      onSuccess(res);
    } catch (e) {
      setBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 403) {
          setError('Anda tidak memiliki izin untuk menghapus aset.');
          return;
        }
        if (e.status === 404) {
          // Selection state is now stale (an asset vanished under us) — reloading
          // the list is safer than leaving the operator staring at ids that no
          // longer resolve, so this is the one failure case that also refreshes.
          onStale('Salah satu aset tidak ditemukan. Daftar akan dimuat ulang.');
          return;
        }
        if (e.status === 409) {
          setError(e.message || 'Data berubah oleh proses lain. Silakan muat ulang daftar aset dan coba lagi.');
          return;
        }
        if (e.status === 422) {
          setError(e.message || 'Data yang dikirim tidak valid.');
          return;
        }
        if (e.status >= 500) {
          setError('Terjadi kesalahan pada server. Tidak ada perubahan yang dianggap berhasil.');
          return;
        }
        setError(e.message || 'Gagal menghapus aset. Coba lagi.');
        return;
      }
      setError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  return (
    <LifecycleConfirmDialog
      open={open}
      title={`Hapus ${assets.length} aset?`}
      tone="danger"
      confirmLabel="Hapus"
      busy={busy}
      error={error}
      onConfirm={handleConfirm}
      onClose={onClose}
    >
      <p>
        Aset yang dipilih akan dipindahkan ke <span className="font-medium text-gray-900">Trash</span>.
        Data <span className="font-medium text-gray-900">tidak</span> dihapus permanen — record tetap
        tersimpan dan dapat dipulihkan. Nomor aset yang sudah digunakan tidak akan digunakan kembali.
      </p>

      <div className="rounded-lg border border-gray-200 bg-gray-50 p-2.5">
        <p className="mb-1 text-xs font-medium text-gray-700">{assets.length} aset akan dihapus:</p>
        <ul className="space-y-0.5">
          {preview.map((a) => (
            <li key={a.id} className="truncate font-mono text-xs text-gray-600">
              {a.asset_code} — {a.subcategory?.name ?? '—'}
            </li>
          ))}
        </ul>
        {remaining > 0 && <p className="mt-1 text-xs text-gray-400">+ {remaining} lainnya</p>}
      </div>
    </LifecycleConfirmDialog>
  );
}
