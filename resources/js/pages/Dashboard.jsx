import { useEffect, useState } from 'react';

import { api, ApiError } from '../lib/api';
import { useAuth } from '../auth/AuthContext';

const CONDITION_LABELS = {
  baik: 'Baik',
  kurang_baik: 'Kurang Baik',
  rusak_berat: 'Rusak Berat',
  unknown: 'Tidak diketahui',
};

function StatCard({ label, value }) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function CountTable({ title, rows, labelKey, headers }) {
  return (
    <section className="rounded-xl border border-gray-200 bg-white">
      <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">{title}</h2>
      {rows.length === 0 ? (
        <p className="px-4 py-6 text-sm text-gray-400">Belum ada data.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2 font-medium">{headers[0]}</th>
                <th className="px-4 py-2 text-right font-medium">{headers[1]}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, i) => (
                <tr key={row.id ?? row.code ?? i} className="border-t border-gray-100">
                  <td className="px-4 py-2">{row[labelKey]}</td>
                  <td className="px-4 py-2 text-right tabular-nums">{row.asset_count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

export default function Dashboard() {
  const { refreshUser } = useAuth();
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

  if (status === 'loading') {
    return <p className="text-sm text-gray-500">Memuat dashboard…</p>;
  }

  if (status === 'error') {
    return (
      <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {error}
      </div>
    );
  }

  const summary = data?.summary ?? { total_assets: 0, written_off: 0, by_condition: {} };
  const byCondition = summary.by_condition ?? {};

  return (
    <div className="space-y-6">
      <h1 className="text-xl font-semibold tracking-tight">Dashboard</h1>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Total Aset" value={summary.total_assets} />
        <StatCard label="Written-off" value={summary.written_off} />
        <StatCard label="Lokasi" value={(data?.by_location ?? []).length} />
        <StatCard label="Kategori" value={(data?.by_category ?? []).length} />
      </div>

      <section className="rounded-xl border border-gray-200 bg-white">
        <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">Kondisi Aset</h2>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <tbody>
              {['baik', 'kurang_baik', 'rusak_berat', 'unknown'].map((key) => (
                <tr key={key} className="border-t border-gray-100 first:border-t-0">
                  <td className="px-4 py-2">{CONDITION_LABELS[key]}</td>
                  <td className="px-4 py-2 text-right tabular-nums">{byCondition[key] ?? 0}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <CountTable
          title="Aset per Lokasi"
          rows={data?.by_location ?? []}
          labelKey="name"
          headers={['Lokasi', 'Jumlah']}
        />
        <CountTable
          title="Aset per Kategori"
          rows={data?.by_category ?? []}
          labelKey="name"
          headers={['Kategori', 'Jumlah']}
        />
      </div>

      <CountTable
        title="Aset per Ruangan"
        rows={(data?.by_room ?? []).map((r) => ({ ...r, name: `${r.name} — ${r.location_name}` }))}
        labelKey="name"
        headers={['Ruangan', 'Jumlah']}
      />

      <section className="rounded-xl border border-gray-200 bg-white">
        <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">Mutasi Terbaru</h2>
        {(data?.recent_mutations ?? []).length === 0 ? (
          <p className="px-4 py-6 text-sm text-gray-400">Belum ada mutasi.</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {data.recent_mutations.map((m) => (
              <li key={m.id} className="px-4 py-3 text-sm">
                <span className="text-gray-500">{m.mutation_date}</span>{' '}
                <span className="font-medium">{m.from?.room_label ?? '—'}</span>
                <span className="text-gray-400"> → </span>
                <span className="font-medium">{m.to?.room_label ?? '—'}</span>
                {m.performed_by?.name && (
                  <span className="text-gray-400"> · {m.performed_by.name}</span>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
