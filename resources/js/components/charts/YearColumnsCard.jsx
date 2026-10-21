import { formatNumber } from '../../lib/chartData';
import ChartCard from './ChartCard';

const PLOT_HEIGHT = 150; // px for the tallest column

/**
 * Column chart per acquisition year (Laporan only), oldest year first, count above
 * each column and a short year label ('15) below. `rows`: [{ year, asset_count }].
 */
export default function YearColumnsCard({ title, rows, className }) {
  const sorted = [...rows].sort((a, b) => a.year - b.year);
  const max = Math.max(1, ...sorted.map((r) => r.asset_count));
  const dense = sorted.length > 12; // smaller labels so many years still fit a phone screen
  const summary = `${title}: ${sorted.map((r) => `${r.year} ${r.asset_count}`).join(', ')}`;
  const textSize = dense ? 'text-[10px]' : 'text-[11px]';

  return (
    <ChartCard title={title} className={className}>
      {sorted.length === 0 ? (
        <p className="text-sm text-muted">Tidak ada data.</p>
      ) : (
        <div>
          <div role="img" aria-label={summary} className="flex h-[200px] items-end gap-1.5 border-b border-border pt-[18px]">
            {sorted.map((r) => (
              <div
                key={r.year}
                title={`${r.year}: ${r.asset_count} aset`}
                className={`flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1 text-muted tabular-nums ${textSize}`}
              >
                <span>{formatNumber(r.asset_count)}</span>
                <div
                  className="w-full max-w-7 rounded-t bg-bar"
                  style={{ height: `${(r.asset_count / max) * PLOT_HEIGHT}px` }}
                />
              </div>
            ))}
          </div>
          <div aria-hidden="true" className="mt-1 flex gap-1.5">
            {sorted.map((r) => (
              <span key={r.year} className={`min-w-0 flex-1 text-center text-muted ${textSize}`}>
                &apos;{String(r.year).slice(2)}
              </span>
            ))}
          </div>
        </div>
      )}
    </ChartCard>
  );
}
