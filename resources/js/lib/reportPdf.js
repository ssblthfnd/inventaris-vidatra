import { ApiError, notifyUnauthorized, presentErrorMessage } from './api';
import { downloadBlob } from './labels';

/**
 * Laporan "Unduh PDF": `GET /api/reports/inventory/pdf` with the page's own filter
 * query string (the server validates it again and prints it as a caption). Binary
 * response, so a raw fetch like lib/labels.js instead of the JSON `api` wrapper.
 * The file name comes from the server's Content-Disposition
 * (`laporan-inventaris-YYYYMMDD-HHmm.pdf`, WIB print time).
 */
export async function downloadReportPdf(query) {
  let response;
  try {
    response = await fetch(`/api/reports/inventory/pdf${query ? `?${query}` : ''}`, {
      credentials: 'include',
      headers: { Accept: 'application/pdf' },
    });
  } catch {
    throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.', { status: 0 });
  }

  if (!response.ok) {
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
    if (response.status === 401) notifyUnauthorized();
    throw new ApiError(presentErrorMessage(response.status, payload, 'Gagal membuat PDF laporan. Coba lagi.'), {
      status: response.status,
      errors: payload?.errors ?? null,
    });
  }

  const disposition = response.headers.get('Content-Disposition') ?? '';
  const filename = disposition.match(/filename="?([^";]+)"?/)?.[1] ?? 'laporan-inventaris.pdf';

  downloadBlob(await response.blob(), filename);
}
