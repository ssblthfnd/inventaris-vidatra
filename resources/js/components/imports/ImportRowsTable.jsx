/**
 * Row-level preview table for one import batch (Tahap 6.1). Mirrors the visual
 * language of `components/inventory/InventoryTable.jsx` (same header/cell classes)
 * so the Import Excel page doesn't introduce a second table style.
 */

const STATUS_BADGE = {
  valid: { label: 'Valid', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  warning: { label: 'Warning', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
  error: { label: 'Error', className: 'bg-red-50 text-red-700 ring-red-200' },
  pending: { label: 'Pending', className: 'bg-gray-100 text-gray-600 ring-gray-200' },
};

function StatusBadge({ status, isDuplicate }) {
  const meta = STATUS_BADGE[status] ?? STATUS_BADGE.pending;

  return (
    <span className="inline-flex flex-col gap-1">
      <span
        className={`inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${meta.className}`}
      >
        {meta.label}
      </span>
      {isDuplicate && (
        <span className="inline-flex w-fit items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 ring-1 ring-inset ring-gray-200">
          Duplikat
        </span>
      )}
    </span>
  );
}

function SkeletonRows() {
  return Array.from({ length: 6 }).map((_, i) => (
    <tr key={i} className="border-t border-gray-100">
      {Array.from({ length: 5 }).map((__, j) => (
        <td key={j} className="px-4 py-3">
          <span className="block h-3 w-full max-w-[10rem] animate-pulse rounded bg-gray-100" />
        </td>
      ))}
    </tr>
  ));
}

export default function ImportRowsTable({ rows, loading }) {
  return (
    <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
              <th className="px-4 py-3">Baris</th>
              <th className="px-4 py-3">Identitas</th>
              <th className="px-4 py-3">Ruangan</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Catatan</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <SkeletonRows />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-8 text-center text-sm text-gray-400">
                  Tidak ada baris untuk filter ini.
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-t border-gray-100 hover:bg-gray-50/60">
                  <td className="px-4 py-3 text-gray-500">{row.row_number}</td>
                  <td className="px-4 py-3">
                    <span className="block font-mono text-[13px] font-medium text-gray-900">
                      {row.identity}
                    </span>
                    {row.brand_model && (
                      <span className="block text-xs text-gray-500">{row.brand_model}</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    {row.matched_room_name ? (
                      <span className="text-gray-700">{row.matched_room_name}</span>
                    ) : (
                      <span className="text-gray-400">
                        {row.room_raw_value ? `${row.room_raw_value} (belum dipetakan)` : 'Belum diisi'}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={row.validation_status} isDuplicate={row.is_duplicate} />
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-500">
                    {row.validation_messages.length === 0 ? (
                      <span className="text-gray-300">—</span>
                    ) : (
                      <ul className="list-inside list-disc space-y-0.5">
                        {row.validation_messages.map((m, i) => (
                          <li key={i}>{m.message}</li>
                        ))}
                      </ul>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
