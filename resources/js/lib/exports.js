import { ApiError } from './api';

/**
 * Asset Excel export download helper (Tahap 6.2).
 *
 * `GET /api/assets/export` returns a raw `.xlsx` file, not JSON, so — same
 * reasoning as `lib/labels.js` / `lib/imports.js`'s template download — this is a
 * small dedicated fetch path outside the shared JSON `api` wrapper. It is a plain
 * GET (no CSRF priming needed) and uses the exact same blob-download technique:
 * fetch -> Blob -> temporary `<a download>` -> revoke. No blank tab, no navigation.
 */

async function parseErrorPayload(response) {
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }
  const fieldError = payload?.errors ? Object.values(payload.errors)[0]?.[0] : null;

  return { message: fieldError || payload?.message || 'Gagal membuat file export. Coba lagi.', errors: payload?.errors ?? null };
}

/**
 * @param {string} query  a query string (no leading `?`) using the exact same
 *   parameter names as `GET /api/assets` (e.g. from `lib/inventoryQuery.js`'s
 *   `buildQuery()`), or an empty string for "no filter".
 */
export async function downloadAssetExport(query = '') {
  const url = query ? `/api/assets/export?${query}` : '/api/assets/export';

  let response;
  try {
    response = await fetch(url, {
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
  const disposition = response.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename="?([^"]+)"?/);
  const filename = match ? match[1] : 'export-inventaris.xlsx';

  const objectUrl = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = objectUrl;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(objectUrl), 30_000);
}
