import { formatNumber } from '../../lib/chartData';

/** KPI tiles that wrap instead of breaking at fixed breakpoints. `items`: [{ label, value }]. */
export default function KpiRow({ items }) {
  return (
    <div className="flex flex-wrap gap-3">
      {items.map((item) => (
        <div
          key={item.label}
          className="flex min-w-0 flex-[1_1_130px] flex-col gap-1.5 rounded-[14px] border border-border bg-surface px-[18px] py-4"
        >
          <span className="text-sm text-muted">{item.label}</span>
          <span className="text-[26px] leading-tight font-bold text-ink tabular-nums">{formatNumber(item.value)}</span>
        </div>
      ))}
    </div>
  );
}
