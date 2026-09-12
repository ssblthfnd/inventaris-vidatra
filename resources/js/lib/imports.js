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

export const IMPORT_CATEGORY_OPTIONS = [
  { value: '02', label: '02 — Meubelair' },
  { value: '03', label: '03 — Elektronik' },
  { value: '06', label: '06 — Alat Kebersihan' },
];

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
