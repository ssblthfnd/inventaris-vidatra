import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';

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
 * Batch Create Asset (Tahap 5.8.4) — `/inventory/batch`.
 *
 * One template form + a "Jumlah Aset" count. Submits ONE request:
 *   POST /api/assets/batch  { ...template, count }
 * The backend creates `count` SEPARATE asset rows, each `quantity = 1`, each with
 * its own server-generated `sequence_no` / `asset_code`, in one atomic transaction.
 * The frontend never computes an asset number — final codes come only from the
 * response.
 */

const MAX_COUNT = 1000;

const EMPTY = {
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
  count: '1',
};

export default function BatchAssetForm() {
  const location = useLocation();
  const { isOperator, refreshUser } = useAuth();
  const md = useMasterData();
  const { ensureRooms, ensureSubcategories } = md;

  const fromQuery = typeof location.state?.from === 'string' ? location.state.from : '';
  const listTo = fromQuery ? `/inventory?${fromQuery}` : '/inventory';

  const [form, setForm] = useState(EMPTY);
  const [fieldErrors, setFieldErrors] = useState({});
  const [generalError, setGeneralError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null); // { count, data:[{id,asset_code}], template snapshot }

  const dirtyRef = useRef(false);
  const submittingRef = useRef(false);

  /* ---- guard: browser refresh / close with unsaved changes ---- */
  useEffect(() => {
    const handler = (e) => {
      if (dirtyRef.current && !submittingRef.current && !result) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [result]);

  /* ---- dependent master data ---- */
  useEffect(() => {
    if (form.location_code) ensureRooms([form.location_code]);
  }, [form.location_code, ensureRooms]);
  useEffect(() => {
    if (form.category_code) ensureSubcategories([form.category_code]);
  }, [form.category_code, ensureSubcategories]);

  /* ---- option lists ---- */
  const locationOptions = useMemo(
    () => md.locations.map((l) => ({ value: l.code, label: `${l.code} — ${l.name}` })),
    [md.locations],
  );
  const categoryOptions = useMemo(
    () => md.categories.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` })),
    [md.categories],
  );
  const roomOptions = useMemo(
    () =>
      (md.roomsByLocation[form.location_code] ?? []).map((r) => ({
        value: String(r.id),
        label: r.name,
      })),
    [md.roomsByLocation, form.location_code],
  );
  const subcategoryOptions = useMemo(
    () =>
      (md.subcategoriesByCategory[form.category_code] ?? []).map((s) => ({
        value: s.code,
        label: `${s.code} — ${s.name}`,
      })),
    [md.subcategoriesByCategory, form.category_code],
  );

  const roomsLoading = Boolean(form.location_code) && !(form.location_code in md.roomsByLocation);
  const subsLoading = Boolean(form.category_code) && !(form.category_code in md.subcategoriesByCategory);

  const nameFor = (list, code) => list.find((x) => x.value === code)?.label ?? '';
  const subcategoryName = (
    md.subcategoriesByCategory[form.category_code]?.find((s) => s.code === form.subcategory_code)
      ?.name ?? ''
  );

  /* ---- field setters (dependent resets happen here) ---- */
  const set = (key) => (value) => {
    dirtyRef.current = true;
    setForm((f) => ({ ...f, [key]: value }));
    setFieldErrors((e) => (e[key] ? { ...e, [key]: undefined } : e));
  };
  const setLocation = (value) => {
    dirtyRef.current = true;
    setForm((f) => ({ ...f, location_code: value, room_id: '' }));
    setFieldErrors((e) => ({ ...e, location_code: undefined, room_id: undefined }));
  };
  const setCategory = (value) => {
    dirtyRef.current = true;
    setForm((f) => ({ ...f, category_code: value, subcategory_code: '' }));
    setFieldErrors((e) => ({ ...e, category_code: undefined, subcategory_code: undefined }));
  };

  const countNum = Number(form.count);
  const countValid = Number.isInteger(countNum) && countNum >= 1 && countNum <= MAX_COUNT;

  /* ---- validate + submit ---- */
  const validate = () => {
    const e = {};
    if (!form.location_code) e.location_code = 'Lokasi wajib dipilih.';
    if (!form.category_code) e.category_code = 'Kategori wajib dipilih.';
    if (!form.subcategory_code) e.subcategory_code = 'Subkategori wajib dipilih.';
    if (!form.asset_year) e.asset_year = 'Tahun aset wajib diisi.';
    else {
      const y = Number(form.asset_year);
      if (!Number.isInteger(y) || y < 1980 || y > MAX_YEAR)
        e.asset_year = `Tahun harus antara 1980 dan ${MAX_YEAR}.`;
    }
    if (form.count === '' || form.count == null) e.count = 'Jumlah aset wajib diisi.';
    else if (!Number.isInteger(countNum)) e.count = 'Jumlah aset harus bilangan bulat.';
    else if (countNum < 1) e.count = 'Jumlah aset minimal 1.';
    else if (countNum > MAX_COUNT) e.count = `Jumlah aset maksimal ${MAX_COUNT}.`;
    for (const [k, max] of Object.entries(MAX_LEN)) {
      if (form[k] && form[k].trim().length > max) e[k] = `Maksimal ${max} karakter.`;
    }
    if (form.purchase_date && Number.isNaN(new Date(form.purchase_date).getTime()))
      e.purchase_date = 'Tanggal tidak valid.';
    return e;
  };

  const buildPayload = () => ({
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
    asset_year: Number(form.asset_year),
    count: countNum,
  });

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
      const res = await api.post('/api/assets/batch', buildPayload());
      dirtyRef.current = false;
      setResult({
        count: res?.count ?? res?.data?.length ?? countNum,
        assets: res?.data ?? [],
        template: {
          location: nameFor(locationOptions, form.location_code),
          category: nameFor(categoryOptions, form.category_code),
          subcategory: subcategoryName || form.subcategory_code,
          room: form.room_id ? nameFor(roomOptions, form.room_id) : 'Belum dipetakan',
          year: form.asset_year,
        },
        // newest-first so the just-created batch shows at the top of the list
        filterLink: `/inventory?${new URLSearchParams([
          ['category_code[]', form.category_code],
          ['subcategory_code[]', `${form.category_code}.${form.subcategory_code}`],
          ['sort', 'created_at'],
          ['direction', 'desc'],
        ]).toString()}`,
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
          setGeneralError('Anda tidak memiliki izin untuk membuat aset.');
          return;
        }
        if (e.status === 422 && e.errors) {
          const mapped = {};
          for (const [key, msgs] of Object.entries(e.errors)) {
            mapped[key.split('.')[0]] = Array.isArray(msgs) ? msgs[0] : String(msgs);
          }
          setFieldErrors(mapped);
          setGeneralError('Periksa kembali isian yang ditandai. Tidak ada aset yang dibuat.');
          return;
        }
        setGeneralError(
          `${e.message || 'Gagal membuat batch aset.'} Tidak ada aset yang dibuat.`,
        );
        return;
      }
      setGeneralError('Terjadi kesalahan tak terduga. Tidak ada aset yang dibuat.');
    }
  };

  /* ---- gates ---- */
  if (!isOperator) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Hanya operator atau admin yang dapat menambah aset."
        backTo="/inventory"
        backLabel="Kembali ke Inventaris"
      />
    );
  }
  if (!md.ready) return <FormSkeleton />;

  /* ---- success result view ---- */
  if (result) {
    const shown = result.assets.slice(0, 12);
    return (
      <div className="mx-auto max-w-2xl space-y-5">
        <Link
          to={listTo}
          className="inline-flex items-center gap-1.5 rounded text-sm font-medium text-gray-600 hover:text-gray-900"
        >
          <span aria-hidden="true">←</span> Kembali ke Inventaris
        </Link>

        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
          <h1 className="text-base font-semibold text-emerald-900">
            Berhasil membuat {result.count} aset
          </h1>
          <p className="mt-1 text-sm text-emerald-800">
            {result.count} aset terpisah dibuat, masing-masing quantity 1. Nomor aset dibuat
            otomatis oleh sistem.
          </p>
        </div>

        <dl className="overflow-hidden rounded-xl border border-gray-200 bg-white divide-y divide-gray-100">
          {[
            ['Lokasi', result.template.location],
            ['Kategori', result.template.category],
            ['Subkategori', result.template.subcategory],
            ['Ruangan', result.template.room],
            ['Tahun', result.template.year],
          ].map(([k, v]) => (
            <div key={k} className="grid grid-cols-1 gap-0.5 px-4 py-3 sm:grid-cols-3 sm:gap-4">
              <dt className="text-sm text-gray-500">{k}</dt>
              <dd className="text-sm text-gray-900 sm:col-span-2">{v || '—'}</dd>
            </div>
          ))}
        </dl>

        {shown.length > 0 && (
          <div className="rounded-xl border border-gray-200 bg-white p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-400">
              Kode aset yang dibuat
            </p>
            <ul className="mt-2 flex flex-wrap gap-1.5">
              {shown.map((a) => (
                <li
                  key={a.id}
                  className="rounded border border-gray-200 bg-gray-50 px-2 py-1 font-mono text-xs text-gray-700"
                >
                  {a.asset_code}
                </li>
              ))}
              {result.assets.length > shown.length && (
                <li className="px-2 py-1 text-xs text-gray-400">
                  +{result.assets.length - shown.length} lainnya
                </li>
              )}
            </ul>
          </div>
        )}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={() => {
              setForm(EMPTY);
              setResult(null);
              setFieldErrors({});
              setGeneralError('');
            }}
            className="rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:border-gray-400"
          >
            Buat batch lain
          </button>
          <Link
            to={result.filterLink}
            className="rounded-lg bg-gray-900 px-4 py-2 text-center text-sm font-medium text-white hover:bg-gray-800"
          >
            Lihat di Inventaris
          </Link>
        </div>
      </div>
    );
  }

  /* ---- form ---- */
  return (
    <div className="mx-auto max-w-3xl space-y-5 pb-4">
      <Link
        to={listTo}
        className="inline-flex items-center gap-1.5 rounded text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
      >
        <span aria-hidden="true">←</span> Kembali ke Inventaris
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight">Tambah Banyak Aset</h1>
        <p className="mt-1 text-sm text-gray-500">
          Buat beberapa aset identik sekaligus. Setiap aset menjadi satu record terpisah
          dengan nomor aset sendiri. Kode aset dibuat otomatis oleh sistem setelah disimpan.
        </p>
      </header>

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
          description="Klasifikasi dan penempatan — sama untuk semua aset dalam batch ini."
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
                  : 'Opsional.'
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
              <span className="font-medium text-gray-900">{subcategoryName || '—'}</span>
              <span className="ml-1 text-xs text-gray-400">(mengikuti subkategori)</span>
            </div>
          </FullWidth>
        </Section>

        <Section title="Detail Aset" description="Atribut fisik — sama untuk semua aset. Opsional.">
          <Field label="Merek / Model" htmlFor="brand_model" error={fieldErrors.brand_model}>
            <TextInput
              id="brand_model"
              value={form.brand_model}
              onChange={set('brand_model')}
              error={fieldErrors.brand_model}
              maxLength={MAX_LEN.brand_model}
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
          <Field
            label="Nomor Seri"
            htmlFor="serial_no"
            error={fieldErrors.serial_no}
            hint="Akan sama untuk semua aset — kosongkan jika tiap unit punya nomor seri berbeda."
          >
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
          <Field label="Jumlah per aset" htmlFor="qty" hint="Setiap aset dicatat sebagai 1 unit.">
            <TextInput id="qty" value="1 unit" onChange={() => {}} disabled readOnly />
          </Field>
        </Section>

        <Section title="Informasi Perolehan">
          <Field
            label="Tahun Aset"
            htmlFor="asset_year"
            required
            error={fieldErrors.asset_year}
            hint={`Tahun perolehan aset (1980–${MAX_YEAR}).`}
          >
            <TextInput
              id="asset_year"
              type="number"
              inputMode="numeric"
              min={1980}
              max={MAX_YEAR}
              value={form.asset_year}
              onChange={set('asset_year')}
              error={fieldErrors.asset_year}
              placeholder="mis. 2026"
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
                placeholder="Catatan tambahan (opsional) — berlaku untuk semua aset."
              />
            </Field>
          </FullWidth>
        </Section>

        <Section
          title="Jumlah Aset"
          description="Berapa banyak aset identik yang akan dibuat dari template di atas."
        >
          <Field
            label="Jumlah Aset"
            htmlFor="count"
            required
            error={fieldErrors.count}
            hint={`1 – ${MAX_COUNT} aset.`}
          >
            <TextInput
              id="count"
              type="number"
              inputMode="numeric"
              min={1}
              max={MAX_COUNT}
              step={1}
              value={form.count}
              onChange={set('count')}
              error={fieldErrors.count}
            />
          </Field>
          <FullWidth>
            <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-600">
              {countValid ? (
                <>
                  Akan dibuat <span className="font-semibold text-gray-900">{countNum} aset</span>{' '}
                  terpisah{form.subcategory_code && subcategoryName ? ` (${subcategoryName})` : ''},
                  masing-masing quantity 1. Nomor aset dibuat otomatis oleh sistem setelah
                  disimpan.
                </>
              ) : (
                'Masukkan jumlah aset yang valid (1–1000) untuk melihat pratinjau.'
              )}
            </div>
          </FullWidth>
        </Section>

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Link
            to={listTo}
            className="rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:border-gray-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            Batal
          </Link>
          <button
            type="submit"
            disabled={submitting || !countValid}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting
              ? `Membuat ${countValid ? countNum : ''} aset…`
              : `Buat ${countValid ? countNum : ''} Aset`}
          </button>
        </div>
      </form>
    </div>
  );
}
