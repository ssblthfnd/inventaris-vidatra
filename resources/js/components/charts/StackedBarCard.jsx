import { formatNumber, formatPercent } from '../../lib/chartData';
import ChartCard from './ChartCard';

/**
 * 100% stacked bar + legend (Sumber, Kondisi). The legend always lists every
 * item, zero ones included, with count and percentage, so nothing depends on
 * colour alone; zero segments are not drawn. `items`: [{ label, value, color }].
 */
export default function StackedBarCard({ title, items, className }) {
  const total = items.reduce((sum, item) => sum + item.value, 0);
  const summary = `${title}: ${items.map((item) => `${item.label} ${item.value}`).join(', ')}`;

  return (
    <ChartCard title={title} className={className}>
      <div role="img" aria-label={summary} className="flex h-6 gap-0.5 overflow-hidden rounded bg-track">
        {items
          .filter((item) => item.value > 0)
          .map((item) => (
            <div
              key={item.label}
              title={`${item.label}: ${item.value} (${formatPercent(item.value, total)})`}
              className="h-full"
              style={{ width: `${(item.value / total) * 100}%`, backgroundColor: item.color }}
            />
          ))}
      </div>
      <ul className="flex flex-col gap-[9px] text-sm text-ink">
        {items.map((item) => (
          <li key={item.label} className="flex items-center gap-2.5">
            <span aria-hidden="true" className="h-2.5 w-2.5 shrink-0 rounded-[3px]" style={{ backgroundColor: item.color }} />
            <span className="min-w-0 flex-1">{item.label}</span>
            <span className="tabular-nums">{formatNumber(item.value)}</span>
            <span className="w-[52px] text-right text-muted tabular-nums">{formatPercent(item.value, total)}</span>
          </li>
        ))}
      </ul>
    </ChartCard>
  );
}
