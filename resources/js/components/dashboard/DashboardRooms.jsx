import { useEffect, useId, useState } from 'react';

import { useAuth } from '../../auth/AuthContext';
import { api, ApiError } from '../../lib/api';
import { formatNumber } from '../../lib/chartData';
import RoomCountTable from '../charts/RoomCountTable';

const SEARCH_DELAY_MS = 300;

// max-[480px]:basis-full — on phones every control gets its own row (README: "kontrol filter ruangan bertumpuk")
const fieldClass = 'flex min-w-0 flex-col gap-1.5 text-[13px] text-muted max-[480px]:basis-full';
const controlClass =
  'h-11 w-full rounded-[10px] border border-field-border bg-field px-2.5 text-sm text-ink focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none';
const pagerButton =
  'min-h-11 cursor-pointer whitespace-nowrap rounded-[10px] border border-field-border px-4 text-sm font-semibold text-ink hover:bg-gray-50 disabled:cursor-default disabled:opacity-45 disabled:hover:bg-transparent';

/**
 * Dashboard "Aset per Ruangan": search, location filter, sort and page size, all
 * done by `GET /api/dashboard/rooms`. Changing the search or a filter goes back to
 * page 1; the previous page stays visible (dimmed) while the next one loads.
 * `locations`: [{ code, name }] for the filter.
 */
export default function DashboardRooms({ locations }) {
  const { refreshUser } = useAuth();
  const id = useId();

  const [search, setSearch] = useState('');
  const [query, setQuery] = useState({ q: '', location_code: '', sort: 'count', per_page: 10, page: 1 });
  const [result, setResult] = useState(null); // { data, meta }
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  const update = (patch) => setQuery((prev) => ({ ...prev, ...patch, page: patch.page ?? 1 }));

  // debounce typing; only a changed term resets the page
  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery((prev) => (prev.q === search.trim() ? prev : { ...prev, q: search.trim(), page: 1 }));
    }, SEARCH_DELAY_MS);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    let alive = true;
    setPhase('loading');

    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== '') params.set(key, String(value));
    }

    api
      .get(`/api/dashboard/rooms?${params}`)
      .then((res) => {
        if (!alive) return;
        setResult({ data: res?.data ?? [], meta: res?.meta ?? null });
        setPhase('ready');
      })
      .catch((e) => {
        if (!alive) return;
        if (e instanceof ApiError && (e.status === 401 || e.status === 403)) {
          refreshUser();
          return;
        }
        setError(e instanceof ApiError ? e.message : 'Gagal memuat data ruangan.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
  }, [query, retryKey, refreshUser]);

  const rows = result?.data ?? [];
  const meta = result?.meta ?? { current_page: 1, last_page: 1, per_page: query.per_page, total: 0 };
  const lastPage = Math.max(1, meta.last_page);
  const from = rows.length ? (meta.current_page - 1) * meta.per_page + 1 : 0;
  const to = from ? from + rows.length - 1 : 0;
  const summary = meta.total
    ? `Menampilkan ${formatNumber(from)}–${formatNumber(to)} dari ${formatNumber(meta.total)} ruangan`
    : 'Menampilkan 0 ruangan';
  const pageLabel = `${meta.current_page} / ${lastPage}`;

  return (
    <section className="min-w-0 overflow-hidden rounded-[14px] border border-border bg-surface">
      <div className="flex flex-col gap-3.5 border-b border-divider px-5 py-4">
        <h2 className="text-[15px] font-bold text-ink">Aset per Ruangan</h2>
        <div className="flex flex-wrap items-end gap-3">
          <label htmlFor={`${id}-q`} className={`${fieldClass} flex-[2_1_240px]`}>
            <span>Cari ruangan</span>
            <input
              id={`${id}-q`}
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Nama ruangan…"
              maxLength={100}
              className={controlClass}
            />
          </label>
          <label htmlFor={`${id}-loc`} className={`${fieldClass} flex-[1_1_150px]`}>
            <span>Lokasi</span>
            <select
              id={`${id}-loc`}
              value={query.location_code}
              onChange={(e) => update({ location_code: e.target.value })}
              className={controlClass}
            >
              <option value="">Semua lokasi</option>
              {locations.map((l) => (
                <option key={l.code} value={l.code}>
                  {l.name}
                </option>
              ))}
            </select>
          </label>
          <label htmlFor={`${id}-sort`} className={`${fieldClass} flex-[1_1_150px]`}>
            <span>Urutkan</span>
            <select
              id={`${id}-sort`}
              value={query.sort}
              onChange={(e) => update({ sort: e.target.value })}
              className={controlClass}
            >
              <option value="count">Jumlah terbanyak</option>
              <option value="name">Nama ruangan (A–Z)</option>
            </select>
          </label>
          <label htmlFor={`${id}-pp`} className={`${fieldClass} flex-[0_1_110px]`}>
            <span>Per halaman</span>
            <select
              id={`${id}-pp`}
              value={query.per_page}
              onChange={(e) => update({ per_page: Number(e.target.value) })}
              className={controlClass}
            >
              <option value={10}>10</option>
              <option value={25}>25</option>
            </select>
          </label>
        </div>
      </div>

      {phase === 'error' ? (
        <div className="px-5 py-7 text-center text-sm">
          <p className="text-red-700">{error}</p>
          <button type="button" onClick={() => setRetryKey((k) => k + 1)} className={`${pagerButton} mt-3`}>
            Coba lagi
          </button>
        </div>
      ) : result === null ? (
        <p className="px-5 py-7 text-center text-sm text-muted">Memuat ruangan…</p>
      ) : (
        <div aria-busy={phase === 'loading'} className={phase === 'loading' ? 'opacity-60' : ''}>
          <RoomCountTable rows={rows} />
        </div>
      )}

      {/* phones: summary + page number on one line, the two buttons below splitting the full width */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-divider px-5 py-3.5 text-sm text-muted max-[480px]:flex-col max-[480px]:items-stretch">
        <div className="flex items-baseline justify-between gap-3">
          <span aria-live="polite">{result === null ? '' : summary}</span>
          <span className="hidden whitespace-nowrap tabular-nums max-[480px]:inline">{pageLabel}</span>
        </div>
        <div className="flex items-center gap-2 max-[480px]:grid max-[480px]:grid-cols-2">
          <button
            type="button"
            onClick={() => update({ page: meta.current_page - 1 })}
            disabled={phase !== 'ready' || meta.current_page <= 1}
            className={pagerButton}
          >
            ‹ Sebelumnya
          </button>
          <span className="whitespace-nowrap tabular-nums max-[480px]:hidden">{pageLabel}</span>
          <button
            type="button"
            onClick={() => update({ page: meta.current_page + 1 })}
            disabled={phase !== 'ready' || meta.current_page >= lastPage}
            className={pagerButton}
          >
            Selanjutnya ›
          </button>
        </div>
      </div>
    </section>
  );
}
