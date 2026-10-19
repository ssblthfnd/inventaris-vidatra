import { api, ApiError, ensureCsrfCookie, notifyUnauthorized, presentErrorMessage, readCookie } from './api';

/**
 * Foto Aset API helpers.
 *
 *   GET    /api/assets/{asset}/photos           list (metadata only)
 *   GET    /api/assets/{asset}/photos/{photo}   the JPEG — used directly as <img src>; the
 *                                               session cookie authenticates it and the
 *                                               server re-checks access on every request
 *   POST   /api/assets/{asset}/photos           upload one photo (multipart `photo`)
 *   DELETE /api/assets/{asset}/photos/{photo}   permanent delete
 *
 * The upload uses XMLHttpRequest instead of `fetch` only because fetch cannot
 * report upload progress; CSRF/419 handling and error presentation are the same
 * as every other request (lib/api.js).
 */

export const MAX_PHOTOS = 3;

export const UNCONFIRMED_UPLOAD_MESSAGE =
  'Server tidak mengonfirmasi unggahan. Daftar foto dimuat ulang — periksa apakah foto sudah tersimpan sebelum mencoba lagi.';

export function listAssetPhotos(assetId) {
  return api.get(`/api/assets/${encodeURIComponent(assetId)}/photos`);
}

export function deleteAssetPhoto(assetId, photoId) {
  return api.delete(`/api/assets/${encodeURIComponent(assetId)}/photos/${encodeURIComponent(photoId)}`);
}

function sendUpload(url, body, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) xhr.setRequestHeader('X-XSRF-TOKEN', xsrf);

    if (onProgress && xhr.upload) {
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable && e.total > 0) onProgress(Math.min(100, Math.round((e.loaded / e.total) * 100)));
      };
    }

    xhr.onload = () => {
      let payload = null;
      try {
        payload = xhr.responseText ? JSON.parse(xhr.responseText) : null;
      } catch {
        payload = null;
      }
      resolve({ status: xhr.status, payload });
    };
    xhr.onerror = () => resolve({ status: 0, payload: null });
    xhr.onabort = () => resolve({ status: 0, payload: null });
    try {
      xhr.send(body);
    } catch {
      reject(new ApiError(presentErrorMessage(0, null), { status: 0 }));
    }
  });
}

/**
 * @param {number|string} assetId
 * @param {Blob} blob   the (compressed) photo
 * @param {{filename?: string, onProgress?: (percent: number) => void}} [options]
 * @returns {Promise<{data: object, message?: string}>}
 * @throws {ApiError}
 */
export async function uploadAssetPhoto(assetId, blob, { filename = 'foto.jpg', onProgress } = {}) {
  const url = `/api/assets/${encodeURIComponent(assetId)}/photos`;

  const attempt = async (forceCsrf) => {
    await ensureCsrfCookie(forceCsrf);
    const body = new FormData();
    body.append('photo', blob, filename);
    return sendUpload(url, body, onProgress);
  };

  let { status, payload } = await attempt(false);
  if (status === 419) {
    ({ status, payload } = await attempt(true)); // stale CSRF cookie — refresh once
  }

  if (status >= 200 && status < 300) {
    // A real success carries the created photo. A 2xx with an empty or non-JSON body
    // (e.g. a proxy / firewall page) is never shown as "uploaded" — its outcome is unknown.
    if (payload?.data) return payload;
    throw new ApiError(UNCONFIRMED_UPLOAD_MESSAGE, { status });
  }

  // expired session: once per upload, the same global signal every other request sends
  if (status === 401) notifyUnauthorized();

  let message = presentErrorMessage(status, payload);
  if (status === 413) {
    // Laravel (body above PHP's post_max_size) or the web server / proxy itself
    message = 'Ukuran foto melebihi batas yang diterima server. Coba foto dengan resolusi lebih kecil.';
  } else if (status === 0) {
    // no response at all: a dropped connection — or a server that closed it while
    // refusing a body that is too large, which a browser cannot tell apart
    message =
      'Unggahan terputus sebelum server menjawab. Periksa koneksi Anda; bila terus terjadi, ukuran foto mungkin melebihi batas server — coba foto yang lebih kecil.';
  }
  throw new ApiError(message, { status, errors: payload?.errors ?? null });
}
