import { useEffect, useRef, useState } from 'react';

/**
 * "Export Excel" trigger + a transient menu (Tahap 6.2) — not a permanent export
 * configuration panel, matching `PrintLabelMenu`'s own "no persistent selector"
 * precedent (Tahap 6.0.2). Every option's wording states explicitly whether it
 * uses the filters currently applied on the page, or bypasses them entirely, so
 * a user can never accidentally export the wrong scope without knowing it.
 *
 * This component only owns the menu's open/closed state; the actual download
 * (and its busy/error state) belongs to the caller via `onSelect(query)`, where
 * `query` is a ready-to-use `GET /api/assets/export` query string (or `''` for
 * "no filter, everything").
 *
 * Tahap 6.8.4: the per-category quick-export buttons are driven by `categories`
 * (the caller's own already-fetched `useMasterData().categories`, active-only)
 * instead of a hardcoded list — `AssetExportService` genuinely supports
 * exporting any category (it falls back to a generic column layout for one it
 * doesn't have a specific mapping for), so unlike the import TEMPLATE selector
 * in `lib/imports.js` (which is hard-limited to categories with a known Excel
 * column layout), export has no such restriction and can safely offer every
 * active category. No new fetch here — reuses whatever the page already loaded.
 */
export default function ExportMenu({
  categories,
  currentQuery,
  currentCount,
  hasActiveFilters,
  onSelect,
  busy = false,
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

  useEffect(() => {
    if (busy) setOpen(false);
  }, [busy]);

  const pick = (query) => {
    setOpen(false);
    onSelect(query);
  };

  return (
    <div ref={rootRef} className="relative inline-block">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        disabled={busy}
        aria-haspopup="menu"
        aria-expanded={open}
        className={buttonClassName}
      >
        {busy ? 'Menyiapkan Excel…' : 'Export Excel'}
      </button>

      {open && (
        <div
          role="menu"
          className={[
            'absolute z-30 mt-2 w-64 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg',
            align === 'right' ? 'right-0' : 'left-0',
          ].join(' ')}
        >
          <button
            type="button"
            role="menuitem"
            onClick={() => pick(currentQuery)}
            className="flex w-full flex-col items-start px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
          >
            <span className="font-medium">Export hasil saat ini</span>
            <span className="text-xs text-gray-400">
              {hasActiveFilters ? `Sesuai filter aktif — ${currentCount} aset` : `Belum ada filter — ${currentCount} aset`}
            </span>
          </button>

          <div className="my-1 border-t border-gray-100" />

          <button
            type="button"
            role="menuitem"
            onClick={() => pick('')}
            className="flex w-full flex-col items-start px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
          >
            <span className="font-medium">Semua Data</span>
            <span className="text-xs text-gray-400">Mengabaikan filter yang sedang aktif</span>
          </button>

          {categories.map((cat) => (
            <button
              key={cat.code}
              type="button"
              role="menuitem"
              onClick={() => pick(`category_code[]=${cat.code}`)}
              className="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
            >
              <span>{cat.name}</span>
              <span className="text-xs text-gray-400">{cat.code}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
