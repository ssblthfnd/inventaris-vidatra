/**
 * Filter state <-> URL query string <-> `GET /api/reports/inventory` query
 * string, for the Reporting page (Tahap 6.3).
 *
 * Deliberately a SEPARATE (small) module from `lib/inventoryQuery.js` rather
 * than extending it: the Reporting page has no sort/pagination, and adds
 * `asset_year` (already a real `AssetIndexRequest` filter, but never surfaced
 * in any UI before this stage) without touching the Inventaris page's own
 * state module. The array-filter *encoding* (`key[]=a&key[]=b`) and semantics
 * (OR within, AND between) are identical to Inventaris — same backend
 * FormRequest, same interpretation.
 */

export const ARRAY_FILTERS = [
  'location_code',
  'category_code',
  'subcategory_code',
  'room_id',
  'condition',
  'is_written_off',
  'asset_year',
];

/** Read a `URLSearchParams` into a normalised state object. */
export function parseReportQuery(searchParams) {
  const state = {};
  for (const key of ARRAY_FILTERS) {
    const values = searchParams.getAll(`${key}[]`);
    // de-dupe defensively; the API rejects duplicates with 422
    state[key] = [...new Set(values.filter((v) => v !== ''))];
  }
  return state;
}

/** Serialise state to a query string (no leading `?`). Empty filters are omitted. */
export function buildReportQuery(state) {
  const params = new URLSearchParams();
  for (const key of ARRAY_FILTERS) {
    for (const value of state[key] ?? []) params.append(`${key}[]`, value);
  }
  return params.toString();
}

/** True when any filter is active. */
export function hasActiveReportFilters(state) {
  return ARRAY_FILTERS.some((key) => (state[key] ?? []).length > 0);
}

/** An empty-but-valid state, used by "Reset semua". */
export function emptyReportState() {
  const state = {};
  for (const key of ARRAY_FILTERS) state[key] = [];
  return state;
}
