import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import AssetHistorySection from '../components/asset-detail/AssetHistorySection';
import LifecycleConfirmDialog from '../components/LifecycleConfirmDialog';
import PrintLabelMenu from '../components/PrintLabelMenu';
import { api, ApiError } from '../lib/api';
import { printAssetLabel } from '../lib/labels';

/**
 * Asset Detail page — `/inventory/:assetId` (Tahap 5.8.2, lifecycle Tahap 5.8.5).
 *
 * Read for everyone; lifecycle actions (write-off / unwrite-off / soft delete /
 * restore) for operator + admin, each behind a confirmation dialog and the existing
 * backend endpoints. Soft delete and write-off are kept strictly separate:
 *  - soft delete  → row survives, hidden from the active list, `is_trashed = true`,
 *    restorable (same id / code / sequence).
 *  - write-off    → still an active row, `is_written_off = true`.
 */

const CONDITION = {
  baik: { label: 'Baik', dot: 'bg-emerald-500', text: 'text-emerald-700', ring: 'ring-emerald-200' },
  kurang_baik: { label: 'Kurang Baik', dot: 'bg-amber-500', text: 'text-amber-700', ring: 'ring-amber-200' },
  rusak_berat: { label: 'Rusak Berat', dot: 'bg-red-500', text: 'text-red-700', ring: 'ring-red-200' },
};
const CONDITION_UNKNOWN = {
  label: 'Belum diisi',
  dot: 'bg-gray-300',
  text: 'text-gray-600',
  ring: 'ring-gray-200',
};

const MISSING = 'Belum diisi';

const todayISO = () => new Date().toISOString().slice(0, 10);

const formatDate = (iso) => {
  if (!iso) return null;
  const d = new Date(`${iso}T00:00:00`);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
};

function ConditionBadge({ value }) {
  const meta = CONDITION[value] ?? CONDITION_UNKNOWN;
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${meta.ring} ${meta.text}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} aria-hidden="true" />
      {meta.label}
    </span>
  );
}

function StatusBadge({ trashed, writtenOff }) {
  if (trashed) {
    return (
      <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300">
        Di Trash
      </span>
    );
  }
  if (writtenOff) {
    return (
      <span className="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-200">
        Write-off
      </span>
    );
  }
  return (
    <span className="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200">
      Aktif
    </span>
  );
}

const statusText = (asset) =>
  asset.is_trashed ? 'Di Trash' : asset.is_written_off ? 'Write-off' : 'Aktif';

/** One label / value row inside a section's <dl>. */
function Row({ label, children }) {
  const empty = children == null || children === '';
  return (
    <div className="grid grid-cols-1 gap-0.5 px-4 py-3 sm:grid-cols-[minmax(0,9rem)_minmax(0,1fr)] sm:gap-4">
      <dt className="text-sm text-gray-500">{label}</dt>
      <dd className={`text-sm ${empty ? 'text-gray-400' : 'text-gray-900'}`}>
        {empty ? MISSING : children}
      </dd>
    </div>
  );
}

function Section({ title, children, list = true }) {
  return (
    <section className="overflow-hidden rounded-xl border border-gray-200 bg-white">
      <h2 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">
        {title}
      </h2>
      {list ? <dl className="divide-y divide-gray-100">{children}</dl> : children}
    </section>
  );
}

function BackLink({ to }) {
  return (
    <Link
      to={to}
      className="inline-flex items-center gap-1.5 rounded text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
    >
      <span aria-hidden="true">←</span> Kembali ke Inventaris
    </Link>
  );
}

const actionBtn =
  'inline-flex shrink-0 items-center rounded-lg border px-3 py-1.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2';

function DetailSkeleton() {
  return (
    <div className="mx-auto max-w-4xl space-y-6" aria-hidden="true">
      <div className="h-4 w-40 animate-pulse rounded bg-gray-100" />
      <div className="space-y-3">
        <div className="h-3 w-20 animate-pulse rounded bg-gray-100" />
        <div className="h-7 w-72 max-w-full animate-pulse rounded bg-gray-100" />
        <div className="h-4 w-40 animate-pulse rounded bg-gray-100" />
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        {[0, 1].map((i) => (
          <div key={i} className="rounded-xl border border-gray-200 bg-white p-4">
            <div className="mb-4 h-4 w-32 animate-pulse rounded bg-gray-100" />
            <div className="space-y-3">
              {[0, 1, 2, 3].map((j) => (
                <div key={j} className="h-3 w-full animate-pulse rounded bg-gray-100" />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function CenteredState({ backTo, title, message, action }) {
  return (
    <div className="mx-auto max-w-2xl">
      <BackLink to={backTo} />
      <div className="mt-6 rounded-xl border border-gray-200 bg-white px-6 py-14 text-center">
        <h1 className="text-base font-semibold text-gray-900">{title}</h1>
        <p className="mx-auto mt-1.5 max-w-sm text-sm text-gray-500">{message}</p>
        {action}
      </div>
    </div>
  );
}

export default function AssetDetail() {
  const { assetId } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const { refreshUser, isOperator } = useAuth();

  const [phase, setPhase] = useState('loading'); // loading | ready | notfound | forbidden | error
  const [asset, setAsset] = useState(null);
  const [attempt, setAttempt] = useState(0);
  const [flash, setFlash] = useState(
    location.state?.created
      ? 'Aset berhasil ditambahkan.'
      : location.state?.updated
        ? 'Perubahan aset berhasil disimpan.'
        : '',
  );

  // lifecycle dialog
  const [action, setAction] = useState(null); // null | 'delete' | 'writeoff' | 'unwriteoff' | 'restore'
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState('');
  const [woDate, setWoDate] = useState(todayISO());
  const [woNote, setWoNote] = useState('');

  // bump after every successful lifecycle action so AssetHistorySection reloads —
  // a history-refresh failure never implies the lifecycle action itself failed.
  const [historyRefreshKey, setHistoryRefreshKey] = useState(0);

  // label PDF generation (Tahap 6.0) — not a lifecycle dialog, just a background
  // download; only needs its own busy flag + inline error.
  const [labelBusy, setLabelBusy] = useState(false);
  const [labelError, setLabelError] = useState('');

  const from = location.state?.from;
  const backTo = typeof from === 'string' && from ? `/inventory?${from}` : '/inventory';

  useEffect(() => {
    let alive = true;
    setPhase('loading');

    api
      .get(`/api/assets/${encodeURIComponent(assetId)}`)
      .then((res) => {
        if (!alive) return;
        setAsset(res?.data ?? null);
        setPhase('ready');
      })
      .catch((e) => {
        if (!alive) return;
        if (e instanceof ApiError && e.status === 401) {
          refreshUser();
          return;
        }
        if (e instanceof ApiError && e.status === 403) setPhase('forbidden');
        else if (e instanceof ApiError && e.status === 404) setPhase('notfound');
        else setPhase('error');
      });

    return () => {
      alive = false;
    };
  }, [assetId, attempt, refreshUser]);

  const retry = useCallback(() => setAttempt((n) => n + 1), []);

  const closeDialog = useCallback(() => {
    if (busy) return;
    setAction(null);
    setActionError('');
  }, [busy]);

  /**
   * Run a lifecycle request. `request()` either returns the fresh asset resource
   * (write-off / unwrite-off / restore) or resolves with nothing (soft delete → 204,
   * followed by a re-read so the trash view can render).
   */
  const runAction = async (request, successMsg, { refetch = false } = {}) => {
    setBusy(true);
    setActionError('');
    try {
      const res = await request();
      let next = res?.data ?? null;
      if (refetch || !next) {
        const fresh = await api.get(`/api/assets/${encodeURIComponent(assetId)}`);
        next = fresh?.data ?? null;
      }
      setBusy(false);
      setAction(null);
      if (next) setAsset(next);
      else setAttempt((n) => n + 1); // fall back to the full reload path
      setFlash(successMsg);
      setHistoryRefreshKey((n) => n + 1);
    } catch (e) {
      setBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 401) {
          refreshUser();
          return;
        }
        if (e.status === 404) {
          setAction(null);
          navigate(backTo);
          return;
        }
        if (e.status === 403) {
          setActionError('Anda tidak memiliki izin untuk tindakan ini.');
          return;
        }
        if (e.status === 409) {
          setActionError(e.message || 'Status aset sudah berubah. Muat ulang halaman.');
          return;
        }
        if (e.status === 422 && e.errors) {
          setActionError(Object.values(e.errors)[0]?.[0] ?? 'Data yang dikirim tidak valid.');
          return;
        }
        setActionError(e.message || 'Gagal memproses. Coba lagi.');
        return;
      }
      setActionError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  const doWriteOff = () =>
    runAction(
      () =>
        api.post(`/api/assets/${encodeURIComponent(assetId)}/write-off`, {
          written_off_on: woDate,
          written_off_note: woNote.trim() || null,
        }),
      'Aset ditandai write-off.',
    );
  const doUnwriteOff = () =>
    runAction(
      () => api.post(`/api/assets/${encodeURIComponent(assetId)}/unwrite-off`),
      'Status write-off dibatalkan.',
    );
  const doDelete = () =>
    runAction(
      () => api.delete(`/api/assets/${encodeURIComponent(assetId)}`),
      'Aset dipindahkan ke Trash.',
      { refetch: true },
    );
  const doRestore = () =>
    runAction(
      () => api.post(`/api/assets/${encodeURIComponent(assetId)}/restore`),
      'Aset berhasil dipulihkan.',
    );

  const doPrintLabel = async (size) => {
    setLabelBusy(true);
    setLabelError('');
    try {
      await printAssetLabel(assetId, size);
    } catch (e) {
      setLabelError(e?.message || 'Gagal membuat label. Coba lagi.');
    } finally {
      setLabelBusy(false);
    }
  };

  if (phase === 'loading') return <DetailSkeleton />;

  if (phase === 'notfound') {
    return (
      <CenteredState
        backTo={backTo}
        title="Aset tidak ditemukan"
        message="Aset yang Anda cari tidak tersedia atau mungkin sudah dihapus permanen."
      />
    );
  }
  if (phase === 'forbidden') {
    return (
      <CenteredState
        backTo={backTo}
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk melihat aset ini."
      />
    );
  }
  if (phase === 'error' || !asset) {
    return (
      <CenteredState
        backTo={backTo}
        title="Gagal memuat data aset"
        message="Terjadi kendala saat memuat data. Silakan coba lagi."
        action={
          <button
            type="button"
            onClick={retry}
            className="mt-4 rounded-md bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
          >
            Coba lagi
          </button>
        }
      />
    );
  }

  const name = asset.subcategory?.name ?? asset.category?.name ?? 'Aset';
  const roomLabel = asset.room?.name ?? null;
  const isTrashed = asset.is_trashed === true;

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <BackLink to={backTo} />

        {isOperator && !isTrashed && (
          <div className="flex flex-wrap gap-2">
            <Link
              to={`/inventory/${asset.id}/edit`}
              state={{ from }}
              className={`${actionBtn} border-gray-300 text-gray-700 hover:border-gray-400`}
            >
              Edit
            </Link>
            {asset.is_written_off ? (
              <button
                type="button"
                onClick={() => setAction('unwriteoff')}
                className={`${actionBtn} border-gray-300 text-gray-700 hover:border-gray-400`}
              >
                Batalkan Write-off
              </button>
            ) : (
              <button
                type="button"
                onClick={() => {
                  setWoDate(todayISO());
                  setWoNote('');
                  setAction('writeoff');
                }}
                className={`${actionBtn} border-gray-300 text-gray-700 hover:border-gray-400`}
              >
                Tandai Write-off
              </button>
            )}
            <button
              type="button"
              onClick={() => setAction('delete')}
              className={`${actionBtn} border-red-200 text-red-700 hover:border-red-300 hover:bg-red-50`}
            >
              Hapus Aset
            </button>
            <PrintLabelMenu
              onSelect={doPrintLabel}
              busy={labelBusy}
              align="right"
              buttonClassName={`${actionBtn} border-gray-300 text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed disabled:opacity-60`}
            />
          </div>
        )}

        {isOperator && isTrashed && (
          <button
            type="button"
            onClick={() => setAction('restore')}
            className={`${actionBtn} border-gray-900 bg-gray-900 text-white hover:bg-gray-800`}
          >
            Restore Aset
          </button>
        )}
      </div>

      {flash && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">
          {flash}
        </div>
      )}

      {labelError && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
          {labelError}
        </div>
      )}

      {isTrashed && (
        <div className="rounded-xl border border-gray-300 bg-gray-100 px-4 py-3 text-sm">
          <p className="font-medium text-gray-900">Aset berada di Trash</p>
          <p className="mt-1 text-gray-600">
            Record ini masih tersimpan di sistem (kode aset {asset.asset_code}, nomor urut{' '}
            {asset.sequence_no}) dan dapat dipulihkan oleh operator/admin. Kode aset tidak akan
            digunakan ulang.
          </p>
        </div>
      )}

      {/* identity */}
      <header className="space-y-2">
        <p className="text-xs font-medium uppercase tracking-wide text-gray-400">Kode Aset</p>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
          <h1 className="break-all font-mono text-xl font-semibold text-gray-900 sm:text-2xl">
            {asset.asset_code}
          </h1>
          <ConditionBadge value={asset.condition} />
          <StatusBadge trashed={isTrashed} writtenOff={asset.is_written_off} />
        </div>
        <div>
          <p className="text-lg text-gray-900">{name}</p>
          {asset.brand_model && <p className="text-sm text-gray-500">{asset.brand_model}</p>}
        </div>
      </header>

      {asset.is_written_off && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <p className="font-medium">Aset ini berstatus write-off.</p>
          <p className="mt-1 text-amber-800">
            {asset.written_off_on
              ? `Tanggal write-off: ${formatDate(asset.written_off_on)}`
              : 'Tanggal write-off tidak dicatat.'}
          </p>
          {asset.written_off_note && (
            <p className="mt-1 text-amber-800">Catatan: {asset.written_off_note}</p>
          )}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Informasi Aset">
          <Row label="Kategori">{asset.category?.name}</Row>
          <Row label="Subkategori">{asset.subcategory?.name}</Row>
          <Row label="Lokasi">{asset.location?.name}</Row>
          <Row label="Ruangan">
            {roomLabel ?? (
              <span className="text-gray-400">
                Belum dipetakan
                {asset.room_raw_value && (
                  <span className="block text-xs">Data asal: {asset.room_raw_value}</span>
                )}
              </span>
            )}
          </Row>
          <Row label="Tahun">{asset.asset_year}</Row>
          <Row label="Nomor Urut">{asset.sequence_no}</Row>
        </Section>

        <Section title="Spesifikasi">
          <Row label="Merek / Model">{asset.brand_model}</Row>
          <Row label="Nomor Seri">{asset.serial_no}</Row>
          <Row label="Bahan">{asset.material}</Row>
          <Row label="Tanggal Pembelian">{formatDate(asset.purchase_date)}</Row>
          <Row label="Sumber Dana">{asset.funding_source}</Row>
          {asset.detail_type && <Row label="Jenis / Tipe">{asset.detail_type}</Row>}
          {asset.capacity_note && <Row label="Kapasitas">{asset.capacity_note}</Row>}
          <Row label="Jumlah">{asset.quantity != null ? `${asset.quantity} unit` : null}</Row>
        </Section>

        <Section title="Kondisi & Status">
          <Row label="Kondisi">
            <span className="inline-flex items-center gap-1.5">
              <span
                className={`h-1.5 w-1.5 rounded-full ${(CONDITION[asset.condition] ?? CONDITION_UNKNOWN).dot}`}
                aria-hidden="true"
              />
              {(CONDITION[asset.condition] ?? CONDITION_UNKNOWN).label}
            </span>
          </Row>
          <Row label="Status">{statusText(asset)}</Row>
        </Section>

        {asset.notes && (
          <div className="lg:col-span-2">
            <Section title="Catatan" list={false}>
              <p className="px-4 py-3 text-sm whitespace-pre-line text-gray-900">{asset.notes}</p>
            </Section>
          </div>
        )}
      </div>

      <AssetHistorySection
        assetId={asset.id}
        refreshSignal={historyRefreshKey}
        isOperator={isOperator}
        onReverted={(reverted) => {
          const updated = reverted.find((a) => a.id === asset.id);
          if (updated) setAsset(updated);
        }}
      />

      {/* ---- lifecycle dialogs ---- */}
      <LifecycleConfirmDialog
        open={action === 'delete'}
        title="Hapus aset ini?"
        tone="danger"
        confirmLabel="Hapus Aset"
        busy={busy}
        error={actionError}
        onConfirm={doDelete}
        onClose={closeDialog}
      >
        <p>
          Aset <span className="font-mono text-gray-900">{asset.asset_code}</span> akan dihapus dari
          daftar aset aktif.
        </p>
        <p>
          Data <span className="font-medium text-gray-900">tidak</span> dihapus dari database —
          record tetap tersimpan dan dapat dipulihkan oleh operator/admin. Nomor aset tidak akan
          digunakan ulang.
        </p>
      </LifecycleConfirmDialog>

      <LifecycleConfirmDialog
        open={action === 'writeoff'}
        title="Tandai aset sebagai write-off?"
        confirmLabel="Tandai Write-off"
        busy={busy}
        error={actionError}
        confirmDisabled={!woDate}
        onConfirm={doWriteOff}
        onClose={closeDialog}
      >
        <p>
          Write-off <span className="font-medium text-gray-900">tidak</span> menghapus record aset
          dari sistem. Aset tetap tercatat, statusnya menjadi <span className="font-medium">Write-off</span>.
        </p>
        <div className="pt-1">
          <label htmlFor="wo-date" className="mb-1 block text-xs font-medium text-gray-700">
            Tanggal write-off <span className="text-red-600">*</span>
          </label>
          <input
            id="wo-date"
            type="date"
            value={woDate}
            max={todayISO()}
            onChange={(e) => setWoDate(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900"
          />
        </div>
        <div>
          <label htmlFor="wo-note" className="mb-1 block text-xs font-medium text-gray-700">
            Catatan (opsional)
          </label>
          <textarea
            id="wo-note"
            rows={2}
            value={woNote}
            maxLength={255}
            onChange={(e) => setWoNote(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900"
          />
        </div>
      </LifecycleConfirmDialog>

      <LifecycleConfirmDialog
        open={action === 'unwriteoff'}
        title="Batalkan status write-off?"
        confirmLabel="Batalkan Write-off"
        busy={busy}
        error={actionError}
        onConfirm={doUnwriteOff}
        onClose={closeDialog}
      >
        <p>
          Status write-off akan dibatalkan dan aset kembali berstatus aktif. Kode aset dan nomor
          urut tidak berubah.
        </p>
      </LifecycleConfirmDialog>

      <LifecycleConfirmDialog
        open={action === 'restore'}
        title="Pulihkan aset dari Trash?"
        confirmLabel="Restore Aset"
        busy={busy}
        error={actionError}
        onConfirm={doRestore}
        onClose={closeDialog}
      >
        <p>
          Pulihkan aset ini ke daftar aset aktif? Record yang sama akan aktif kembali — ID, kode
          aset <span className="font-mono text-gray-900">{asset.asset_code}</span>, dan nomor urut
          tetap sama.
        </p>
      </LifecycleConfirmDialog>
    </div>
  );
}
