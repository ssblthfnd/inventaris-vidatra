import { useEffect, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { ApiError } from '../../lib/api';
import {
  diffSnapshots,
  eventLabel,
  getEventSnapshots,
  revertMutation,
  revertStatusTransition,
} from '../../lib/assetHistory';

/**
 * Revert confirmation dialog (Tahap 6.5). Shows exactly what will change
 * BEFORE the request is sent — sourced from the mutation's own recorded
 * `before_snapshot`/`after_snapshot` (no extra fetch just to render this;
 * the backend's own field-level conflict check at submit time is what's
 * actually authoritative — see the 409 handling below).
 *
 * For a mutation that is part of a batch operation (`batch_operation_id` set),
 * this dialog cannot enumerate every OTHER affected asset (the history it was
 * opened from is scoped to one asset), so it states plainly that the whole
 * batch group reverts atomically instead.
 */
export default function RevertConfirmDialog({ open, event, onClose, onSuccess }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setError('');
  }, [open]);

  if (!open || !event) return null;

  const { before, after } = getEventSnapshots(event);
  const rows = diffSnapshots(after, before); // reversed: .before = current, .after = restored-to
  const status = revertStatusTransition(event.event_type);
  const isBatch = Boolean(event.batch_operation_id);

  const handleConfirm = async () => {
    setBusy(true);
    setError('');
    try {
      const res = await revertMutation(event.id);
      setBusy(false);
      onSuccess(res);
    } catch (e) {
      setBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 403) {
          setError('Anda tidak memiliki izin untuk merevert mutasi ini.');
          return;
        }
        if (e.status === 404) {
          setError('Mutasi ini tidak ditemukan. Mungkin data sudah berubah — muat ulang riwayat.');
          return;
        }
        if (e.status === 409) {
          setError(
            e.message ||
              'Data aset sudah berubah setelah mutasi ini dilakukan. Revert tidak dapat dilakukan secara ' +
                'otomatis. Periksa perubahan terbaru sebelum mencoba tindakan lain.',
          );
          return;
        }
        if (e.status >= 500) {
          setError('Terjadi kesalahan pada server. Tidak ada perubahan yang disimpan.');
          return;
        }
        setError(e.message || 'Gagal merevert mutasi. Coba lagi.');
        return;
      }
      setError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  return (
    <LifecycleConfirmDialog
      open={open}
      title="Revert perubahan ini?"
      confirmLabel="Revert"
      busy={busy}
      error={error}
      onConfirm={handleConfirm}
      onClose={onClose}
    >
      <p className="text-gray-600">{eventLabel(event)}</p>

      <div className="space-y-2.5">
        {status && (
          <div className="text-sm">
            <p className="font-medium text-gray-800">{status.label}</p>
            <p className="text-gray-600">
              Saat ini: <span className="text-gray-900">{status.current}</span>
            </p>
            <p className="text-gray-600">
              Akan dikembalikan menjadi: <span className="text-gray-900">{status.restored}</span>
            </p>
          </div>
        )}
        {rows.map((row) => (
          <div key={row.field} className="text-sm">
            <p className="font-medium text-gray-800">{row.label}</p>
            <p className="text-gray-600">
              Saat ini: <span className="text-gray-900">{row.before}</span>
            </p>
            <p className="text-gray-600">
              Akan dikembalikan menjadi: <span className="text-gray-900">{row.after}</span>
            </p>
          </div>
        ))}
        {!status && rows.length === 0 && (
          <p className="text-sm text-gray-400">Tidak ada rincian perubahan untuk ditampilkan.</p>
        )}
      </div>

      {isBatch && (
        <p className="rounded-md bg-indigo-50 px-2.5 py-2 text-xs text-indigo-700">
          Mutasi ini adalah bagian dari perubahan massal. Membatalkan akan me-revert SEMUA aset yang
          terpengaruh sekaligus, secara atomik — jika salah satu aset mengalami konflik, tidak ada aset yang
          akan diubah.
        </p>
      )}
    </LifecycleConfirmDialog>
  );
}
