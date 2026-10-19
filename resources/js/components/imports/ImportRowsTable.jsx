import { ASSET_SOURCES } from '../../lib/assetSources';
import { importRowMessageText } from '../../lib/importMessages';

/**
 * Row-level preview table for one import batch (Tahap 6.1). Mirrors the visual
 * language of `components/inventory/InventoryTable.jsx` (same header/cell classes)
 * so the Import Excel page doesn't introduce a second table style.
 *
 * R9.4-17: row messages are shown in Indonesian via importRowMessageText()
 * (keyed by the stored `code`); the stored English message is untouched.
 *
 * Tahap 2 — the Sumber column shows what promotion would store for each row and,
 * for rows not yet promoted (and not Error), a select to correct it in place.
 * The choice is saved server-side via `onResolveSource(row, value)`; an
 * unrecognised value is shown verbatim and is never treated as Yayasan.
 */

const STATUS_BADGE = {
  valid: { label: 'Valid', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  warning: { label: 'Warning', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
  error: { label: 'Error', className: 'bg-red-50 text-red-700 ring-red-200' },
  pending: { label: 'Pending', className: 'bg-gray-100 text-gray-600 ring-gray-200' },
};

function StatusBadge({ status, isDuplicate }) {
  const meta = STATUS_BADGE[status] ?? STATUS_BADGE.pending;

  return (
    <span className="inline-flex flex-col gap-1">
      <span
        className={`inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${meta.className}`}
      >
        {meta.label}
      </span>
      {isDuplicate && (
        <span className="inline-flex w-fit items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 ring-1 ring-inset ring-gray-200">
          Duplikat
        </span>
      )}
    </span>
  );
}

// R9.4-02/11 — only manual mappings get a note (automatic exact-name/alias
// matches stay unlabelled, as before); who/when come from the resolution record.
const MANUAL_METHOD_LABEL = {
  manual: 'Dipetakan manual (import ini saja)',
  manual_alias: 'Dipetakan manual · alias permanen',
};

function ManualMappingNote({ row }) {
  const label = MANUAL_METHOD_LABEL[row.room_match_method];
  if (!label) return null;
  const resolution = row.room_mapping_resolution;

  return (
    <span className="mt-0.5 block text-[11px] text-gray-500">
      {label}
      {resolution?.resolved_by && ` · oleh ${resolution.resolved_by.name}`}
      {resolution?.resolved_at && `, ${new Date(resolution.resolved_at).toLocaleString('id-ID')}`}
    </span>
  );
}

const SOURCE_STATUS_NOTE = {
  missing: 'Kolom SUMBER kosong',
  column_absent: 'File tanpa kolom SUMBER',
};

function SourceCell({ row, onResolveSource, busy }) {
  const unrecognized = row.asset_source_status === 'unrecognized';
  const canCorrect = onResolveSource && row.promoted_asset_id == null && row.validation_status !== 'error';
  const resolution = row.asset_source_resolution;

  return (
    <div className="space-y-1">
      {unrecognized ? (
        <span className="block text-amber-800">
          “{row.asset_source_raw}” <span className="text-xs">(tidak dikenali)</span>
        </span>
      ) : (
        <span className="block text-gray-700">{row.asset_source_label ?? '—'}</span>
      )}
      {row.asset_source_status === 'corrected' ? (
        <span className="block text-[11px] text-gray-500">
          Dipilih di pratinjau
          {resolution?.resolved_by && ` · oleh ${resolution.resolved_by.name}`}
          {row.asset_source_raw && ` · nilai file: “${row.asset_source_raw}”`}
        </span>
      ) : (
        SOURCE_STATUS_NOTE[row.asset_source_status] && (
          <span className="block text-[11px] text-gray-500">{SOURCE_STATUS_NOTE[row.asset_source_status]}</span>
        )
      )}
      {canCorrect && (
        <select
          value=""
          disabled={busy}
          onChange={(e) => e.target.value && onResolveSource(row, e.target.value)}
          aria-label={`Pilih sumber untuk baris ${row.row_number}`}
          className={[
            'w-full max-w-[12rem] rounded-md border px-2 py-1 text-xs outline-none focus:ring-1',
            unrecognized
              ? 'border-amber-400 focus:border-amber-500 focus:ring-amber-500'
              : 'border-gray-300 focus:border-gray-900 focus:ring-gray-900',
          ].join(' ')}
        >
          <option value="">{unrecognized ? 'Pilih sumber yang sah…' : 'Ubah sumber…'}</option>
          {ASSET_SOURCES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </select>
      )}
    </div>
  );
}

function SkeletonRows() {
  return Array.from({ length: 6 }).map((_, i) => (
    <tr key={i} className="border-t border-gray-100">
      {Array.from({ length: 6 }).map((__, j) => (
        <td key={j} className="px-4 py-3">
          <span className="block h-3 w-full max-w-[10rem] animate-pulse rounded bg-gray-100" />
        </td>
      ))}
    </tr>
  ));
}

export default function ImportRowsTable({ rows, loading, onResolveSource = null, sourceBusyRowId = null }) {
  return (
    <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[860px] text-sm">
          <thead>
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
              <th className="px-4 py-3">Baris</th>
              <th className="px-4 py-3">Identitas</th>
              <th className="px-4 py-3">Ruangan</th>
              <th className="px-4 py-3">Sumber</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Catatan</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <SkeletonRows />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-sm text-gray-400">
                  Tidak ada baris untuk filter ini.
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-t border-gray-100 hover:bg-gray-50/60">
                  <td className="px-4 py-3 text-gray-500">{row.row_number}</td>
                  <td className="px-4 py-3">
                    <span className="block font-mono text-[13px] font-medium text-gray-900">
                      {row.identity}
                    </span>
                    {row.brand_model && (
                      <span className="block text-xs text-gray-500">{row.brand_model}</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    {row.matched_room_name ? (
                      <>
                        <span className="block text-gray-700">{row.matched_room_name}</span>
                        <ManualMappingNote row={row} />
                      </>
                    ) : (
                      <span className="text-gray-400">
                        {row.room_raw_value ? `${row.room_raw_value} (belum dipetakan)` : 'Belum diisi'}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <SourceCell row={row} onResolveSource={onResolveSource} busy={sourceBusyRowId === row.id} />
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={row.validation_status} isDuplicate={row.is_duplicate} />
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-500">
                    {row.validation_messages.length === 0 ? (
                      <span className="text-gray-300">—</span>
                    ) : (
                      <ul className="list-inside list-disc space-y-0.5">
                        {row.validation_messages.map((m, i) => (
                          <li key={i}>{importRowMessageText(m)}</li>
                        ))}
                      </ul>
                    )}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
