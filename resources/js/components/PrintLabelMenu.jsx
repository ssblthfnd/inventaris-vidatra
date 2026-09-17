import { useEffect, useRef, useState } from 'react';
import { LABEL_SIZE_OPTIONS } from '../lib/labels';

/**
 * "Cetak Label" trigger + a print-size menu (Kecil/Sedang/Besar) — Tahap 6.0.2.
 * R8 adds an optional Mode toggle (A4 / Individual) above the size list, shown
 * only when `showModeSelector` is true (Inventory's multi-select batch print,
 * where "one A4 sheet vs. one file per asset" is a meaningful choice) — NOT on
 * `AssetDetail`'s single-asset print button, which already produces exactly an
 * Individual-mode-shaped PDF (see `AssetLabelController`'s own docblock) and
 * has no second asset to make a multi-page-vs-sheet decision about, so a
 * redundant mode toggle there would offer nothing new. `AssetDetail`'s call
 * site is unchanged: it just receives an extra `mode` argument on `onSelect`
 * it already safely ignores.
 *
 * Deliberately NOT a persistent size (or mode) selector: the menu only exists
 * in the DOM while `open` is true, and closes itself immediately after a size
 * pick, on outside click, or on Escape — matching the spec's "jangan membuat
 * selector ukuran permanen yang selalu mengambil tempat di halaman." The mode
 * toggle is the one exception that does NOT close the menu on change (picking
 * a mode isn't the final action — picking a size still is).
 *
 * This component owns only the menu's open/closed state and (when shown) which
 * mode is currently selected. The actual PDF request/download and its
 * busy/error state belong to the caller (`AssetDetail` / `Inventory`) via
 * `onSelect` — while `busy` is true the trigger is disabled, which is what
 * prevents a second click from starting a duplicate request.
 *
 * Props:
 *  - onSelect(size, mode): called when a size is picked; `mode` is always
 *    `'a4'` when `showModeSelector` is false
 *  - busy: bool — a print request is in flight; trigger shows "Menyiapkan PDF…" and is disabled
 *  - disabled: bool — extra disable condition unrelated to busy (e.g. nothing selected)
 *  - buttonClassName: full className for the trigger button (caller controls visual style)
 *  - align: 'left' | 'right' — which edge of the trigger the menu hangs from
 *  - showModeSelector: bool — show the A4/Individual toggle (R8); default false preserves the exact pre-R8 menu
 *  - selectionCount: number — how many assets are currently selected, used only for the optional Individual-mode hint text ("N aset akan digabung menjadi 1 PDF N halaman")
 */
export default function PrintLabelMenu({
  onSelect,
  busy = false,
  disabled = false,
  buttonClassName = '',
  align = 'left',
  showModeSelector = false,
  selectionCount = 1,
}) {
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState('a4');
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
    onSelect(size, mode);
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
        {busy ? 'Menyiapkan…' : 'Cetak Label'}
      </button>

      {open && (
        <div
          role="menu"
          className={[
            'absolute z-30 mt-2 w-56 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg',
            align === 'right' ? 'right-0' : 'left-0',
          ].join(' ')}
        >
          {showModeSelector && (
            <div className="border-b border-gray-100 px-3 py-2">
              <p className="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-400">Mode</p>
              <div className="flex flex-col gap-1 text-sm text-gray-700">
                <label className="flex items-center gap-1.5">
                  <input
                    type="radio"
                    name="print-label-mode"
                    checked={mode === 'a4'}
                    onChange={() => setMode('a4')}
                    className="h-3.5 w-3.5 border-gray-300 text-gray-900 focus:ring-gray-900"
                  />
                  A4 (banyak label per halaman)
                </label>
                <label className="flex items-center gap-1.5">
                  <input
                    type="radio"
                    name="print-label-mode"
                    checked={mode === 'individual'}
                    onChange={() => setMode('individual')}
                    className="h-3.5 w-3.5 border-gray-300 text-gray-900 focus:ring-gray-900"
                  />
                  Individual (satu label per halaman)
                </label>
              </div>
              {mode === 'individual' && (
                <p className="mt-1.5 text-xs text-gray-400">
                  {selectionCount <= 1
                    ? '1 aset → PDF ukuran label.'
                    : `${selectionCount} aset → 1 PDF dengan ${selectionCount} halaman.`}
                </p>
              )}
            </div>
          )}
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
