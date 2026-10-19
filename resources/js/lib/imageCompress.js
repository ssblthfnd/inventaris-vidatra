/**
 * Browser-side photo compression for Foto Aset — no dependency, browser APIs only.
 *
 * Decodes the chosen file (createImageBitmap with EXIF orientation applied,
 * falling back to an <img> loaded from a data: URL — the CSP allows `data:` but
 * not `blob:` images), draws it onto a canvas no larger than `maxSide` on a white
 * background, and re-encodes it as JPEG. Re-encoding drops every EXIF/GPS tag of
 * the original.
 *
 * This is a convenience (smaller uploads, faster on mobile data) and NEVER a
 * security boundary: the server decodes, checks and re-encodes every upload
 * again (App\Services\Image\ImageSanitizer).
 */

export const DEFAULT_MAX_SIDE = 1600;
export const DEFAULT_QUALITY = 0.8;

/** Formats the server also accepts as-is when the browser cannot decode them itself. */
const SERVER_DECODABLE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);

export class ImageCompressError extends Error {
  /**
   * @param {string} message user-facing (Indonesian)
   * @param {'heic'|'not_image'|'decode'} code
   */
  constructor(message, code) {
    super(message);
    this.name = 'ImageCompressError';
    this.code = code;
  }
}

export const HEIC_MESSAGE =
  'Format HEIC/HEIF tidak dapat diproses di perangkat ini. Pilih foto JPEG, atau atur kamera iPhone ke "Most Compatible" (Pengaturan → Kamera → Format), lalu coba lagi.';

/** By MIME type or file name — browsers often report HEIC as an empty type. */
export function looksLikeHeic(file) {
  const type = (file?.type || '').toLowerCase();
  const name = (file?.name || '').toLowerCase();
  return type === 'image/heic' || type === 'image/heif' || /\.(heic|heif)$/.test(name);
}

/** Whether the server could still process the ORIGINAL file if the browser could not decode it. */
export function serverCanTakeOriginal(file, maxBytes) {
  return Boolean(file) && SERVER_DECODABLE_TYPES.has((file.type || '').toLowerCase()) && file.size <= maxBytes;
}

function readAsDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error || new Error('read failed'));
    reader.readAsDataURL(blob);
  });
}

function loadImageElement(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.decoding = 'async';
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('img decode failed'));
    img.src = src;
  });
}

/** @returns {Promise<{source: CanvasImageSource, width: number, height: number, release: () => void}>} */
async function decode(file) {
  if (typeof createImageBitmap === 'function') {
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
      return { source: bitmap, width: bitmap.width, height: bitmap.height, release: () => bitmap.close?.() };
    } catch {
      // fall through to <img> (older Safari, or options unsupported)
    }
  }

  const dataUrl = await readAsDataUrl(file);
  const img = await loadImageElement(dataUrl); // modern browsers apply EXIF orientation here too
  return { source: img, width: img.naturalWidth, height: img.naturalHeight, release: () => {} };
}

function canvasToBlob(canvas, quality) {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('encode failed'))),
      'image/jpeg',
      quality,
    );
  });
}

export function fitWithin(width, height, maxSide) {
  const scale = Math.min(1, maxSide / Math.max(width, height));
  return {
    width: Math.max(1, Math.round(width * scale)),
    height: Math.max(1, Math.round(height * scale)),
  };
}

/**
 * @param {File} file
 * @param {{maxSide?: number, quality?: number}} [options]
 * @returns {Promise<{blob: Blob, previewUrl: string, width: number, height: number}>}
 *   `previewUrl` is a data: URL of the compressed JPEG (allowed by the CSP).
 * @throws {ImageCompressError}
 */
export async function compressImage(file, { maxSide = DEFAULT_MAX_SIDE, quality = DEFAULT_QUALITY } = {}) {
  const type = (file?.type || '').toLowerCase();
  if (!file || (type && !type.startsWith('image/') && !looksLikeHeic(file))) {
    throw new ImageCompressError('File yang dipilih bukan gambar. Pilih foto berformat JPEG atau PNG.', 'not_image');
  }

  let decoded;
  try {
    decoded = await decode(file);
  } catch {
    if (looksLikeHeic(file)) throw new ImageCompressError(HEIC_MESSAGE, 'heic');
    throw new ImageCompressError(
      'Foto tidak dapat dibaca oleh browser ini. Coba foto lain, atau gunakan format JPEG.',
      'decode',
    );
  }

  try {
    if (!decoded.width || !decoded.height) {
      throw new Error('empty image');
    }
    const target = fitWithin(decoded.width, decoded.height, maxSide);
    const canvas = document.createElement('canvas');
    canvas.width = target.width;
    canvas.height = target.height;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('no 2d context');
    ctx.fillStyle = '#ffffff'; // transparency becomes white, same as the server
    ctx.fillRect(0, 0, target.width, target.height);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(decoded.source, 0, 0, target.width, target.height);

    const blob = await canvasToBlob(canvas, quality);
    canvas.width = 0; // release the bitmap memory early (notably on iOS)
    canvas.height = 0;
    const previewUrl = await readAsDataUrl(blob);

    return { blob, previewUrl, width: target.width, height: target.height };
  } catch {
    throw new ImageCompressError(
      'Foto gagal diproses di perangkat ini. Coba foto lain atau ulangi beberapa saat lagi.',
      'decode',
    );
  } finally {
    decoded.release();
  }
}

export function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 KB';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}
