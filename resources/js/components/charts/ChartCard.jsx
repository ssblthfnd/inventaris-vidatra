/**
 * Card shell shared by the Dashboard and Laporan charts. `className` carries the
 * flex basis the parent row wants (e.g. `flex-[1_1_300px]` for three across).
 */
export default function ChartCard({ title, className = '', children }) {
  return (
    <section className={`flex min-w-0 flex-col gap-4 rounded-[14px] border border-border bg-surface p-5 ${className}`}>
      <h2 className="text-[15px] font-bold text-ink">{title}</h2>
      {children}
    </section>
  );
}
