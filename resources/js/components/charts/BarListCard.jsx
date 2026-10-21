import { formatNumber } from '../../lib/chartData';
import ChartCard from './ChartCard';

/**
 * Single-colour horizontal bars (Lokasi, Kategori), largest first, label top-left
 * and count top-right. `twoColumns` lays the bars out in two columns when there is
 * room (Kategori on the Dashboard). `rows`: [{ key, label, value }].
 */
export default function BarListCard({ title, rows, twoColumns = false, className, emptyText = 'Belum ada data.' }) {
  const sorted = [...rows].sort((a, b) => b.value - a.value || a.label.localeCompare(b.label, 'id'));
  const max = Math.max(1, ...sorted.map((r) => r.value));
  const summary = `${title}: ${sorted.map((r) => `${r.label} ${r.value}`).join(', ')}`;

  return (
    <ChartCard title={title} className={className}>
      {sorted.length === 0 ? (
        <p className="text-sm text-muted">{emptyText}</p>
      ) : (
        <div
          role="img"
          aria-label={summary}
          className={twoColumns ? 'flex flex-wrap gap-x-10 gap-y-3.5' : 'flex flex-col gap-3.5'}
        >
          {sorted.map((row) => (
            <div
              key={row.key}
              title={`${row.label}: ${row.value} aset`}
              className={`flex flex-col gap-1.5 text-sm text-ink ${twoColumns ? 'min-w-0 flex-[1_1_320px]' : ''}`}
            >
              <div className="flex justify-between gap-2">
                <span className="min-w-0 break-words">{row.label}</span>
                <span className="tabular-nums">{formatNumber(row.value)}</span>
              </div>
              <div className="h-2.5 overflow-hidden rounded bg-track">
                <div className="h-full rounded-r bg-bar" style={{ width: `${(row.value / max) * 100}%` }} />
              </div>
            </div>
          ))}
        </div>
      )}
    </ChartCard>
  );
}
