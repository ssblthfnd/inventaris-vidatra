import { api } from './api';

/**
 * Mutation/audit history helpers for the Asset Detail page (Tahap 5.8.9).
 *
 * `GET /api/assets/{asset}/mutations` is the ONLY history endpoint — this file is a
 * thin, typed-in-spirit wrapper around it plus pure presentation helpers (event
 * labels, field labels, before/after diffing). No history is ever computed from
 * anything other than the API response; nothing here talks to the database.
 */

/** Canonical event_type (Tahap 5.8.8) -> Indonesian label. */
export const EVENT_LABELS = {
  CREATE: 'Aset dibuat',
  EDIT: 'Data aset diubah',
  MOVE_ROOM: 'Ruangan dipindahkan',
  WRITE_OFF: 'Aset di-write-off',
  UNWRITE_OFF: 'Write-off dibatalkan',
  SOFT_DELETE: 'Aset dipindahkan ke Trash',
  RESTORE: 'Aset dipulihkan',
  BATCH_EDIT: 'Perubahan massal',
  BATCH_DELETE: 'Penghapusan massal',
};

/** Legacy `mutation_type` fallback, for the rare row with no `event_type` at all. */
const LEGACY_TYPE_LABELS = {
  pindah_ruangan: 'Ruangan dipindahkan',
};

/** Technical snapshot field -> Indonesian label. `id` / `room_id` / `deleted_at` are
 * deliberately absent — see SKIP_FIELDS below for why. */
export const FIELD_LABELS = {
  asset_code: 'Kode Aset',
  location_code: 'Lokasi',
  category_code: 'Kategori',
  subcategory_code: 'Subkategori',
  sequence_no: 'Nomor Urut',
  asset_year: 'Tahun Aset',
  room_name: 'Ruangan',
  room_raw_value: 'Data Ruangan Asal',
  brand_model: 'Merek / Model',
  detail_type: 'Tipe',
  serial_no: 'Nomor Seri',
  material: 'Material',
  capacity_note: 'Kapasitas',
  quantity: 'Jumlah',
  purchase_date: 'Tanggal Pembelian',
  funding_source: 'Sumber Dana',
  condition: 'Kondisi',
  notes: 'Catatan',
  is_written_off: 'Status Write-off',
  written_off_on: 'Tanggal Write-off',
  written_off_note: 'Catatan Write-off',
};

const CONDITION_LABELS = {
  baik: 'Baik',
  kurang_baik: 'Kurang Baik',
  rusak_berat: 'Rusak Berat',
};

const MISSING = 'Belum diisi';

/** Fields never shown as a raw before/after row — either meaningless to a user
 * (`id`), superseded by a friendlier field in the same snapshot (`room_id` ->
 * `room_name`), or given its own dedicated "Status" transition instead of a raw
 * timestamp diff (`deleted_at`). */
const SKIP_FIELDS = new Set(['id', 'room_id', 'deleted_at']);

/** Fields shown in the compact CREATE summary, in this order — not a full dump of
 * every snapshot field (see 5.8.9 spec: "keep the event compact"). */
const CREATE_SUMMARY_FIELDS = ['brand_model', 'location_code', 'room_name', 'condition'];

function formatDateOnly(value) {
  if (!value) return null;
  const d = new Date(`${value}T00:00:00`);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** Full date + time, Indonesian locale — for the event's own timestamp, not any
 * snapshot field. */
export function formatDateTime(iso) {
  if (!iso) return MISSING;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('id-ID', {
    day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

/** Human-readable value for one snapshot field. Never renders `null` / `undefined`
 * / `[object Object]` — falls back to "Belum diisi". */
function formatFieldValue(field, value) {
  if (value === null || value === undefined || value === '') return MISSING;
  if (field === 'condition') return CONDITION_LABELS[value] ?? value;
  if (field === 'is_written_off') return value ? 'Write-off' : 'Belum';
  if (field === 'quantity') return `${value} unit`;
  if (field === 'purchase_date' || field === 'written_off_on') return formatDateOnly(value) ?? MISSING;
  return String(value);
}

/**
 * Everything that actually differs between two snapshots, as
 * `{ field, label, before, after }` rows ready to render. Only fields present in
 * either snapshot are considered; unchanged fields are omitted entirely.
 */
export function diffSnapshots(before, after) {
  if (!before || !after) return [];
  const fields = new Set([...Object.keys(before), ...Object.keys(after)]);
  const rows = [];
  for (const field of fields) {
    if (SKIP_FIELDS.has(field)) continue;
    const b = before[field];
    const a = after[field];
    if (b === a) continue;
    rows.push({
      field,
      label: FIELD_LABELS[field] ?? field,
      before: formatFieldValue(field, b),
      after: formatFieldValue(field, a),
    });
  }
  return rows;
}

/** Compact CREATE-event summary — a handful of fields from `after`, not a full diff
 * (there is no `before` to diff against). */
export function createSummary(after) {
  if (!after) return [];
  return CREATE_SUMMARY_FIELDS.map((field) => ({
    field,
    label: FIELD_LABELS[field] ?? field,
    value: formatFieldValue(field, after[field]),
  }));
}

/** `{label:'Status', before, after}` for a lifecycle transition, or `null` when the
 * event type isn't one. Rendered separately from the generic diff because a raw
 * `deleted_at` timestamp diff would be unreadable. */
export function statusTransition(eventType) {
  if (eventType === 'SOFT_DELETE' || eventType === 'BATCH_DELETE') {
    return { label: 'Status', before: 'Aktif', after: 'Trash' };
  }
  if (eventType === 'RESTORE') {
    return { label: 'Status', before: 'Trash', after: 'Aktif' };
  }
  return null;
}

/**
 * The `{before, after}` snapshot pair to diff for one event row from the API.
 * Prefers the Tahap 5.8.8 `before_snapshot`/`after_snapshot` JSON; falls back to
 * the Tahap 5.5 `from`/`to`/`condition_before`/`condition_after` fields for the
 * handful of real historical rows that predate 5.8.8 (backfilled `event_type` but
 * no snapshot JSON — fabricating one would misrepresent what was actually
 * recorded). Returns `{before: null, after: null}` for CREATE (no prior state).
 */
export function getEventSnapshots(event) {
  if (event.before_snapshot || event.after_snapshot) {
    return { before: event.before_snapshot, after: event.after_snapshot };
  }
  if (event.event_type === 'CREATE') {
    return { before: null, after: null };
  }
  // Legacy pre-5.8.8 row: reconstruct a minimal, honest snapshot pair from the
  // fields that actually existed back then.
  return {
    before: {
      location_code: event.from?.location_code ?? null,
      room_name: event.from?.room_label ?? null,
      condition: event.condition_before ?? null,
    },
    after: {
      location_code: event.to?.location_code ?? null,
      room_name: event.to?.room_label ?? null,
      condition: event.condition_after ?? null,
    },
  };
}

export function eventLabel(event) {
  return EVENT_LABELS[event.event_type] ?? LEGACY_TYPE_LABELS[event.mutation_type] ?? 'Perubahan';
}

/**
 * `GET /api/assets/{asset}/mutations` — newest first (`created_at desc`, the
 * technical timestamp; every event this app writes happens in real time, so this
 * is a truer "newest first" than the business `mutation_date`, which is date-only).
 *
 * @param {number|string} assetId
 * @param {{page?: number, perPage?: number}} [options]
 */
export function getAssetMutations(assetId, { page = 1, perPage = 20 } = {}) {
  const params = new URLSearchParams({ sort: 'created_at', direction: 'desc' });
  if (page > 1) params.set('page', String(page));
  if (perPage !== 20) params.set('per_page', String(perPage));

  return api.get(`/api/assets/${encodeURIComponent(assetId)}/mutations?${params.toString()}`);
}
