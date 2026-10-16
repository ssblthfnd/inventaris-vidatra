import { api, ApiError, ensureCsrfCookie, presentErrorMessage, readCookie } from './api';

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

  if (status >= 200 && status < 300) return payload;

  let message = presentErrorMessage(status, payload);
  if (status === 413) {
    message = 'Ukuran foto melebihi batas yang diterima server. Coba foto dengan resolusi lebih kecil.';
  }
  throw new ApiError(message, { status, errors: payload?.errors ?? null });
}
