import { ApiError, api, ensureCsrfCookie, readCookie } from './api';

/**
 * Import Excel UI API helpers (Tahap 6.1).
 *
 * Two requests need their own path outside the shared `api` wrapper in `lib/api.js`
 * (same reasoning as `lib/labels.js`): the template download returns a binary Excel
 * file, not JSON, and the upload sends `multipart/form-data`, not a JSON body.
 * Everything else (list/show/rows/promote/report) is plain JSON and reuses `api`.
 */

export const IMPORT_STATUS_OPTIONS = [
  { value: '', label: 'Semua' },
  { value: 'valid', label: 'Valid' },
  { value: 'warning', label: 'Warning' },
  { value: 'error', label: 'Error' },
  { value: 'duplicate', label: 'Duplikat' },
];

/**
 * Tahap 6.8.4: this is NOT master data and must stay a fixed constant — it
 * mirrors the backend's `App\Import\Parsing\CategoryColumnMap::KNOWN_CATEGORIES`
 * exactly, the fixed set of categories `ImportTemplateService` actually knows
 * a physical Excel column layout for (`ImportTemplateRequest` rejects any
 * other value with a 422). Creating a new category in Master Data must NOT
 * imply a new import-template format, so this list intentionally does not
 * grow just because more categories exist — only the category NAMES shown
 * next to these codes are master-data-driven (see `Imports.jsx`, which joins
 * this against `useMasterData().categories` for live names and to hide a
 * code if that category is currently inactive).
 */
export const IMPORT_TEMPLATE_CATEGORY_CODES = ['02', '03', '06'];

async function parseErrorPayload(response) {
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }
  const fieldError = payload?.errors ? Object.values(payload.errors)[0]?.[0] : null;

  return { message: fieldError || payload?.message || 'Terjadi kesalahan. Coba lagi.', errors: payload?.errors ?? null };
}

/** Downloads the Excel template for `category` — same blob-download technique as
 *  `lib/labels.js` (Tahap 6.0.2): no blank tab, no navigation, a real file save. */
export async function downloadImportTemplate(category) {
  let response;
  try {
    response = await fetch(`/api/imports/template?category=${encodeURIComponent(category)}`, {
      credentials: 'include',
      headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
    });
  } catch {
    throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.', { status: 0 });
  }

  if (!response.ok) {
    const { message, errors } = await parseErrorPayload(response);
    throw new ApiError(message, { status: response.status, errors });
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `template-impor-${category}.xlsx`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/** Uploads one `.xlsx` file to be staged. Returns the created batch (`{ data, message }`). */
export async function uploadImportFile(file) {
  await ensureCsrfCookie();
  const headers = { Accept: 'application/json' };
  const xsrf = readCookie('XSRF-TOKEN');
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;

  const form = new FormData();
  form.append('file', file);

  let response;
  try {
    response = await fetch('/api/imports', { method: 'POST', credentials: 'include', headers, body: form });
  } catch {
    throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.', { status: 0 });
  }

  if (!response.ok) {
    const { message, errors } = await parseErrorPayload(response);
    throw new ApiError(message, { status: response.status, errors });
  }

  return response.json();
}

function buildQuery(params) {
  const usp = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') usp.set(key, value);
  }
  const qs = usp.toString();

  return qs ? `?${qs}` : '';
}

export function listImportBatches({ page } = {}) {
  return api.get(`/api/imports${buildQuery({ page })}`);
}

export function getImportBatch(batchId) {
  return api.get(`/api/imports/${batchId}`);
}

export function getImportRows(batchId, { status, page, per_page: perPage } = {}) {
  return api.get(`/api/imports/${batchId}/rows${buildQuery({ status, page, per_page: perPage })}`);
}

export function promoteImportBatch(batchId) {
  return api.post(`/api/imports/${batchId}/promote`);
}

export function getImportReport(batchId) {
  return api.get(`/api/imports/${batchId}/report`);
}

/** Tahap 6.9 R9.2 — distinct (location_code, normalized raw value) unmapped room groups for one batch. */
export function getRoomMappings(batchId) {
  return api.get(`/api/imports/${batchId}/room-mappings`);
}

/**
 * Resolves one unmapped group to `roomId` — for this batch only, or
 * permanently as a new alias when `saveAsAlias` is true.
 */
export function resolveRoomMapping(batchId, { locationCode, rawValue, roomId, saveAsAlias }) {
  return api.post(`/api/imports/${batchId}/room-mappings/resolve`, {
    location_code: locationCode,
    raw_value: rawValue,
    room_id: roomId,
    save_as_alias: saveAsAlias,
  });
}
