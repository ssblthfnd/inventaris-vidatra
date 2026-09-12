import { useEffect, useRef, useState } from 'react';
import { LABEL_SIZE_OPTIONS } from '../lib/labels';

/**
 * "Cetak Label" trigger + a print-size menu (Kecil/Sedang/Besar) — Tahap 6.0.2.
 *
 * Deliberately NOT a persistent size selector: the menu only exists in the DOM
 * while `open` is true, and closes itself immediately after a pick, on outside
 * click, or on Escape — matching the spec's "jangan membuat selector ukuran
 * permanen yang selalu mengambil tempat di halaman."
 *
 * This component owns only the menu's open/closed state. The actual PDF
 * request/download and its busy/error state belong to the caller (`AssetDetail`
 * / `Inventory`) via `onSelect` — while `busy` is true the trigger is disabled,
 * which is what prevents a second click from starting a duplicate request.
 *
 * Props:
 *  - onSelect(size): called with 'small' | 'medium' | 'large' when a size is picked
 *  - busy: bool — a print request is in flight; trigger shows "Menyiapkan PDF…" and is disabled
 *  - disabled: bool — extra disable condition unrelated to busy (e.g. nothing selected)
 *  - buttonClassName: full className for the trigger button (caller controls visual style)
 *  - align: 'left' | 'right' — which edge of the trigger the menu hangs from
 */
export default function PrintLabelMenu({
  onSelect,
  busy = false,
  disabled = false,
  buttonClassName = '',
  align = 'left',
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onDocClick = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDocClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  // Busy can start from outside a click (e.g. right after pick()) — never leave
  // the menu open behind a disabled trigger.
  useEffect(() => {
    if (busy) setOpen(false);
  }, [busy]);

  const pick = (size) => {
    setOpen(false);
    onSelect(size);
  };

  return (
    <div ref={rootRef} className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        disabled={busy || disabled}
        aria-haspopup="menu"
        aria-expanded={open}
        className={buttonClassName}
      >
        {busy ? 'Menyiapkan PDF…' : 'Cetak Label'}
      </button>

      {open && (
        <div
          role="menu"
          className={[
            'absolute z-30 mt-2 w-48 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg',
            align === 'right' ? 'right-0' : 'left-0',
          ].join(' ')}
        >
          {LABEL_SIZE_OPTIONS.map((opt) => (
            <button
              key={opt.value}
              type="button"
              role="menuitem"
              onClick={() => pick(opt.value)}
              className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
            >
              <span>{opt.label}</span>
              <span className="text-xs text-gray-400">{opt.dims}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
