import { useEffect, useRef } from 'react';

/**
 * Reusable confirmation modal for asset lifecycle actions (Tahap 5.8.5).
 *
 * No dependency: a dimmed backdrop + a centred panel (bottom sheet on mobile),
 * Escape / backdrop-click to close (both blocked while `busy`), focus moved into
 * the panel on open. The confirm button is disabled while a request is in flight so
 * an action can never be double-submitted. Extra inputs (e.g. the write-off date)
 * are passed as `children`.
 */
export default function LifecycleConfirmDialog({
  open,
  title,
  tone = 'default', // 'default' | 'danger'
  confirmLabel,
  confirmDisabled = false,
  busy = false,
  error = '',
  onConfirm,
  onClose,
  children,
}) {
  const panelRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    panelRef.current?.focus();
    const onKey = (e) => {
      if (e.key === 'Escape' && !busy) onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, busy, onClose]);

  if (!open) return null;

  const confirmClass =
    tone === 'danger'
      ? 'bg-red-600 hover:bg-red-700 focus-visible:ring-red-600'
      : 'bg-gray-900 hover:bg-gray-800 focus-visible:ring-gray-900';

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
      <button
        type="button"
        aria-label="Tutup"
        disabled={busy}
        onClick={() => !busy && onClose()}
        className="absolute inset-0 bg-black/40 disabled:cursor-default"
      />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby="lifecycle-dialog-title"
        className="relative w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl outline-none sm:rounded-2xl"
      >
        <h2 id="lifecycle-dialog-title" className="text-base font-semibold text-gray-900">
          {title}
        </h2>
        <div className="mt-2 space-y-2 text-sm text-gray-600">{children}</div>

        {error && (
          <p
            role="alert"
            className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
          >
            {error}
          </p>
        )}

        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:border-gray-400 disabled:opacity-60"
          >
            Batal
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={busy || confirmDisabled}
            className={`rounded-lg px-4 py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${confirmClass}`}
          >
            {busy ? 'Memproses…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
