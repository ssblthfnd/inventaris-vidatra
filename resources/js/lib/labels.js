import { ApiError, ensureCsrfCookie, readCookie } from './api';

/**
 * Asset-label PDF download helpers (Tahap 6.0; print-size options + direct-download
 * UX Tahap 6.0.2; Individual print mode R8).
 *
 * `GET /api/assets/{asset}/label` and `POST /api/assets/batch/label` always
 * return a raw PDF (never a ZIP — an earlier revision of R8's Individual mode
 * did return one for more than one asset; that was replaced with a single
 * multi-page PDF instead, per explicit review feedback that one file is more
 * practical to print in bulk), not JSON — the shared `api` wrapper in
 * `lib/api.js` always decodes the body as text/JSON, which would corrupt
 * binary content. This is a small, separate fetch path that mirrors the same
 * credentials/CSRF/error conventions instead.
 *
 * Tahap 6.0.2 dropped the earlier "open a blank tab, then navigate it once the PDF
 * is ready" technique entirely: the backend is always fetched as a Blob and saved
 * via a temporary `<a download>` click — no new tab, no navigation, nothing that
 * can show a blank/about:blank window while the request is in flight.
 *
 * R8 — download filenames stay CLIENT-SIDE-constructed strings (matching this
 * file's own pre-existing convention, e.g. `label-asset-{id}-{size}.pdf` already
 * doesn't match the backend's own `Content-Disposition` header) rather than
 * parsed out of the response header: the actual bytes fetched here are a Blob,
 * which carries no Content-Disposition of its own once `.blob()` is called, and
 * every filename needed (an asset's own `asset_code`, or the fixed `labels.pdf`)
 * is already available client-side without needing to parse a header at all.
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

/** Mirrors the backend's own defensive filename sanitizer (AssetLabelPdfService::individualFilename()) — asset_code is always digits/dots in practice, this only guards the rare unsafe character. */
function safeFilenamePart(value) {
  return String(value).replace(/[^A-Za-z0-9._-]/g, '_');
}

/**
 * `mode`: `'a4'` (default, unchanged pre-R8 behaviour — one A4 sheet) or
 * `'individual'` (R8 — always exactly ONE PDF: the raw single-label PDF for
 * exactly one asset, or one multi-page PDF — one page per asset, every page
 * still exactly the chosen label size, never an A4 sheet — for more than
 * one). `assets` takes `{ id, asset_code }` objects rather than bare ids
 * (R8) so the Individual-mode single-asset filename can be constructed here
 * without an extra round trip — every existing caller already has full
 * asset objects at hand.
 *
 * @param {Array<{id: number, asset_code?: string}>} assets
 */
export async function printBatchLabels(assets, size = 'small', mode = 'a4') {
  const assetIds = assets.map((a) => a.id);
  const blob = await fetchPdf('POST', '/api/assets/batch/label', { asset_ids: assetIds, size, mode });

  if (mode === 'individual' && assets.length === 1) {
    downloadBlob(blob, `label-${safeFilenamePart(assets[0].asset_code ?? assets[0].id)}.pdf`);
    return;
  }
  if (mode === 'individual') {
    downloadBlob(blob, 'labels.pdf');
    return;
  }
  downloadBlob(blob, `asset-labels-${size}.pdf`);
}
