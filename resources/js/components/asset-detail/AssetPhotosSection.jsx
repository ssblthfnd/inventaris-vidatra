import { useCallback, useEffect, useRef, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { ApiError } from '../../lib/api';
import { deleteAssetPhoto, listAssetPhotos, MAX_PHOTOS, uploadAssetPhoto } from '../../lib/assetPhotos';
import {
  compressImage,
  formatBytes,
  ImageCompressError,
  serverCanTakeOriginal,
} from '../../lib/imageCompress';

/**
 * Foto Aset — the only place photos are added, viewed and deleted (Asset Detail
 * page). Up to three photos per asset; the server enforces the limit, validates
 * and re-encodes every upload, and authorizes every image request.
 *
 *  - "Ambil Foto" uses `<input type="file" accept="image/*" capture="environment">`
 *    (a hint to open the rear camera on mobile — browsers may ignore it);
 *    "Pilih dari Galeri" is a plain file picker. No getUserMedia (the app's
 *    Permissions-Policy disables the camera API on purpose).
 *  - The chosen photo is compressed in the browser (lib/imageCompress.js) and
 *    previewed from a data: URL before anything is sent.
 *  - A failed upload never removes anything: existing photos stay listed exactly
 *    as they were, and the preview stays so the user can retry or cancel.
 *  - Deleting is permanent — the confirmation says so explicitly.
 *
 * `onChanged()` lets the page refresh the history section after an add/delete.
 */

function photoErrorMessage(e, fallback) {
  if (e instanceof ApiError) {
    if (e.status === 403) return 'Anda tidak memiliki izin untuk mengelola foto aset ini.';
    if (e.status === 404) return 'Aset atau foto tidak ditemukan. Muat ulang halaman.';
    if (e.errors?.photo?.[0]) return e.errors.photo[0];
    return e.message || fallback;
  }
  return fallback;
}

function Thumbnail({ photo, onOpen, onDelete, canManage }) {
  const [failed, setFailed] = useState(false);

  return (
    <li className="group relative overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
      <button
        type="button"
        onClick={() => onOpen(photo)}
        className="block aspect-[4/3] w-full focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        aria-label={`Buka foto slot ${photo.slot}`}
      >
        {failed ? (
          <span className="flex h-full w-full items-center justify-center px-2 text-center text-xs text-gray-400">
            Foto tidak dapat dimuat
          </span>
        ) : (
          <img
            src={photo.url}
            alt={`Foto aset slot ${photo.slot}`}
            loading="lazy"
            onError={() => setFailed(true)}
            className="h-full w-full object-cover"
          />
        )}
      </button>
      <div className="flex items-center justify-between gap-2 border-t border-gray-200 bg-surface px-2.5 py-1.5">
        <span className="text-xs text-gray-500">
          Slot {photo.slot} · {formatBytes(photo.size_bytes)}
        </span>
        {canManage && (
          <button
            type="button"
            onClick={() => onDelete(photo)}
            className="rounded px-1.5 py-0.5 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600"
          >
            Hapus
          </button>
        )}
      </div>
    </li>
  );
}

function Lightbox({ photos, index, onClose, onIndex }) {
  const photo = photos[index];
  const closeRef = useRef(null);

  useEffect(() => {
    closeRef.current?.focus();
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'ArrowRight' && photos.length > 1) onIndex((index + 1) % photos.length);
      if (e.key === 'ArrowLeft' && photos.length > 1) onIndex((index - 1 + photos.length) % photos.length);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [index, photos.length, onClose, onIndex]);

  if (!photo) return null;

  const navBtn =
    'rounded-full bg-surface/90 px-3 py-2 text-sm font-medium text-gray-900 shadow hover:bg-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-white';

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-black/85" role="dialog" aria-modal="true" aria-label="Pratinjau foto aset">
      <div className="flex items-center justify-between gap-3 px-4 py-3 text-sm text-white">
        <span>
          Foto slot {photo.slot} · {photo.width}×{photo.height} · {formatBytes(photo.size_bytes)}
          {photos.length > 1 && ` · ${index + 1} dari ${photos.length}`}
        </span>
        <button ref={closeRef} type="button" onClick={onClose} className={navBtn}>
          Tutup
        </button>
      </div>
      <div
        className="flex min-h-0 flex-1 items-center justify-center gap-3 px-3 pb-4"
        onClick={(e) => e.target === e.currentTarget && onClose()}
      >
        {photos.length > 1 && (
          <button type="button" onClick={() => onIndex((index - 1 + photos.length) % photos.length)} className={navBtn} aria-label="Foto sebelumnya">
            ‹
          </button>
        )}
        <img
          src={photo.url}
          alt={`Foto aset slot ${photo.slot}`}
          className="max-h-full min-h-0 max-w-full rounded-md object-contain"
        />
        {photos.length > 1 && (
          <button type="button" onClick={() => onIndex((index + 1) % photos.length)} className={navBtn} aria-label="Foto berikutnya">
            ›
          </button>
        )}
      </div>
    </div>
  );
}

export default function AssetPhotosSection({ assetId, canManage = false, isTrashed = false, onChanged }) {
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [photos, setPhotos] = useState([]);
  const [maxPhotos, setMaxPhotos] = useState(MAX_PHOTOS);
  const [maxUploadBytes, setMaxUploadBytes] = useState(15 * 1024 * 1024);
  const [outputMaxSide, setOutputMaxSide] = useState(1600);
  const [loadError, setLoadError] = useState('');
  const [reloadKey, setReloadKey] = useState(0);

  // selection → preview → upload
  const [preparing, setPreparing] = useState(false);
  const [pending, setPending] = useState(null); // {blob, previewUrl|null, width, height, size, name, original}
  const [uploading, setUploading] = useState(false);
  const [progress, setProgress] = useState(0);
  const [uploadError, setUploadError] = useState('');
  const [flash, setFlash] = useState('');

  // delete
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  const [lightboxIndex, setLightboxIndex] = useState(null);

  const cameraInput = useRef(null);
  const galleryInput = useRef(null);

  useEffect(() => {
    let alive = true;
    setLoadError('');

    listAssetPhotos(assetId)
      .then((res) => {
        if (!alive) return;
        setPhotos(res?.data ?? []);
        if (res?.meta?.max_photos) setMaxPhotos(res.meta.max_photos);
        if (res?.meta?.max_upload_kb) setMaxUploadBytes(res.meta.max_upload_kb * 1024);
        if (res?.meta?.output_max_side) setOutputMaxSide(res.meta.output_max_side);
        setPhase('ready');
      })
      .catch((e) => {
        if (!alive) return;
        setLoadError(photoErrorMessage(e, 'Foto aset tidak dapat dimuat.'));
        setPhase('error');
      });

    return () => {
      alive = false;
    };
  }, [assetId, reloadKey]);

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  const full = photos.length >= maxPhotos;
  const busy = preparing || uploading || deleting;
  const canAdd = canManage && !isTrashed && phase === 'ready' && !full && !busy;

  const onFileChosen = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = ''; // the same file can be chosen again later
    if (!file) return;

    setUploadError('');
    setFlash('');
    setPending(null);
    setPreparing(true);
    try {
      const out = await compressImage(file, { maxSide: outputMaxSide });
      setPending({ ...out, size: out.blob.size, name: file.name, original: false });
    } catch (e) {
      if (e instanceof ImageCompressError && e.code === 'decode' && serverCanTakeOriginal(file, maxUploadBytes)) {
        // The browser could not decode it, the server may still: send the original, no preview.
        setPending({ blob: file, previewUrl: null, width: null, height: null, size: file.size, name: file.name, original: true });
      } else {
        setUploadError(e instanceof ImageCompressError ? e.message : 'Foto tidak dapat diproses. Coba foto lain.');
      }
    } finally {
      setPreparing(false);
    }
  };

  const cancelPending = () => {
    if (uploading) return;
    setPending(null);
    setUploadError('');
  };

  const doUpload = async () => {
    if (!pending) return;
    setUploading(true);
    setProgress(0);
    setUploadError('');
    try {
      const res = await uploadAssetPhoto(assetId, pending.blob, {
        filename: pending.original ? pending.name || 'foto' : 'foto.jpg',
        onProgress: setProgress,
      });
      setPending(null);
      setFlash(res?.message || 'Foto berhasil ditambahkan.');
      setReloadKey((k) => k + 1);
      onChanged?.();
    } catch (e) {
      // Nothing was removed: the list is untouched and the preview stays for a retry.
      setUploadError(photoErrorMessage(e, 'Foto gagal diunggah. Coba lagi.'));
      if (e instanceof ApiError && e.status === 422 && /sudah memiliki/.test(e.errors?.photo?.[0] ?? '')) {
        setReloadKey((k) => k + 1); // someone else filled the last slot — show the real list
      }
      if (e instanceof ApiError && e.status >= 200 && e.status < 300) {
        setReloadKey((k) => k + 1); // unconfirmed 2xx — show what the server really holds
      }
    } finally {
      setUploading(false);
    }
  };

  const closeDelete = useCallback(() => {
    if (deleting) return;
    setDeleteTarget(null);
    setDeleteError('');
  }, [deleting]);

  const doDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    setDeleteError('');
    try {
      await deleteAssetPhoto(assetId, deleteTarget.id);
      setFlash(`Foto slot ${deleteTarget.slot} telah dihapus permanen.`);
      setDeleteTarget(null);
      setLightboxIndex(null);
      setReloadKey((k) => k + 1);
      onChanged?.();
    } catch (e) {
      setDeleteError(photoErrorMessage(e, 'Foto gagal dihapus. Coba lagi.'));
      if (e instanceof ApiError && e.status === 404) setReloadKey((k) => k + 1);
    } finally {
      setDeleting(false);
    }
  };

  const inputBtn =
    'inline-flex items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

  return (
    <section className="overflow-hidden rounded-xl border border-gray-200 bg-surface">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
        <div>
          <h2 className="text-sm font-semibold text-gray-700">Foto Aset</h2>
          <p className="mt-0.5 text-xs text-gray-500">
            {phase === 'ready' ? `${photos.length} dari ${maxPhotos} foto` : `Maksimal ${maxPhotos} foto`}
          </p>
        </div>
        {canManage && !isTrashed && (
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              disabled={!canAdd}
              onClick={() => cameraInput.current?.click()}
              className={`${inputBtn} border-accent bg-accent text-accent-fg hover:bg-accent-hover`}
            >
              Ambil Foto
            </button>
            <button
              type="button"
              disabled={!canAdd}
              onClick={() => galleryInput.current?.click()}
              className={`${inputBtn} border-gray-300 text-gray-700 hover:border-gray-400`}
            >
              Pilih dari Galeri
            </button>
            <input
              ref={cameraInput}
              type="file"
              accept="image/*"
              capture="environment"
              className="hidden"
              onChange={onFileChosen}
              tabIndex={-1}
              aria-hidden="true"
            />
            <input
              ref={galleryInput}
              type="file"
              accept="image/jpeg,image/png,image/webp,image/*"
              className="hidden"
              onChange={onFileChosen}
              tabIndex={-1}
              aria-hidden="true"
            />
          </div>
        )}
      </div>

      <div className="space-y-3 p-4">
        {flash && (
          <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800">
            {flash}
          </div>
        )}

        {canManage && !isTrashed && phase === 'ready' && full && !pending && (
          <p className="rounded-lg border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600">
            Batas {maxPhotos} foto sudah tercapai. Untuk mengganti foto, hapus salah satu foto terlebih
            dahulu, lalu tambahkan foto baru.
          </p>
        )}

        {isTrashed && (
          <p className="text-xs text-gray-500">Aset berada di Trash — foto hanya dapat dilihat, tidak dapat ditambah atau dihapus.</p>
        )}

        {preparing && (
          <p role="status" className="text-sm text-gray-600">
            Memproses foto…
          </p>
        )}

        {pending && (
          <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div className="flex flex-col gap-3 sm:flex-row">
              <div className="flex aspect-[4/3] w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-surface ring-1 ring-gray-200 sm:w-48">
                {pending.previewUrl ? (
                  <img src={pending.previewUrl} alt="Pratinjau foto yang akan diunggah" className="h-full w-full object-contain" />
                ) : (
                  <span className="px-2 text-center text-xs text-gray-500">Pratinjau tidak tersedia di browser ini</span>
                )}
              </div>
              <div className="min-w-0 flex-1 space-y-2 text-sm">
                <p className="font-medium text-gray-900">Pratinjau sebelum diunggah</p>
                <p className="text-gray-600">
                  {pending.width && pending.height ? `${pending.width}×${pending.height} piksel · ` : ''}
                  {formatBytes(pending.size)}
                  {pending.original && ' · dikirim tanpa kompresi, diproses di server'}
                </p>
                {uploading && (
                  <div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress}>
                      <div className="h-full bg-gray-900 transition-[width]" style={{ width: `${progress}%` }} />
                    </div>
                    <p className="mt-1 text-xs text-gray-500">
                      {progress < 100 ? `Mengunggah… ${progress}%` : 'Memproses di server…'}
                    </p>
                  </div>
                )}
                <div className="flex flex-wrap gap-2 pt-1">
                  <button
                    type="button"
                    onClick={doUpload}
                    disabled={uploading || full}
                    className={`${inputBtn} border-accent bg-accent text-accent-fg hover:bg-accent-hover`}
                  >
                    {uploading ? 'Mengunggah…' : uploadError ? 'Coba Unggah Lagi' : 'Unggah Foto'}
                  </button>
                  <button
                    type="button"
                    onClick={cancelPending}
                    disabled={uploading}
                    className={`${inputBtn} border-gray-300 text-gray-700 hover:border-gray-400`}
                  >
                    Batal
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {uploadError && (
          <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-700">
            {uploadError}
            {pending && ' Foto yang sudah ada tidak berubah.'}
          </p>
        )}

        {phase === 'loading' && (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-hidden="true">
            {[0, 1, 2].map((i) => (
              <div key={i} className="aspect-[4/3] animate-pulse rounded-lg bg-gray-100" />
            ))}
          </div>
        )}

        {phase === 'error' && (
          <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-4 text-center text-sm">
            <p className="text-red-700">{loadError}</p>
            <button
              type="button"
              onClick={() => {
                setPhase('loading');
                setReloadKey((k) => k + 1);
              }}
              className="mt-2 rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100"
            >
              Coba lagi
            </button>
          </div>
        )}

        {phase === 'ready' && photos.length === 0 && (
          <p className="text-sm text-gray-500">Belum ada foto untuk aset ini.</p>
        )}

        {phase === 'ready' && photos.length > 0 && (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {photos.map((photo, i) => (
              <Thumbnail
                key={photo.id}
                photo={photo}
                canManage={canManage && !isTrashed}
                onOpen={() => setLightboxIndex(i)}
                onDelete={(p) => {
                  setDeleteError('');
                  setDeleteTarget(p);
                }}
              />
            ))}
          </ul>
        )}

        {canManage && !isTrashed && (
          <p className="text-xs text-gray-400">
            Foto diperkecil otomatis (sisi terpanjang {outputMaxSide} px) dan disimpan sebagai JPEG tanpa
            data lokasi/EXIF. Foto HEIC dari iPhone yang tidak dapat dibaca: atur kamera ke “Most
            Compatible” atau pilih foto JPEG.
          </p>
        )}
      </div>

      {lightboxIndex !== null && photos[lightboxIndex] && (
        <Lightbox photos={photos} index={lightboxIndex} onIndex={setLightboxIndex} onClose={() => setLightboxIndex(null)} />
      )}

      <LifecycleConfirmDialog
        open={deleteTarget !== null}
        title="Hapus foto ini secara permanen?"
        tone="danger"
        confirmLabel="Hapus Permanen"
        busy={deleting}
        error={deleteError}
        onConfirm={doDelete}
        onClose={closeDelete}
      >
        <p>
          Foto slot <span className="font-medium text-gray-900">{deleteTarget?.slot}</span> akan dihapus
          dari server.{' '}
          <span className="font-medium text-gray-900">Foto yang dihapus tidak bisa dikembalikan</span> —
          tidak ada Trash untuk foto, dan Revert di Riwayat Perubahan tidak memulihkannya.
        </p>
        <p>
          Jika Anda ingin mengganti foto, pastikan foto pengganti sudah tersedia. Bila unggahan foto baru
          nanti gagal, foto yang sudah dihapus ini tetap tidak kembali.
        </p>
      </LifecycleConfirmDialog>
    </section>
  );
}
