import { ApiError, ensureCsrfCookie, readCookie } from './api';

/**
 * Asset-label PDF download helpers (Tahap 6.0; print-size options + direct-download
 * UX Tahap 6.0.2).
 *
 * `GET /api/assets/{asset}/label` and `POST /api/assets/batch/label` return a raw
 * PDF, not JSON — the shared `api` wrapper in `lib/api.js` always decodes the body
 * as text/JSON, which would corrupt a binary PDF. This is a small, separate fetch
 * path that mirrors the same credentials/CSRF/error conventions instead.
 *
 * Tahap 6.0.2 dropped the earlier "open a blank tab, then navigate it once the PDF
 * is ready" technique entirely: the backend is always fetched as a Blob and saved
 * via a temporary `<a download>` click — no new tab, no navigation, nothing that
 * can show a blank/about:blank window while the request is in flight.
 */

/** Predefined print sizes, backend is the source of truth for the physical mm values. */
export const LABEL_SIZE_OPTIONS = [
  { value: 'small', label: 'Kecil', dims: '55 × 15 mm' },
  { value: 'medium', label: 'Sedang', dims: '70 × 20 mm' },
  { value: 'large', label: 'Besar', dims: '90 × 25 mm' },
];

async function fetchPdf(method, url, body) {
  const headers = { Accept: 'application/pdf' };
  const options = { method, credentials: 'include', headers };

  if (method !== 'GET') {
    await ensureCsrfCookie();
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(url, options);
  } catch {
    throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.', {
      status: 0,
    });
  }

  if (!response.ok) {
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
    const fieldError = payload?.errors ? Object.values(payload.errors)[0]?.[0] : null;
    throw new ApiError(fieldError || payload?.message || 'Gagal membuat label. Coba lagi.', {
      status: response.status,
      errors: payload?.errors ?? null,
    });
  }

  return response.blob();
}

/**
 * Saves `blob` as `filename` via a temporary `<a download>` — no navigation, no new
 * tab. The object URL is revoked on a short delay rather than immediately after
 * `click()`: some browsers (Firefox in particular) start the save asynchronously,
 * and revoking too early can cancel it.
 */
function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

export async function printAssetLabel(assetId, size = 'small') {
  const blob = await fetchPdf(
    'GET',
    `/api/assets/${encodeURIComponent(assetId)}/label?size=${encodeURIComponent(size)}`,
  );
  downloadBlob(blob, `label-asset-${assetId}-${size}.pdf`);
}

export async function printBatchLabels(assetIds, size = 'small') {
  const blob = await fetchPdf('POST', '/api/assets/batch/label', { asset_ids: assetIds, size });
  downloadBlob(blob, `asset-labels-${size}.pdf`);
}
