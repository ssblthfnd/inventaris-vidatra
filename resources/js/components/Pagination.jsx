/**
 * Page navigation for a paginated API list (`meta.current_page` / `meta.last_page`),
 * extracted unchanged from `pages/Inventory.jsx` (Tahap 6.9 R9.4-14) so the asset
 * Trash uses the same control. Renders nothing for a single page.
 */

function PageButton({ children, active, disabled, onClick, ariaLabel }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={ariaLabel}
      aria-current={active ? 'page' : undefined}
      className={[
        'min-w-9 rounded-md border px-3 py-1.5 text-sm transition-colors',
        active
          ? 'border-gray-900 bg-gray-900 text-white'
          : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-gray-300',
      ].join(' ')}
    >
      {children}
    </button>
  );
}

function pageWindow(current, last) {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  const pages = new Set([1, last, current, current - 1, current + 1]);
  const sorted = [...pages].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out = [];
  let prev = 0;
  for (const p of sorted) {
    if (p - prev > 1) out.push(`gap-${p}`);
    out.push(p);
    prev = p;
  }
  return out;
}

export default function Pagination({ meta, onPageChange }) {
  if (!meta || meta.total === 0 || meta.last_page <= 1) return null;

  return (
    <nav className="flex flex-wrap items-center justify-center gap-1.5" aria-label="Paginasi">
      <PageButton
        onClick={() => onPageChange(meta.current_page - 1)}
        disabled={meta.current_page <= 1}
        ariaLabel="Halaman sebelumnya"
      >
        ‹ Sebelumnya
      </PageButton>
      {pageWindow(meta.current_page, meta.last_page).map((p) =>
        typeof p === 'string' ? (
          <span key={p} className="px-1 text-gray-400">
            …
          </span>
        ) : (
          <PageButton
            key={p}
            active={p === meta.current_page}
            onClick={() => onPageChange(p)}
            ariaLabel={`Halaman ${p}`}
          >
            {p}
          </PageButton>
        ),
      )}
      <PageButton
        onClick={() => onPageChange(meta.current_page + 1)}
        disabled={meta.current_page >= meta.last_page}
        ariaLabel="Halaman berikutnya"
      >
        Berikutnya ›
      </PageButton>
    </nav>
  );
}
