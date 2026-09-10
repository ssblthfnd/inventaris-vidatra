/**
 * Single source of truth for the Inventaris list state <-> URL query string
 * <-> `GET /api/assets` query string (Tahap 5.8.1).
 *
 * The browser URL and the API request use the *same* encoding, so a shared /
 * bookmarked `/inventory?...` link maps 1:1 onto the API call. Array filters use
 * Laravel's natural `key[]=a&key[]=b` syntax (repeated bare keys are NOT parsed as
 * an array by Laravel).
 */

export const ARRAY_FILTERS = [
  'location_code',
  'category_code',
  'subcategory_code',
  'room_id',
  'condition',
  'is_written_off',
];

export const SORTS = [
  { value: 'asset_code:asc', label: 'Kode aset (A–Z)', sort: 'asset_code', direction: 'asc' },
  { value: 'asset_code:desc', label: 'Kode aset (Z–A)', sort: 'asset_code', direction: 'desc' },
  { value: 'asset_year:desc', label: 'Tahun terbaru', sort: 'asset_year', direction: 'desc' },
  { value: 'asset_year:asc', label: 'Tahun terlama', sort: 'asset_year', direction: 'asc' },
  { value: 'created_at:desc', label: 'Terakhir ditambahkan', sort: 'created_at', direction: 'desc' },
];

export const PER_PAGE_OPTIONS = [20, 50, 100];

export const DEFAULTS = {
  q: '',
  sort: 'asset_code',
  direction: 'asc',
  page: 1,
  per_page: 20,
};

function clampInt(value, min, max, fallback) {
  const n = Number.parseInt(value, 10);
  if (!Number.isFinite(n)) return fallback;
  if (n < min) return min;
  if (max != null && n > max) return max;
  return n;
}

/** Read a `URLSearchParams` into a normalised state object. */
export function parseQuery(searchParams) {
  const state = {
    q: searchParams.get('q')?.slice(0, 100) ?? '',
    sort: DEFAULTS.sort,
    direction: DEFAULTS.direction,
    page: clampInt(searchParams.get('page'), 1, null, 1),
    per_page: PER_PAGE_OPTIONS.includes(clampInt(searchParams.get('per_page'), 1, 100, 20))
      ? clampInt(searchParams.get('per_page'), 1, 100, 20)
      : 20,
  };

  const sort = searchParams.get('sort');
  const direction = searchParams.get('direction');
  if (SORTS.some((s) => s.sort === sort)) state.sort = sort;
  if (direction === 'asc' || direction === 'desc') state.direction = direction;

  for (const key of ARRAY_FILTERS) {
    const values = searchParams.getAll(`${key}[]`);
    // de-dupe defensively; the API rejects duplicates with 422
    state[key] = [...new Set(values.filter((v) => v !== ''))];
  }

  return state;
}

/**
 * Serialise state to a query string (no leading `?`). Default / empty values are
 * omitted so the URL stays short and shareable.
 */
export function buildQuery(state) {
  const params = new URLSearchParams();

  if (state.q?.trim()) params.set('q', state.q.trim());

  for (const key of ARRAY_FILTERS) {
    for (const value of state[key] ?? []) params.append(`${key}[]`, value);
  }

  if (state.sort && state.sort !== DEFAULTS.sort) params.set('sort', state.sort);
  if (state.direction && state.direction !== DEFAULTS.direction) params.set('direction', state.direction);
  if (state.per_page && state.per_page !== DEFAULTS.per_page) params.set('per_page', String(state.per_page));
  if (state.page && state.page > 1) params.set('page', String(state.page));

  return params.toString();
}

export const sortValue = (state) => `${state.sort}:${state.direction}`;

/** True when any real filter (search included) is active. */
export function hasActiveFilters(state) {
  if (state.q?.trim()) return true;
  return ARRAY_FILTERS.some((key) => (state[key] ?? []).length > 0);
}

/** An empty-but-valid state, used by "Reset semua". */
export function emptyState() {
  const state = { ...DEFAULTS };
  for (const key of ARRAY_FILTERS) state[key] = [];
  return state;
}
