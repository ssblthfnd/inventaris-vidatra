import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import { api, ApiError } from '../lib/api';
import {
  CONDITION_CHOICES,
  CenteredState,
  Field,
  FormSkeleton,
  FullWidth,
  MAX_LEN,
  MAX_YEAR,
  Section,
  SelectInput,
  TextInput,
  controlClass,
  trimOrNull,
} from '../lib/assetFields';
import { useMasterData } from '../lib/useMasterData';

/**
 * Create / Edit Asset form (Tahap 5.8.3) — one component, two modes.
 *
 *   create  →  POST /api/assets            →  /inventory/:newId
 *   edit    →  PUT  /api/assets/:assetId   →  /inventory/:assetId
 *
 * The frontend NEVER computes `sequence_no` / `asset_code` — the backend
 * (`AssetNumberGenerator`) is authoritative. On edit, `asset_code`, `sequence_no`
 * and `asset_year` are read-only. Master data comes only from the Tahap 5.3
 * endpoints; category→subcategory and location→room dependencies are enforced in the
 * UI *and* re-validated by the backend. Room/location mutation logging is entirely a
 * backend concern — this form just submits.
 */

const EMPTY_FORM = {
  location_code: '',
  room_id: '',
  category_code: '',
  subcategory_code: '',
  asset_year: '',
  condition: '',
  brand_model: '',
  detail_type: '',
  serial_no: '',
  material: '',
  capacity_note: '',
  purchase_date: '',
  funding_source: '',
  notes: '',
  mutation_note: '',
};

/* ------------------------------------------------------------------ page */

export default function AssetForm({ mode }) {
  const isEdit = mode === 'edit';
  const { assetId } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { isOperator, refreshUser } = useAuth();
  const md = useMasterData();
  const { ensureRooms, ensureSubcategories } = md;

  const fromQuery = typeof location.state?.from === 'string' ? location.state.from : '';
  const listTo = fromQuery ? `/inventory?${fromQuery}` : '/inventory';
  const detailTo = isEdit ? `/inventory/${assetId}` : '/inventory';
  const cancelTo = isEdit ? detailTo : listTo;

  const [form, setForm] = useState(EMPTY_FORM);
  const [original, setOriginal] = useState(null); // the loaded asset (edit)
  const [phase, setPhase] = useState(isEdit ? 'loading' : 'ready'); // loading|ready|notfound|error
  const [fieldErrors, setFieldErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const dirtyRef = useRef(false);
  const submittingRef = useRef(false);
  const markDirty = () => {
    dirtyRef.current = true;
  };

  /* ---- guard: browser refresh / close with unsaved changes ---- */
  useEffect(() => {
    const handler = (e) => {
      if (dirtyRef.current && !submittingRef.current) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, []);

  /* ---- edit: load the asset ---- */
  useEffect(() => {
    if (!isEdit || !isOperator) return undefined;
    let alive = true;
    setPhase('loading');

    api
      .get(`/api/assets/${encodeURIComponent(assetId)}`)
      .then((res) => {
        if (!alive) return;
        const a = res?.data;
        if (!a) {
          setPhase('error');
          return;
        }
        setOriginal(a);
        setForm({
          ...EMPTY_FORM,
          location_code: a.location?.code ?? '',
          room_id: a.room ? String(a.room.id) : '',
          category_code: a.category?.code ?? '',
          subcategory_code: a.subcategory?.code ?? '',
          asset_year: a.asset_year != null ? String(a.asset_year) : '',
          condition: a.condition ?? '',
          brand_model: a.brand_model ?? '',
          detail_type: a.detail_type ?? '',
          serial_no: a.serial_no ?? '',
          material: a.material ?? '',
          capacity_note: a.capacity_note ?? '',
          purchase_date: a.purchase_date ?? '',
          funding_source: a.funding_source ?? '',
          notes: a.notes ?? '',
        });
        dirtyRef.current = false;
        setPhase('ready');
      })
      .catch((e) => {
        if (!alive) return;
        if (e instanceof ApiError && e.status === 401) {
          refreshUser();
          return;
        }
        if (e instanceof ApiError && e.status === 404) setPhase('notfound');
        else if (e instanceof ApiError && e.status === 403) setPhase('forbidden');
        else setPhase('error');
      });

    return () => {
      alive = false;
    };
  }, [isEdit, isOperator, assetId, refreshUser]);

  /* ---- dependent master data ---- */
  useEffect(() => {
    if (form.location_code) ensureRooms([form.location_code]);
  }, [form.location_code, ensureRooms]);

  useEffect(() => {
    if (form.category_code) ensureSubcategories([form.category_code]);
  }, [form.category_code, ensureSubcategories]);

  /* ---- option lists (dependent, with the current value kept visible on edit) ---- */
  const locationOptions = useMemo(() => {
    const opts = md.locations.map((l) => ({ value: l.code, label: `${l.code} — ${l.name}` }));
    if (original?.location?.code && !opts.some((o) => o.value === original.location.code)) {
      opts.unshift({
        value: original.location.code,
        label: `${original.location.code} — ${original.location.name} (saat ini)`,
      });
    }
    return opts;
  }, [md.locations, original]);

  const roomOptions = useMemo(() => {
    const rooms = md.roomsByLocation[form.location_code] ?? [];
    const opts = rooms.map((r) => ({ value: String(r.id), label: r.name }));
    if (
      original?.room?.id &&
      String(original.room.id) === form.room_id &&
      form.location_code === original.location?.code &&
      !opts.some((o) => o.value === form.room_id)
    ) {
      opts.unshift({ value: String(original.room.id), label: `${original.room.name} (saat ini)` });
    }
    return opts;
  }, [md.roomsByLocation, form.location_code, form.room_id, original]);

  const categoryOptions = useMemo(() => {
    const opts = md.categories.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` }));
    if (original?.category?.code && !opts.some((o) => o.value === original.category.code)) {
      opts.unshift({
        value: original.category.code,
        label: `${original.category.code} — ${original.category.name} (saat ini)`,
      });
    }
    return opts;
  }, [md.categories, original]);

  const subcategoryOptions = useMemo(() => {
    const subs = md.subcategoriesByCategory[form.category_code] ?? [];
    const opts = subs.map((s) => ({ value: s.code, label: `${s.code} — ${s.name}` }));
    if (
      original?.subcategory?.code &&
      original.subcategory.code === form.subcategory_code &&
      form.category_code === original.category?.code &&
      !opts.some((o) => o.value === form.subcategory_code)
    ) {
      opts.unshift({
        value: original.subcategory.code,
        label: `${original.subcategory.code} — ${original.subcategory.name} (saat ini)`,
      });
    }
    return opts;
  }, [md.subcategoriesByCategory, form.category_code, form.subcategory_code, original]);

  const roomsLoading = Boolean(form.location_code) && !(form.location_code in md.roomsByLocation);
  const subsLoading = Boolean(form.category_code) && !(form.category_code in md.subcategoriesByCategory);

  const assetName = useMemo(() => {
    const s = (md.subcategoriesByCategory[form.category_code] ?? []).find(
      (x) => x.code === form.subcategory_code,
    );
    return s?.name ?? original?.subcategory?.name ?? '';
  }, [md.subcategoriesByCategory, form.category_code, form.subcategory_code, original]);

  const placementChanged =
    isEdit &&
    original &&
    (form.location_code !== original.location?.code ||
      String(form.room_id || '') !== String(original.room?.id || ''));

  /* ---- field setters (dependent resets happen here, not in an effect) ---- */
  const set = (key) => (value) => {
    markDirty();
    setForm((f) => ({ ...f, [key]: value }));
    setFieldErrors((e) => (e[key] ? { ...e, [key]: undefined } : e));
  };
  const setLocation = (value) => {
    markDirty();
    setForm((f) => ({ ...f, location_code: value, room_id: '' }));
    setFieldErrors((e) => ({ ...e, location_code: undefined, room_id: undefined }));
  };
  const setCategory = (value) => {
    markDirty();
    setForm((f) => ({ ...f, category_code: value, subcategory_code: '' }));
    setFieldErrors((e) => ({ ...e, category_code: undefined, subcategory_code: undefined }));
  };

  /* ---- validate + submit ---- */
  const validate = () => {
    const e = {};
    if (!form.location_code) e.location_code = 'Lokasi wajib dipilih.';
    if (!form.category_code) e.category_code = 'Kategori wajib dipilih.';
    if (!form.subcategory_code) e.subcategory_code = 'Subkategori wajib dipilih.';
    if (!isEdit) {
      if (!form.asset_year) e.asset_year = 'Tahun aset wajib diisi.';
      else {
        const y = Number(form.asset_year);
        if (!Number.isInteger(y) || y < 1980 || y > MAX_YEAR)
          e.asset_year = `Tahun harus antara 1980 dan ${MAX_YEAR}.`;
      }
    }
    for (const [k, max] of Object.entries(MAX_LEN)) {
      if (form[k] && form[k].trim().length > max) e[k] = `Maksimal ${max} karakter.`;
    }
    if (form.purchase_date && Number.isNaN(new Date(form.purchase_date).getTime()))
      e.purchase_date = 'Tanggal tidak valid.';
    return e;
  };

  const buildPayload = () => {
    const p = {
      location_code: form.location_code,
      category_code: form.category_code,
      subcategory_code: form.subcategory_code,
      room_id: form.room_id === '' ? null : Number(form.room_id),
      condition: form.condition === '' ? null : form.condition,
      brand_model: trimOrNull(form.brand_model),
      detail_type: trimOrNull(form.detail_type),
      serial_no: trimOrNull(form.serial_no),
      material: trimOrNull(form.material),
      capacity_note: trimOrNull(form.capacity_note),
      purchase_date: trimOrNull(form.purchase_date),
      funding_source: trimOrNull(form.funding_source),
      notes: trimOrNull(form.notes),
    };
    if (!isEdit) p.asset_year = Number(form.asset_year);
    if (isEdit && placementChanged && trimOrNull(form.mutation_note))
      p.mutation_note = form.mutation_note.trim();
    return p;
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    setGeneralError('');
    const clientErrors = validate();
    if (Object.keys(clientErrors).length > 0) {
      setFieldErrors(clientErrors);
      return;
    }
    setFieldErrors({});
    setSubmitting(true);
    submittingRef.current = true;

    try {
      const payload = buildPayload();
      const res = isEdit
        ? await api.put(`/api/assets/${encodeURIComponent(assetId)}`, payload)
        : await api.post('/api/assets', payload);

      dirtyRef.current = false;
      const id = isEdit ? assetId : res?.data?.id;
      navigate(`/inventory/${id}`, {
        replace: true,
        state: { from: fromQuery, [isEdit ? 'updated' : 'created']: true },
      });
    } catch (e) {
      submittingRef.current = false;
      setSubmitting(false);
      if (e instanceof ApiError) {
        if (e.status === 401) {
          refreshUser();
          return;
        }
        if (e.status === 403) {
          setGeneralError('Anda tidak memiliki izin untuk menyimpan aset.');
          return;
        }
        if (e.status === 404) {
          setGeneralError('Aset tidak ditemukan. Mungkin sudah dihapus.');
          return;
        }
        if (e.status === 422 && e.errors) {
          const mapped = {};
          for (const [key, msgs] of Object.entries(e.errors)) {
            mapped[key.split('.')[0]] = Array.isArray(msgs) ? msgs[0] : String(msgs);
          }
          setFieldErrors(mapped);
          setGeneralError('Periksa kembali isian yang ditandai.');
          return;
        }
        setGeneralError(e.message || 'Gagal menyimpan aset. Coba lagi.');
        return;
      }
      setGeneralError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  /* ---- render gates ---- */
  if (!isOperator) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Hanya operator atau admin yang dapat menambah atau mengubah aset."
        backTo={isEdit ? detailTo : '/inventory'}
        backLabel="Kembali"
      />
    );
  }
  if (phase === 'loading' || (!isEdit && !md.ready)) return <FormSkeleton />;
  if (phase === 'notfound')
    return (
      <CenteredState
        title="Aset tidak ditemukan"
        message="Aset yang ingin diubah tidak tersedia atau mungkin sudah dihapus."
        backTo="/inventory"
        backLabel="Kembali ke Inventaris"
      />
    );
  if (phase === 'forbidden')
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin atas aset ini."
        backTo="/inventory"
        backLabel="Kembali ke Inventaris"
      />
    );
  if (phase === 'error')
    return (
      <CenteredState
        title="Gagal memuat data"
        message="Terjadi kendala saat memuat data aset. Muat ulang halaman lalu coba lagi."
        backTo={isEdit ? detailTo : '/inventory'}
        backLabel="Kembali"
      />
    );

  const submitLabel = isEdit ? 'Simpan Perubahan' : 'Simpan Aset';

  return (
    <div className="mx-auto max-w-3xl space-y-5 pb-4">
      <Link
        to={cancelTo}
        className="inline-flex items-center gap-1.5 rounded text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
      >
        <span aria-hidden="true">←</span> {isEdit ? 'Kembali ke detail aset' : 'Kembali ke Inventaris'}
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight">
          {isEdit ? 'Edit Aset' : 'Tambah Aset'}
        </h1>
        <p className="mt-1 text-sm text-gray-500">
          {isEdit
            ? 'Perbarui informasi aset. Kode aset dan tahun tidak dapat diubah.'
            : 'Lengkapi informasi aset. Kode aset dibuat otomatis oleh sistem setelah disimpan.'}
        </p>
      </header>

      {isEdit && original && (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-gray-400">Kode Aset</p>
          <p className="mt-0.5 font-mono text-base font-semibold break-all text-gray-900">
            {original.asset_code}
          </p>
          <p className="mt-1 text-xs text-gray-500">
            Nomor urut {original.sequence_no} · Tahun {original.asset_year} · Kode aset mengikuti
            klasifikasi dan tidak diubah manual.
          </p>
        </div>
      )}

      {generalError && (
        <div
          role="alert"
          className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
          {generalError}
        </div>
      )}

      {md.ready && md.locations.length === 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Gagal memuat data master (lokasi/kategori). Muat ulang halaman lalu coba lagi.
        </div>
      )}

      <form onSubmit={handleSubmit} noValidate className="space-y-5">
        <Section
          title="Identitas Aset"
          description="Klasifikasi dan penempatan aset. Menentukan kode aset."
        >
          <Field label="Lokasi" htmlFor="location_code" required error={fieldErrors.location_code}>
            <SelectInput
              id="location_code"
              value={form.location_code}
              onChange={setLocation}
              error={fieldErrors.location_code}
            >
              <option value="">Pilih lokasi…</option>
              {locationOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </SelectInput>
          </Field>

          <Field
            label="Ruangan"
            htmlFor="room_id"
            error={fieldErrors.room_id}
            hint={
              !form.location_code
                ? 'Pilih lokasi terlebih dahulu.'
                : roomsLoading
                  ? 'Memuat ruangan…'
                  : 'Opsional — kosongkan jika belum dipetakan.'
            }
          >
            <SelectInput
              id="room_id"
              value={form.room_id}
              onChange={set('room_id')}
              error={fieldErrors.room_id}
              disabled={!form.location_code || roomsLoading}
            >
              <option value="">Belum dipetakan</option>
              {roomOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </SelectInput>
          </Field>

          <Field label="Kategori" htmlFor="category_code" required error={fieldErrors.category_code}>
            <SelectInput
              id="category_code"
              value={form.category_code}
              onChange={setCategory}
              error={fieldErrors.category_code}
            >
              <option value="">Pilih kategori…</option>
              {categoryOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </SelectInput>
          </Field>

          <Field
            label="Subkategori"
            htmlFor="subcategory_code"
            required
            error={fieldErrors.subcategory_code}
            hint={
              !form.category_code
                ? 'Pilih kategori terlebih dahulu.'
                : subsLoading
                  ? 'Memuat subkategori…'
                  : undefined
            }
          >
            <SelectInput
              id="subcategory_code"
              value={form.subcategory_code}
              onChange={set('subcategory_code')}
              error={fieldErrors.subcategory_code}
              disabled={!form.category_code || subsLoading}
            >
              <option value="">Pilih subkategori…</option>
              {subcategoryOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </SelectInput>
          </Field>

          <FullWidth>
            <div className="rounded-lg bg-gray-50 px-3 py-2 text-sm">
              <span className="text-gray-500">Nama aset: </span>
              <span className="font-medium text-gray-900">{assetName || '—'}</span>
              <span className="ml-1 text-xs text-gray-400">(mengikuti subkategori)</span>
            </div>
          </FullWidth>
        </Section>

        <Section title="Detail Aset" description="Atribut fisik aset. Semua opsional.">
          <Field label="Merek / Model" htmlFor="brand_model" error={fieldErrors.brand_model}>
            <TextInput
              id="brand_model"
              value={form.brand_model}
              onChange={set('brand_model')}
              error={fieldErrors.brand_model}
              maxLength={MAX_LEN.brand_model}
              placeholder="mis. Lenovo ThinkCentre M70q"
            />
          </Field>
          <Field label="Jenis / Tipe" htmlFor="detail_type" error={fieldErrors.detail_type}>
            <TextInput
              id="detail_type"
              value={form.detail_type}
              onChange={set('detail_type')}
              error={fieldErrors.detail_type}
              maxLength={MAX_LEN.detail_type}
            />
          </Field>
          <Field label="Nomor Seri" htmlFor="serial_no" error={fieldErrors.serial_no}>
            <TextInput
              id="serial_no"
              value={form.serial_no}
              onChange={set('serial_no')}
              error={fieldErrors.serial_no}
              maxLength={MAX_LEN.serial_no}
            />
          </Field>
          <Field label="Bahan" htmlFor="material" error={fieldErrors.material}>
            <TextInput
              id="material"
              value={form.material}
              onChange={set('material')}
              error={fieldErrors.material}
              maxLength={MAX_LEN.material}
              placeholder="mis. Besi, Kayu, Plastik"
            />
          </Field>
          <Field label="Kapasitas" htmlFor="capacity_note" error={fieldErrors.capacity_note}>
            <TextInput
              id="capacity_note"
              value={form.capacity_note}
              onChange={set('capacity_note')}
              error={fieldErrors.capacity_note}
              maxLength={MAX_LEN.capacity_note}
            />
          </Field>
          <Field label="Jumlah" htmlFor="quantity" hint="Setiap aset dicatat sebagai 1 unit.">
            <TextInput id="quantity" value="1 unit" onChange={() => {}} disabled readOnly />
          </Field>
        </Section>

        <Section title="Informasi Perolehan">
          <Field
            label="Tahun Aset"
            htmlFor="asset_year"
            required={!isEdit}
            error={fieldErrors.asset_year}
            hint={isEdit ? 'Tidak dapat diubah.' : 'Tahun perolehan aset (1980–' + MAX_YEAR + ').'}
          >
            <TextInput
              id="asset_year"
              type="number"
              inputMode="numeric"
              min={1980}
              max={MAX_YEAR}
              value={isEdit ? String(original?.asset_year ?? '') : form.asset_year}
              onChange={set('asset_year')}
              error={fieldErrors.asset_year}
              disabled={isEdit}
              placeholder="mis. 2025"
            />
          </Field>
          <Field label="Tanggal Pembelian" htmlFor="purchase_date" error={fieldErrors.purchase_date}>
            <TextInput
              id="purchase_date"
              type="date"
              value={form.purchase_date}
              onChange={set('purchase_date')}
              error={fieldErrors.purchase_date}
              max="2999-12-31"
            />
          </Field>
          <Field label="Sumber Dana" htmlFor="funding_source" error={fieldErrors.funding_source}>
            <TextInput
              id="funding_source"
              value={form.funding_source}
              onChange={set('funding_source')}
              error={fieldErrors.funding_source}
              maxLength={MAX_LEN.funding_source}
              placeholder="mis. APBD, Hibah, Yayasan"
            />
          </Field>
        </Section>

        <Section title="Kondisi">
          <Field label="Kondisi" htmlFor="condition" error={fieldErrors.condition}>
            <SelectInput
              id="condition"
              value={form.condition}
              onChange={set('condition')}
              error={fieldErrors.condition}
            >
              {CONDITION_CHOICES.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </SelectInput>
          </Field>
        </Section>

        <Section title="Catatan">
          <FullWidth>
            <Field label="Catatan" htmlFor="notes" error={fieldErrors.notes}>
              <textarea
                id="notes"
                rows={3}
                value={form.notes}
                onChange={(e) => set('notes')(e.target.value)}
                className={controlClass(fieldErrors.notes)}
                placeholder="Catatan tambahan tentang aset ini (opsional)."
              />
            </Field>
          </FullWidth>
        </Section>

        {placementChanged && (
          <Section
            title="Pemindahan Lokasi / Ruangan"
            description="Perubahan lokasi atau ruangan akan dicatat di riwayat mutasi aset."
          >
            <FullWidth>
              <Field
                label="Alasan pemindahan"
                htmlFor="mutation_note"
                error={fieldErrors.mutation_note}
                hint="Opsional — dilampirkan ke catatan mutasi."
              >
                <textarea
                  id="mutation_note"
                  rows={2}
                  value={form.mutation_note}
                  onChange={(e) => set('mutation_note')(e.target.value)}
                  className={controlClass(fieldErrors.mutation_note)}
                  maxLength={1000}
                />
              </Field>
            </FullWidth>
          </Section>
        )}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Link
            to={cancelTo}
            className="rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:border-gray-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            Batal
          </Link>
          <button
            type="submit"
            disabled={submitting}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? 'Menyimpan…' : submitLabel}
          </button>
        </div>
      </form>
    </div>
  );
}
