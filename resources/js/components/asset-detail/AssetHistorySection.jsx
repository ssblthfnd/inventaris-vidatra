import { useCallback, useEffect, useRef, useState } from 'react';

import RevertConfirmDialog from './RevertConfirmDialog';
import { ApiError } from '../../lib/api';
import {
  createSummary,
  diffSnapshots,
  eventLabel,
  formatDateTime,
  getAssetMutations,
  getEventSnapshots,
  statusTransition,
} from '../../lib/assetHistory';

/**
 * Read-only mutation/audit history for one asset (Tahap 5.8.9) — a dedicated section
 * on `AssetDetail.jsx`, below the existing asset-information cards. Loads and errors
 * independently of the rest of the page (a history failure never blocks the asset
 * info above it, and vice versa). No revert/undo — this section only ever reads
 * `GET /api/assets/{asset}/mutations`.
 *
 * `refreshSignal` lets the parent (AssetDetail) ask this section to reload after a
 * lifecycle action succeeds (write-off / unwrite-off / delete / restore) without a
 * full page reload — pass a value that changes (e.g. an incrementing counter).
 *
 * Tahap 6.5 — revert/undo: each event card gets a "Revert" action when the API
 * says `can_revert` (operator/admin only — `isOperator` gates it client-side,
 * same "UI-only convenience" philosophy as everywhere else in this app; the
 * backend's own `can:operator` gate is what actually enforces it). A
 * successful revert refreshes this section's own history AND calls
 * `onReverted(assets)` so the parent can patch the asset it's showing without
 * a full page reload.
 *
 * Tahap 6.9 R9.1 (P2, request-waterfall hardening): `initialFetch`, if given, is
 * an ALREADY-STARTED `getAssetMutations(assetId, {page:1})` promise the parent
 * (AssetDetail) kicked off in parallel with its own asset-detail fetch, instead
 * of this section only starting its request once it mounts (which previously
 * only happened after the asset-detail fetch resolved — a sequential waterfall
 * even though this section never actually needs the resolved asset data, only
 * `assetId` from the route). Used for the very first load ONLY (tracked via a
 * ref so it's a one-shot substitution) — every later reload this section does on
 * its own (refresh after a lifecycle action, "Coba lagi", "Muat lebih banyak")
 * still calls `getAssetMutations` itself exactly as before. This never causes a
 * duplicate request: either the parent's promise is consumed here, or this
 * section makes its own single call — never both for the same load.
 */

function HistorySkeleton() {
  return (
    <div className="space-y-3" aria-hidden="true">
      {[0, 1, 2].map((i) => (
        <div key={i} className="rounded-xl border border-gray-100 p-4">
          <div className="h-4 w-48 animate-pulse rounded bg-gray-100" />
          <div className="mt-2 h-3 w-32 animate-pulse rounded bg-gray-100" />
          <div className="mt-3 h-3 w-full max-w-sm animate-pulse rounded bg-gray-100" />
        </div>
      ))}
    </div>
  );
}

function ChangeRow({ label, children }) {
  return (
    <div className="text-sm">
      <p className="text-gray-500">{label}</p>
      <p className="break-words text-gray-900">{children}</p>
    </div>
  );
}

function historyErrorMessage(e) {
  if (e instanceof ApiError) {
    if (e.status === 403) return 'Anda tidak memiliki izin untuk melihat riwayat aset ini.';
    if (e.status === 404) return 'Riwayat perubahan tidak ditemukan.';
    return e.message || 'Riwayat perubahan tidak dapat dimuat.';
  }
  return 'Riwayat perubahan tidak dapat dimuat.';
}

function HistoryEventCard({ event, isOperator, onRevertClick }) {
  const label = eventLabel(event);
  const isCreate = event.event_type === 'CREATE';
  const { before, after } = getEventSnapshots(event);
  const changes = isCreate ? [] : diffSnapshots(before, after);
  const summary = isCreate ? createSummary(after) : [];
  const status = statusTransition(event.event_type);
  const isBatch = Boolean(event.batch_operation_id);
  const nothingToShow = !status && changes.length === 0 && summary.length === 0;

  return (
    <li className="relative rounded-xl border border-gray-200 bg-white p-4 pl-6">
      <span
        className="absolute left-2 top-[1.35rem] h-2 w-2 rounded-full bg-gray-900"
        aria-hidden="true"
      />
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <p className="font-medium text-gray-900">{label}</p>
          {isBatch && (
            <span className="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200">
              Perubahan massal
            </span>
          )}
          {event.already_reverted && (
            <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-200">
              Sudah di-revert
            </span>
          )}
        </div>
        {/* Prefer not showing an actionable button when the operation cannot be
            performed at all — never a disabled button with no explanation. */}
        {isOperator && event.can_revert && (
          <button
            type="button"
            onClick={() => onRevertClick(event)}
            className="shrink-0 rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-gray-400"
          >
            Revert
          </button>
        )}
      </div>
      <p className="mt-0.5 text-xs text-gray-500">
        oleh {event.performed_by?.name ?? 'Sistem'} · {formatDateTime(event.created_at)}
      </p>

      <div className="mt-2.5 space-y-2">
        {status && (
          <ChangeRow label={status.label}>
            {status.before} → {status.after}
          </ChangeRow>
        )}
        {summary.map((row) => (
          <ChangeRow key={row.field} label={row.label}>
            {row.value}
          </ChangeRow>
        ))}
        {changes.map((row) => (
          <ChangeRow key={row.field} label={row.label}>
            {row.before} → {row.after}
          </ChangeRow>
        ))}
        {nothingToShow && <p className="text-sm text-gray-400">Tidak ada rincian perubahan.</p>}
      </div>
    </li>
  );
}

export default function AssetHistorySection({
  assetId,
  refreshSignal,
  isOperator = false,
  onReverted,
  initialFetch = null,
}) {
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [events, setEvents] = useState([]);
  const [meta, setMeta] = useState(null);
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);
  const [loadingMore, setLoadingMore] = useState(false);

  const [revertTarget, setRevertTarget] = useState(null); // the event being confirmed
  const [flash, setFlash] = useState('');

  // One-shot: consume the parent's prefetched promise on this section's very
  // first load only. Every subsequent effect run (refreshSignal/retryKey
  // change) falls through to this section's own normal fetch, unchanged.
  const consumedInitialFetch = useRef(false);

  useEffect(() => {
    let alive = true;
    setPhase('loading');
    setError('');

    const request =
      !consumedInitialFetch.current && initialFetch
        ? initialFetch
        : getAssetMutations(assetId, { page: 1 });
    consumedInitialFetch.current = true;

    request
      .then((res) => {
        if (!alive) return;
        setEvents(res?.data ?? []);
        setMeta(res?.meta ?? null);
        setPhase('ready');
      })
      .catch((e) => {
        if (!alive) return;
        setError(historyErrorMessage(e));
        setPhase('error');
      });

    return () => {
      alive = false;
    };
  }, [assetId, refreshSignal, retryKey]);

  const loadMore = useCallback(async () => {
    if (!meta || meta.current_page >= meta.last_page) return;
    setLoadingMore(true);
    try {
      const res = await getAssetMutations(assetId, { page: meta.current_page + 1 });
      setEvents((current) => [...current, ...(res?.data ?? [])]);
      setMeta(res?.meta ?? meta);
    } catch {
      // "load more" failing isn't a page-level error — keep what's already shown.
    } finally {
      setLoadingMore(false);
    }
  }, [assetId, meta]);

  const hasMore = meta && meta.current_page < meta.last_page;

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  const handleRevertSuccess = (res) => {
    setRevertTarget(null);
    setFlash(res?.message || 'Mutasi berhasil di-revert.');
    setRetryKey((k) => k + 1); // reload this asset's own history
    onReverted?.(res?.assets ?? []);
  };

  return (
    <section className="overflow-hidden rounded-xl border border-gray-200 bg-white">
      <div className="border-b border-gray-100 px-4 py-3">
        <h2 className="text-sm font-semibold text-gray-700">Riwayat Perubahan</h2>
        <p className="mt-0.5 text-xs text-gray-500">Catatan perubahan aset dari waktu ke waktu.</p>
      </div>

      <div className="p-4">
        {flash && (
          <div
            role="status"
            className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800"
          >
            {flash}
          </div>
        )}

        {phase === 'loading' && <HistorySkeleton />}

        {phase === 'error' && (
          <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-4 text-center text-sm">
            <p className="text-red-700">{error}</p>
            <button
              type="button"
              onClick={() => setRetryKey((k) => k + 1)}
              className="mt-2 rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100"
            >
              Coba lagi
            </button>
          </div>
        )}

        {phase === 'ready' && events.length === 0 && (
          <p className="text-sm text-gray-500">Belum ada riwayat perubahan.</p>
        )}

        {phase === 'ready' && events.length > 0 && (
          <>
            <ol className="space-y-3">
              {events.map((event) => (
                <HistoryEventCard
                  key={event.id}
                  event={event}
                  isOperator={isOperator}
                  onRevertClick={setRevertTarget}
                />
              ))}
            </ol>
            {hasMore && (
              <div className="mt-3 text-center">
                <button
                  type="button"
                  onClick={loadMore}
                  disabled={loadingMore}
                  className="rounded-md border border-gray-300 px-3.5 py-1.5 text-sm font-medium text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {loadingMore ? 'Memuat…' : 'Muat lebih banyak'}
                </button>
              </div>
            )}
          </>
        )}
      </div>

      <RevertConfirmDialog
        open={revertTarget !== null}
        event={revertTarget}
        onClose={() => setRevertTarget(null)}
        onSuccess={handleRevertSuccess}
      />
    </section>
  );
}
