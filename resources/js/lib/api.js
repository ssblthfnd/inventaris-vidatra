/**
 * Tiny fetch wrapper for the Inventaris Vidatra API (Tahap 5.7).
 *
 * Auth is Laravel Sanctum SPA cookie/session — NO tokens are stored anywhere.
 * Every request is same-origin and sends the session cookie (`credentials:
 * 'include'`). Mutating requests first prime the `XSRF-TOKEN` cookie via
 * `GET /sanctum/csrf-cookie` and echo it back as the `X-XSRF-TOKEN` header, exactly
 * like axios' built-in behaviour.
 */

/** Thrown for any non-2xx response (or a network failure). */
export class ApiError extends Error {
  constructor(message, { status = 0, errors = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    /** Laravel validation bag: `{ field: [messages] }` or null. */
    this.errors = errors;
  }
}

/** Called whenever a request comes back 401 — AuthProvider uses it to drop state. */
let onUnauthorized = null;
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn;
}

export function readCookie(name) {
  const match = document.cookie.match(
    new RegExp('(?:^|;\\s*)' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'),
  );
  return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Ensure a usable `XSRF-TOKEN` cookie exists. The Blade SPA shell is served by a
 * Laravel `web` route, so the cookie is normally already set on page load — we only
 * hit `/sanctum/csrf-cookie` when it is missing, or when forced after a 419.
 */
export async function ensureCsrfCookie(force = false) {
  if (!force && readCookie('XSRF-TOKEN')) return;
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  });
}

function friendlyMessage(status) {
  switch (status) {
    case 0:
      return 'Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.';
    case 401:
      return 'Sesi Anda telah berakhir. Silakan masuk kembali.';
    case 403:
      return 'Anda tidak memiliki akses ke sumber daya ini.';
    case 404:
      return 'Data yang diminta tidak ditemukan.';
    case 419:
      return 'Sesi keamanan kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    case 422:
      return 'Data yang dikirim tidak valid.';
    case 429:
      return 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.';
    default:
      return status >= 500
        ? 'Terjadi kesalahan pada server. Coba lagi beberapa saat lagi.'
        : 'Terjadi kesalahan. Coba lagi.';
  }
}

/**
 * Tahap 6.9 R9.4-17 — known technical/English server messages that reach the
 * UI, each with its Indonesian presentation. Only the human-readable text is
 * replaced: the HTTP status, the `errors` bag and the response body itself are
 * untouched (API clients and backend tests still see the original). Matching is
 * exact-pattern, never a blanket "translate English"; an Indonesian or unknown
 * message passes through as-is. Authorization texts stay generic, so a denial
 * never echoes back which location/batch/user it concerned.
 */
const SERVER_MESSAGE_PRESENTATION = [
  // Laravel framework defaults
  [/^This action is unauthorized\.?$/i, 'Anda tidak memiliki izin untuk melakukan tindakan ini.'],
  [/^Unauthenticated\.?$/i, friendlyMessage(401)],
  [/^No query results for model /, friendlyMessage(404)], // also hides the model class / id
  [/^(Not Found|The route .* could not be found\.?)$/i, friendlyMessage(404)],
  [/^CSRF token mismatch\.?$/i, friendlyMessage(419)],
  [/^Too Many Attempts\.?$/i, friendlyMessage(429)],
  // App\Support\LocationScope
  [/^Location '.*' is outside this user's scope\.?$/, 'Data ini berada di luar lokasi yang menjadi wewenang Anda.'],
  [/^unit_admin user #\d+/, 'Akun Anda belum terhubung ke unit yang aktif. Hubungi administrator.'],
  // App\Import\Promotion\AssetPromoter
  [/^Batch \d+ contains data outside your assigned location\.?$/, 'Batch ini berisi data di luar lokasi yang menjadi wewenang Anda.'],
  [/^Batch \d+ is \[[^\]]*\] — validate it before promotion\.?$/, 'Batch ini belum dapat dipromosikan pada statusnya saat ini.'],
  // App\Import\RoomMapping\RoomMappingResolver
  [/^Actor is not authorized for location /, 'Data ini berada di luar lokasi yang menjadi wewenang Anda.'],
  [/^Actor may not save a permanent room alias\.?$/, 'Anda tidak memiliki izin untuk menyimpan alias ruangan permanen.'],
];

/**
 * The human-readable message for an error response — shared by every request
 * helper (this file, `imports.js`, `labels.js`, `exports.js`) so there is one
 * presentation rule:
 *   - a validation error shows its first field message (never Laravel's English
 *     "(and N more errors)" summary);
 *   - a 5xx never shows server internals;
 *   - a known technical message is presented in Indonesian (table above);
 *   - otherwise the server's own message, or `fallback`.
 */
export function presentErrorMessage(status, payload, fallback = friendlyMessage(status)) {
  if (status >= 500) return friendlyMessage(status);

  const fieldError = payload?.errors ? Object.values(payload.errors)[0]?.[0] : null;
  const raw = String(fieldError || payload?.message || '').trim();
  if (!raw) return fallback;

  const known = SERVER_MESSAGE_PRESENTATION.find(([pattern]) => pattern.test(raw));

  return known ? known[1] : raw;
}

async function request(method, url, body, { retried = false } = {}) {
  const headers = { Accept: 'application/json' };
  const options = { method, credentials: 'include', headers };
  const mutating = method !== 'GET' && method !== 'HEAD';

  if (mutating) {
    await ensureCsrfCookie(retried);
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
    throw new ApiError(friendlyMessage(0), { status: 0 });
  }

  // stale CSRF cookie — refresh once and retry the (mutating) request
  if (response.status === 419 && mutating && !retried) {
    return request(method, url, body, { retried: true });
  }

  // transient gateway hiccup (dev server / proxy) — retry an idempotent GET once
  if ([502, 503, 504].includes(response.status) && method === 'GET' && !retried) {
    await new Promise((r) => setTimeout(r, 400));
    return request(method, url, body, { retried: true });
  }

  if (response.status === 401 && onUnauthorized) onUnauthorized();

  if (response.status === 204) return null;

  let payload = null;
  const text = await response.text().catch(() => '');
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    throw new ApiError(presentErrorMessage(response.status, payload), {
      status: response.status,
      errors: payload?.errors ?? null,
    });
  }

  return payload;
}

export const api = {
  get: (url) => request('GET', url),
  post: (url, body = {}) => request('POST', url, body),
  put: (url, body = {}) => request('PUT', url, body),
  patch: (url, body = {}) => request('PATCH', url, body),
  delete: (url, body) => request('DELETE', url, body),
};
