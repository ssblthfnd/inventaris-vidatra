import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';

/** Preferred panel width; shrinks to fit narrow viewports. */
const PANEL_WIDTH = 288;
/** Gap kept between the panel and the viewport edges. */
const VIEWPORT_MARGIN = 8;

/**
 * Keep the dropdown fully inside the viewport. The panel is `position: absolute`
 * anchored to the trigger, so it is aligned to the trigger's left edge and then
 * the whole rect is clamped horizontally into the viewport (and its width capped
 * to the viewport). Purely geometric — no per-filter special-casing, no vertical
 * change (still opens directly below the trigger).
 *
 * @param {DOMRect} triggerRect  the trigger's bounding rect
 * @returns {{ left: string, width: string }}  inline style for the panel
 */
function clampPanelToViewport(triggerRect) {
  const viewportWidth = document.documentElement.clientWidth;
  const width = Math.min(PANEL_WIDTH, viewportWidth - VIEWPORT_MARGIN * 2);

  const maxLeft = viewportWidth - VIEWPORT_MARGIN - width;
  const desiredLeft = triggerRect.left; // align with the trigger's left edge
  const clampedLeft = Math.min(Math.max(desiredLeft, VIEWPORT_MARGIN), maxLeft);

  return {
    // offset is relative to the `relative` wrapper, i.e. the trigger's left edge
    left: `${Math.round(clampedLeft - triggerRect.left)}px`,
    width: `${Math.round(width)}px`,
  };
}

/**
 * Compact multi-select filter: a trigger button that opens a popover with a search
 * box, checkboxes, and "Pilih semua" / "Bersihkan". Used for every Inventaris filter.
 *
 * Props:
 *  - label:     filter name shown on the trigger ("Kategori")
 *  - options:   [{ value, label, hint? }]
 *  - selected:  array of selected values
 *  - onChange:  (nextValues) => void
 *  - disabled:  bool
 *  - loading:   bool — options still loading
 *  - searchable: bool (default: options.length > 6)
 *  - emptyText: shown when there are no options
 */
export default function MultiSelectFilter({
  label,
  options = [],
  selected = [],
  onChange,
  disabled = false,
  loading = false,
  searchable,
  emptyText = 'Tidak ada pilihan',
}) {
  const [open, setOpen] = useState(false);
  const [term, setTerm] = useState('');
  const [panelStyle, setPanelStyle] = useState(null);
  const rootRef = useRef(null);
  const searchRef = useRef(null);
  const listId = useId();

  const showSearch = searchable ?? options.length > 6;
  const selectedSet = useMemo(() => new Set(selected), [selected]);

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
    if (open && showSearch) searchRef.current?.focus();
    if (!open) setTerm('');
  }, [open, showSearch]);

  // Position the panel inside the viewport before paint, and re-clamp on resize
  // (the only thing that moves the trigger horizontally / changes viewport width).
  useLayoutEffect(() => {
    if (!open) return undefined;
    const reposition = () => {
      if (!rootRef.current) return;
      const next = clampPanelToViewport(rootRef.current.getBoundingClientRect());
      setPanelStyle((prev) =>
        prev && prev.left === next.left && prev.width === next.width ? prev : next,
      );
    };
    reposition();
    window.addEventListener('resize', reposition);
    return () => window.removeEventListener('resize', reposition);
  }, [open]);

  const filtered = useMemo(() => {
    const q = term.trim().toLowerCase();
    if (!q) return options;
    return options.filter(
      (o) =>
        o.label.toLowerCase().includes(q) || (o.hint ?? '').toLowerCase().includes(q),
    );
  }, [options, term]);

  const toggle = (value) => {
    if (selectedSet.has(value)) onChange(selected.filter((v) => v !== value));
    else onChange([...selected, value]);
  };

  const selectAllVisible = () => {
    const merged = new Set(selected);
    for (const o of filtered) merged.add(o.value);
    onChange([...merged]);
  };

  const clear = () => onChange([]);

  const summary = () => {
    if (selected.length === 0) return null;
    if (selected.length === 1) {
      return options.find((o) => o.value === selected[0])?.label ?? '1 dipilih';
    }
    if (selected.length === 2) {
      return selected
        .map((v) => options.find((o) => o.value === v)?.label ?? v)
        .join(', ');
    }
    return `${selected.length} dipilih`;
  };

  const active = selected.length > 0;

  return (
    <div ref={rootRef} className="relative">
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        className={[
          'flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm transition-colors sm:w-auto',
          disabled
            ? 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400'
            : active
              ? 'border-gray-900 bg-gray-900 text-white hover:bg-gray-800'
              : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400',
        ].join(' ')}
      >
        <span className="flex min-w-0 items-center gap-1.5">
          <span className={active ? 'font-medium' : ''}>{label}</span>
          {active && (
            <span className="truncate text-xs opacity-80">· {summary()}</span>
          )}
        </span>
        <svg
          viewBox="0 0 20 20"
          className={['h-4 w-4 shrink-0 transition-transform', open ? 'rotate-180' : ''].join(' ')}
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          aria-hidden="true"
        >
          <path d="M6 8l4 4 4-4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      {open && (
        <div
          id={listId}
          style={panelStyle ?? undefined}
          className="absolute left-0 z-30 mt-2 w-72 max-w-[calc(100vw-1rem)] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg"
        >
          {showSearch && (
            <div className="border-b border-gray-100 p-2">
              <input
                ref={searchRef}
                type="text"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                placeholder={`Cari ${label.toLowerCase()}…`}
                className="w-full rounded-md border border-gray-200 px-2.5 py-1.5 text-sm outline-none focus:border-gray-900"
              />
            </div>
          )}

          <div className="max-h-60 overflow-y-auto py-1">
            {loading ? (
              <p className="px-3 py-3 text-sm text-gray-400">Memuat…</p>
            ) : filtered.length === 0 ? (
              <p className="px-3 py-3 text-sm text-gray-400">
                {options.length === 0 ? emptyText : 'Tidak ada yang cocok'}
              </p>
            ) : (
              filtered.map((o) => (
                <label
                  key={o.value}
                  className="flex cursor-pointer items-start gap-2.5 px-3 py-1.5 text-sm hover:bg-gray-50"
                >
                  <input
                    type="checkbox"
                    checked={selectedSet.has(o.value)}
                    onChange={() => toggle(o.value)}
                    className="mt-0.5 h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                  />
                  <span className="min-w-0">
                    <span className="block truncate text-gray-800">{o.label}</span>
                    {o.hint && <span className="block truncate text-xs text-gray-400">{o.hint}</span>}
                  </span>
                </label>
              ))
            )}
          </div>

          <div className="flex items-center justify-between border-t border-gray-100 px-2 py-1.5 text-xs">
            <button
              type="button"
              onClick={selectAllVisible}
              disabled={filtered.length === 0}
              className="rounded px-2 py-1 font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-40"
            >
              Pilih semua
            </button>
            <button
              type="button"
              onClick={clear}
              disabled={selected.length === 0}
              className="rounded px-2 py-1 font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-40"
            >
              Bersihkan
            </button>
            <button
              type="button"
              onClick={() => setOpen(false)}
              className="rounded bg-gray-900 px-2.5 py-1 font-medium text-white hover:bg-gray-800"
            >
              Selesai
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
