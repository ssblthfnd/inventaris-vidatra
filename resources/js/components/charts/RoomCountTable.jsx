import { formatNumber } from '../../lib/chartData';

/**
 * Two-column "Aset per Ruangan" table body: room name (location as small text
 * underneath) · count. Used by the Dashboard (one page at a time) and the Laporan
 * (every row). The "Tanpa Ruangan" bucket has no location, so no second line.
 */
export default function RoomCountTable({ rows, emptyText = 'Tidak ada ruangan yang cocok dengan filter.' }) {
  return (
    <table className="w-full table-fixed border-collapse text-sm text-ink">
      <thead>
        <tr className="text-left text-xs font-semibold text-muted">
          <th scope="col" className="px-5 py-2.5 font-semibold">
            RUANGAN
          </th>
          <th scope="col" className="w-24 px-5 py-2.5 text-right font-semibold">
            JUMLAH
          </th>
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <tr>
            <td colSpan={2} className="border-t border-divider px-5 py-7 text-center text-muted">
              {emptyText}
            </td>
          </tr>
        ) : (
          rows.map((row) => (
            <tr key={row.id ?? 'roomless'}>
              <td className="border-t border-divider px-5 py-[11px] align-top break-words">
                {row.name}
                {row.location_name && <span className="block pt-0.5 text-xs text-muted">{row.location_name}</span>}
              </td>
              <td className="border-t border-divider px-5 py-[11px] text-right align-top tabular-nums">
                {formatNumber(row.asset_count)}
              </td>
            </tr>
          ))
        )}
      </tbody>
    </table>
  );
}
