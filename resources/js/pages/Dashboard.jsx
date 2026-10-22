import { useEffect, useMemo, useState } from 'react';

import { useAuth } from '../auth/AuthContext';
import BarListCard from '../components/charts/BarListCard';
import KpiRow from '../components/charts/KpiRow';
import StackedBarCard from '../components/charts/StackedBarCard';
import DashboardRooms from '../components/dashboard/DashboardRooms';
import { api, ApiError } from '../lib/api';
import { recentMutationSummary } from '../lib/assetHistory';
import { conditionItems, countActive, masterBars, sourceItems } from '../lib/chartData';

/**
 * Dashboard (design: docs/mockups/dashboard.html). KPIs, Sumber · Lokasi · Kondisi,
 * Kategori and Mutasi Terbaru come from `GET /api/dashboard`; the "Aset per
 * Ruangan" table pages through `GET /api/dashboard/rooms` on its own.
 */
export default function Dashboard() {
  const { refreshUser, isUnitAdmin, locationCode } = useAuth();
  const [status, setStatus] = useState('loading'); // loading | ready | error
  const [data, setData] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let alive = true;

    api
      .get('/api/dashboard')
      .then((res) => {
        if (alive) {
          setData(res?.data ?? null);
          setStatus('ready');
        }
      })
      .catch((e) => {
        if (!alive) return;
        if (e instanceof ApiError && (e.status === 401 || e.status === 403)) {
          // let the auth layer drop us back to /login
          refreshUser();
          return;
        }
        setError(e instanceof ApiError ? e.message : 'Gagal memuat dashboard.');
        setStatus('error');
      });

    return () => {
      alive = false;
    };
  }, [refreshUser]);

  const byLocation = useMemo(() => data?.by_location ?? [], [data]);
  const byCategory = data?.by_category ?? [];

  // a unit_admin's data is limited to their own location, so that is the only useful filter choice
  const roomLocations = useMemo(
    () => byLocation.filter((l) => (isUnitAdmin ? l.code === locationCode : l.is_active !== false || l.asset_count > 0)),
    [byLocation, isUnitAdmin, locationCode],
  );

  if (status === 'loading') {
    return <p className="text-sm text-muted">Memuat dashboard…</p>;
  }

  if (status === 'error') {
    return (
      <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {error}
      </div>
    );
  }

  const summary = data?.summary ?? {};

  return (
    <div className="flex flex-col gap-5 text-ink">
      <header className="flex flex-col gap-1.5">
        <h1 className="text-[22px] font-bold tracking-tight">Dashboard</h1>
        <p className="text-sm text-muted">Ringkasan jumlah dan kondisi aset Yayasan Vidatra saat ini.</p>
      </header>

      <KpiRow
        items={[
          { label: 'Total aset', value: summary.total_assets ?? 0 },
          { label: 'Aset aktif', value: summary.active_assets ?? 0 },
          { label: 'Ditulis off', value: summary.written_off ?? 0 },
          { label: 'Lokasi', value: countActive(byLocation) },
          { label: 'Kategori', value: countActive(byCategory) },
        ]}
      />

      <div className="flex flex-wrap gap-4">
        <StackedBarCard title="Aset per Sumber" items={sourceItems(data?.by_source)} className="flex-[1_1_230px]" />
        <BarListCard title="Aset per Lokasi" rows={masterBars(byLocation)} className="flex-[1_1_230px]" />
        <StackedBarCard
          title="Kondisi Aset"
          items={conditionItems(summary.by_condition)}
          className="flex-[1_1_230px]"
        />
      </div>

      <BarListCard title="Aset per Kategori" rows={masterBars(byCategory)} twoColumns />

      <DashboardRooms locations={roomLocations} />

      <section className="min-w-0 overflow-hidden rounded-[14px] border border-border bg-surface">
        <h2 className="border-b border-divider px-5 py-4 text-[15px] font-bold">Mutasi Terbaru</h2>
        {(data?.recent_mutations ?? []).length === 0 ? (
          <p className="px-5 py-6 text-sm text-muted">Belum ada mutasi.</p>
        ) : (
          <ul>
            {data.recent_mutations.map((m) => {
              const item = recentMutationSummary(m);

              return (
                <li key={m.id} className="border-t border-divider px-5 py-3 text-sm first:border-t-0">
                  <span className="text-muted tabular-nums">{m.mutation_date}</span>{' '}
                  {item.kind === 'move' ? (
                    <>
                      <span className="font-medium">{item.from ?? '—'}</span>
                      <span className="text-muted"> → </span>
                      <span className="font-medium">{item.to ?? '—'}</span>
                    </>
                  ) : (
                    <>
                      <span className="font-medium">{item.label}</span>
                      {item.assetCode && <span className="text-muted"> · {item.assetCode}</span>}
                    </>
                  )}
                  {m.performed_by?.name && <span className="text-muted"> · {m.performed_by.name}</span>}
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </div>
  );
}
