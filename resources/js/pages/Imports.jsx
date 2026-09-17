import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { useAuth } from '../auth/AuthContext';
import ImportRowsTable from '../components/imports/ImportRowsTable';
import LifecycleConfirmDialog from '../components/LifecycleConfirmDialog';
import { ApiError } from '../lib/api';
import { CenteredState } from '../lib/assetFields';
import {
  IMPORT_STATUS_OPTIONS,
  IMPORT_TEMPLATE_CATEGORY_CODES,
  downloadImportTemplate,
  getImportBatch,
  getImportRows,
  listImportBatches,
  promoteImportBatch,
  uploadImportFile,
} from '../lib/imports';
import { useMasterData } from '../lib/useMasterData';

/**
 * Import Excel UI (Tahap 6.1) — `/imports`, operator/admin only.
 *
 * Follows the existing staging workflow end to end:
 *   Download Template -> Upload -> Stage/Preview -> Review -> Promote -> Result
 * Every number and status shown here comes straight from the API, which is itself a
 * thin layer over the pre-existing `App\Import` pipeline — this page never decides
 * what is valid/warning/error/duplicate, it only displays it.
 */

const BATCH_STATUS_LABEL = {
  uploaded: 'Diunggah',
  validating: 'Memvalidasi',
  validated: 'Tervalidasi',
  partially_imported: 'Sebagian Diimpor',
  imported: 'Sudah Diimpor',
  failed: 'Gagal',
  rolled_back: 'Dibatalkan',
};

function SummaryTile({ label, value, tone = 'default' }) {
  const toneClass = {
    default: 'text-gray-900',
    emerald: 'text-emerald-700',
    amber: 'text-amber-700',
    red: 'text-red-700',
  }[tone];

  return (
    <div className="rounded-xl border border-gray-200 bg-white px-4 py-3">
      <p className="text-xs font-medium uppercase tracking-wide text-gray-400">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${toneClass}`}>{value}</p>
    </div>
  );
}

export default function Imports() {
  const { canImport, canViewImportHistory } = useAuth();
  const { categories } = useMasterData();

  // Tahap 6.8.4: codes are the fixed backend constant (which categories the
  // template generator actually knows a column layout for); names/labels
  // and availability (only currently-ACTIVE categories) come live from
  // master data — no hardcoded category name lives in this page anymore.
  const templateCategoryOptions = useMemo(
    () =>
      categories
        .filter((c) => IMPORT_TEMPLATE_CATEGORY_CODES.includes(c.code))
        .map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` })),
    [categories],
  );

  const [category, setCategory] = useState('');
  useEffect(() => {
    if (category === '' && templateCategoryOptions.length > 0) {
      setCategory(templateCategoryOptions[0].value);
    }
  }, [category, templateCategoryOptions]);

  const [templateBusy, setTemplateBusy] = useState(false);
  const [templateError, setTemplateError] = useState('');

  const [uploadBusy, setUploadBusy] = useState(false);
  const [uploadError, setUploadError] = useState('');
  const fileInputRef = useRef(null);

  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success');

  const [batch, setBatch] = useState(null);
  const [batchLoading, setBatchLoading] = useState(false);

  const [rows, setRows] = useState([]);
  const [rowsMeta, setRowsMeta] = useState(null);
  const [rowsLoading, setRowsLoading] = useState(false);
  const [statusFilter, setStatusFilter] = useState('');
  const [rowsPage, setRowsPage] = useState(1);

  const [promoteOpen, setPromoteOpen] = useState(false);
  const [promoteBusy, setPromoteBusy] = useState(false);
  const [promoteError, setPromoteError] = useState('');
  const [promotionResult, setPromotionResult] = useState(null);

  const [history, setHistory] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(true);

  const loadHistory = useCallback(async () => {
    setHistoryLoading(true);
    try {
      const res = await listImportBatches({ page: 1 });
      setHistory(res?.data ?? []);
    } catch {
      // history is a convenience list; a failure here should not block the page
    } finally {
      setHistoryLoading(false);
    }
  }, []);

  useEffect(() => {
    if (canViewImportHistory) loadHistory();
  }, [canViewImportHistory, loadHistory]);

  const loadRows = useCallback(async (batchId, status, page) => {
    setRowsLoading(true);
    try {
      const res = await getImportRows(batchId, { status: status || undefined, page });
      setRows(res?.data ?? []);
      setRowsMeta(res?.meta ?? null);
    } catch (e) {
      setRows([]);
      setRowsMeta(null);
    } finally {
      setRowsLoading(false);
    }
  }, []);

  const openBatch = useCallback(
    async (batchId) => {
      setBatchLoading(true);
      setPromotionResult(null);
      setStatusFilter('');
      setRowsPage(1);
      try {
        const res = await getImportBatch(batchId);
        setBatch(res?.data ?? null);
        await loadRows(batchId, '', 1);
      } catch (e) {
        setFlashTone('error');
        setFlash(e?.message || 'Gagal memuat data import.');
      } finally {
        setBatchLoading(false);
      }
    },
    [loadRows],
  );

  const handleDownloadTemplate = async () => {
    if (!category) return;
    setTemplateBusy(true);
    setTemplateError('');
    try {
      await downloadImportTemplate(category);
    } catch (e) {
      setTemplateError(e?.message || 'Gagal mengunduh template. Coba lagi.');
    } finally {
      setTemplateBusy(false);
    }
  };

  const handleUpload = async () => {
    const file = fileInputRef.current?.files?.[0];
    if (!file) {
      setUploadError('Pilih file Excel (.xlsx) terlebih dahulu.');
      return;
    }
    setUploadBusy(true);
    setUploadError('');
    setFlash('');
    try {
      const res = await uploadImportFile(file);
      if (fileInputRef.current) fileInputRef.current.value = '';
      setFlashTone('success');
      setFlash(res?.message || 'File berhasil diunggah dan divalidasi.');
      await openBatch(res.data.id);
      if (canViewImportHistory) await loadHistory();
    } catch (e) {
      setUploadError(e?.message || 'Gagal mengunggah file. Coba lagi.');
    } finally {
      setUploadBusy(false);
    }
  };

  const handleFilterChange = (value) => {
    setStatusFilter(value);
    setRowsPage(1);
    if (batch) loadRows(batch.id, value, 1);
  };

  const handlePageChange = (nextPage) => {
    setRowsPage(nextPage);
    if (batch) loadRows(batch.id, statusFilter, nextPage);
  };

  const handlePromote = async () => {
    if (!batch) return;
    setPromoteBusy(true);
    setPromoteError('');
    try {
      const res = await promoteImportBatch(batch.id);
      setBatch(res.data);
      setPromotionResult(res.promotion);
      setPromoteOpen(false);
      await loadRows(batch.id, statusFilter, rowsPage);
      if (canViewImportHistory) await loadHistory();
    } catch (e) {
      setPromoteError(e?.message || 'Gagal mempromosikan batch. Coba lagi.');
    } finally {
      setPromoteBusy(false);
    }
  };

  if (!canImport) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk mengakses Import Excel."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }

  const promotable = batch ? batch.valid_rows + batch.warning_rows : 0;
  const alreadyImported = batch ? batch.imported_rows : 0;
  const canPromote = batch && promotable > alreadyImported;

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <header>
        <h1 className="text-lg font-semibold text-gray-900">Import Excel</h1>
        <p className="mt-1 text-sm text-gray-500">
          Unduh template, isi data aset, unggah untuk divalidasi, lalu promosikan ke Inventaris.
        </p>
      </header>

      {flash && (
        <div
          role="status"
          className={
            flashTone === 'error'
              ? 'rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800'
              : 'rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800'
          }
        >
          {flash}
        </div>
      )}

      {/* Step 1: template */}
      <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
        <h2 className="text-sm font-semibold text-gray-800">1. Unduh Template Excel</h2>
        <p className="mt-0.5 text-xs text-gray-500">
          Pilih kategori aset, unduh template, lalu isi sheet DATA sesuai panduan di dalamnya.
        </p>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <select
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            disabled={templateBusy || templateCategoryOptions.length === 0}
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 disabled:bg-gray-50"
          >
            {templateCategoryOptions.length === 0 && <option value="">Memuat kategori…</option>}
            {templateCategoryOptions.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
          <button
            type="button"
            onClick={handleDownloadTemplate}
            disabled={templateBusy || !category}
            className="rounded-lg border border-gray-300 px-3.5 py-2 text-sm font-medium text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {templateBusy ? 'Menyiapkan Template…' : 'Download Template Excel'}
          </button>
        </div>
        {templateError && <p className="mt-2 text-sm text-red-600">{templateError}</p>}
      </section>

      {/* Step 2: upload */}
      <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
        <h2 className="text-sm font-semibold text-gray-800">2. Unggah File yang Sudah Diisi</h2>
        <p className="mt-0.5 text-xs text-gray-500">
          Mengunggah file HANYA memvalidasi dan menyimpan data sementara — belum membuat aset baru.
        </p>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <input
            ref={fileInputRef}
            type="file"
            accept=".xlsx"
            disabled={uploadBusy}
            className="block text-sm text-gray-700 file:mr-3 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:border-gray-400 disabled:opacity-60"
          />
          <button
            type="button"
            onClick={handleUpload}
            disabled={uploadBusy}
            className="rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {uploadBusy ? 'Mengunggah…' : 'Upload & Validasi'}
          </button>
        </div>
        {uploadError && <p className="mt-2 text-sm text-red-600">{uploadError}</p>}
      </section>

      {/* Step 3: preview */}
      {batchLoading && (
        <div className="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-400">
          Memuat data import…
        </div>
      )}

      {batch && !batchLoading && (
        <section className="space-y-4">
          <div className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-sm font-semibold text-gray-800">{batch.source_filename}</h2>
                <p className="mt-0.5 text-xs text-gray-500">
                  {batch.category_name ?? batch.category_code ?? 'Kategori tidak dikenal'} · Status:{' '}
                  {BATCH_STATUS_LABEL[batch.status] ?? batch.status}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setPromoteOpen(true)}
                disabled={!canPromote}
                className="rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
              >
                Promote / Import ke Inventaris
              </button>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
              <SummaryTile label="Total" value={batch.total_rows} />
              <SummaryTile label="Valid" value={batch.valid_rows} tone="emerald" />
              <SummaryTile label="Warning" value={batch.warning_rows} tone="amber" />
              <SummaryTile label="Error" value={batch.error_rows} tone="red" />
              <SummaryTile label="Sudah Diimpor" value={batch.imported_rows} />
            </div>
          </div>

          {promotionResult && (
            <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
              <p className="font-medium">Hasil promosi</p>
              <p className="mt-1">
                Berhasil dipromosikan: {promotionResult.promoted} · Sudah dipromosikan sebelumnya:{' '}
                {promotionResult.skipped_already} · Gagal: {promotionResult.failed}
              </p>
              {promotionResult.errors.length > 0 && (
                <ul className="mt-2 list-inside list-disc space-y-0.5 text-xs text-emerald-900">
                  {promotionResult.errors.map((err, i) => (
                    <li key={i}>
                      Baris {err.row}: {err.message}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          <div className="flex flex-wrap gap-2">
            {IMPORT_STATUS_OPTIONS.map((opt) => (
              <button
                key={opt.value || 'all'}
                type="button"
                onClick={() => handleFilterChange(opt.value)}
                className={[
                  'rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                  statusFilter === opt.value
                    ? 'border-gray-900 bg-gray-900 text-white'
                    : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400',
                ].join(' ')}
              >
                {opt.label}
              </button>
            ))}
          </div>

          <ImportRowsTable rows={rows} loading={rowsLoading} />

          {rowsMeta && rowsMeta.total > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
              <span>
                Menampilkan {(rowsMeta.current_page - 1) * rowsMeta.per_page + 1}–
                {Math.min(rowsMeta.current_page * rowsMeta.per_page, rowsMeta.total)} dari {rowsMeta.total} baris
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => handlePageChange(rowsMeta.current_page - 1)}
                  disabled={rowsMeta.current_page <= 1}
                  className="rounded-md border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  ‹ Sebelumnya
                </button>
                <button
                  type="button"
                  onClick={() => handlePageChange(rowsMeta.current_page + 1)}
                  disabled={rowsMeta.current_page >= rowsMeta.last_page}
                  className="rounded-md border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  Selanjutnya ›
                </button>
              </div>
            </div>
          )}
        </section>
      )}

      {/* History — stays admin/operator-only (legacy can:operator Gate on
          GET /api/imports); a unit_admin gets real import access (R6) but
          not this cross-user, unscoped history browser (R6 "Scope Control"),
          and super_admin doesn't have it yet either (the same unmigrated
          Gate). Hidden entirely rather than shown-then-empty, since an empty
          list here would misleadingly read as "no imports yet" rather than
          "you can't see this". */}
      {canViewImportHistory && (
      <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
        <h2 className="text-sm font-semibold text-gray-800">Riwayat Import</h2>
        {historyLoading ? (
          <p className="mt-3 text-sm text-gray-400">Memuat riwayat…</p>
        ) : history.length === 0 ? (
          <p className="mt-3 text-sm text-gray-400">Belum ada riwayat import.</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                  <th className="px-3 py-2">File</th>
                  <th className="px-3 py-2">Kategori</th>
                  <th className="px-3 py-2">Status</th>
                  <th className="px-3 py-2">Total</th>
                  <th className="px-3 py-2">Diimpor</th>
                  <th className="px-3 py-2">Tanggal</th>
                </tr>
              </thead>
              <tbody>
                {history.map((b) => (
                  <tr
                    key={b.id}
                    onClick={() => openBatch(b.id)}
                    className="cursor-pointer border-t border-gray-100 hover:bg-gray-50/60"
                  >
                    <td className="px-3 py-2 font-medium text-gray-900">{b.source_filename}</td>
                    <td className="px-3 py-2 text-gray-700">{b.category_name ?? b.category_code ?? '—'}</td>
                    <td className="px-3 py-2 text-gray-700">{BATCH_STATUS_LABEL[b.status] ?? b.status}</td>
                    <td className="px-3 py-2 text-gray-700">{b.total_rows}</td>
                    <td className="px-3 py-2 text-gray-700">{b.imported_rows}</td>
                    <td className="px-3 py-2 text-gray-500">
                      {b.created_at ? new Date(b.created_at).toLocaleString('id-ID') : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      )}

      <LifecycleConfirmDialog
        open={promoteOpen}
        title="Promosikan batch ini ke Inventaris?"
        confirmLabel="Promote"
        busy={promoteBusy}
        error={promoteError}
        onConfirm={handlePromote}
        onClose={() => !promoteBusy && setPromoteOpen(false)}
      >
        {batch && (
          <>
            <p>
              File: <span className="font-medium text-gray-900">{batch.source_filename}</span>
            </p>
            <p>Total baris: {batch.total_rows}</p>
            <p>Valid: {batch.valid_rows} · Warning: {batch.warning_rows} · Error: {batch.error_rows}</p>
            <p>Sudah dipromosikan sebelumnya: {batch.imported_rows}</p>
            <p className="mt-2 text-xs text-gray-500">
              Baris berstatus Valid/Warning yang belum dipromosikan akan dibuat sebagai aset baru. Baris
              Error tidak akan diproses.
            </p>
          </>
        )}
      </LifecycleConfirmDialog>
    </div>
  );
}
