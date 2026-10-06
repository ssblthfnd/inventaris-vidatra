import { memo, useMemo } from 'react';

import {
  CONDITION_CHOICES,
  Field,
  FullWidth,
  MAX_LEN,
  MAX_YEAR,
  SelectInput,
  TextInput,
  controlClass,
} from '../../lib/assetFields';
import { INVENTORY_CODE_EXAMPLE } from '../../lib/inventoryCode';

/**
 * One asset in the multiple-entry form (Tahap 6.9 R10, `pages/AssetEntry.jsx`).
 *
 * Purely presentational: every value lives in the page's row state and every
 * change goes back through the page's callbacks (keyed by the row's stable
 * `clientId`), which is where the dependent-field rules live. Element ids carry
 * the `clientId` so several rows never share an id.
 *
 * A value that is not among the loaded options (e.g. a location taken from a
 * typed inventory number that does not exist) is still shown, marked
 * "tidak ditemukan", instead of the select silently displaying another option.
 */

/** Options for a select, keeping an unknown current value visible. */
function withCurrent(options, value) {
  if (!value || options.some((o) => o.value === value)) return options;
  return [{ value, label: `${value} (tidak ditemukan)` }, ...options];
}

function AssetEntryRow({
  row,
  number,
  errors,
  locations,
  categories,
  subcategories,
  rooms,
  roomsLoading,
  subsLoading,
  lockLocation,
  canRemove,
  canCreateRoom,
  disabled,
  onField,
  onCode,
  onCodeBlur,
  onRemove,
  onAddRoom,
}) {
  const id = (field) => `${field}-${row.clientId}`;
  const set = (field) => (value) => onField(row.clientId, field, value);

  const locationOptions = useMemo(
    () => withCurrent(locations.map((l) => ({ value: l.code, label: `${l.code} — ${l.name}` })), row.location_code),
    [locations, row.location_code],
  );
  const categoryOptions = useMemo(
    () => withCurrent(categories.map((c) => ({ value: c.code, label: `${c.code} — ${c.name}` })), row.category_code),
    [categories, row.category_code],
  );
  const subcategoryOptions = useMemo(() => {
    const options = (subcategories ?? []).map((s) => ({ value: s.code, label: `${s.code} — ${s.name}` }));
    return subcategories ? withCurrent(options, row.subcategory_code) : options;
  }, [subcategories, row.subcategory_code]);
  const roomOptions = useMemo(() => (rooms ?? []).map((r) => ({ value: String(r.id), label: r.name })), [rooms]);

  const assetName = (subcategories ?? []).find((s) => s.code === row.subcategory_code)?.name ?? '';
  const codeApplied = row.sequence_no !== null;

  return (
    <section
      aria-labelledby={id('title')}
      className={`rounded-xl border bg-white p-4 sm:p-5 ${
        Object.keys(errors).length > 0 ? 'border-red-300' : 'border-gray-200'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 id={id('title')} className="text-sm font-semibold text-gray-800">
            Aset {number}
          </h2>
          <p className="mt-0.5 text-xs text-gray-500">
            {assetName || 'Pilih kategori dan subkategori'}
            {' · '}
            {codeApplied ? `Nomor manual ${row.sequence_no}` : 'Nomor dibuat otomatis saat disimpan'}
          </p>
        </div>
        {canRemove && (
          <button
            type="button"
            onClick={() => onRemove(row.clientId)}
            disabled={disabled}
            className="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
          >
            Hapus baris
          </button>
        )}
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FullWidth>
          <Field
            label="Nomor Inventaris (opsional)"
            htmlFor={id('inventory_code')}
            error={errors.inventory_code}
            hint={`Isi kode lengkap untuk memakai nomor yang sudah ada, mis. ${INVENTORY_CODE_EXAMPLE} — lokasi, kategori, subkategori, nomor urut, dan tahun terisi otomatis. Kosongkan agar nomor dibuat otomatis.`}
          >
            <TextInput
              id={id('inventory_code')}
              value={row.inventory_code}
              onChange={(value) => onCode(row.clientId, value)}
              onBlur={() => onCodeBlur(row.clientId)}
              error={errors.inventory_code}
              disabled={disabled}
              placeholder={INVENTORY_CODE_EXAMPLE}
              autoComplete="off"
              spellCheck={false}
              className={`${controlClass(errors.inventory_code)} font-mono`}
            />
          </Field>
          {row.codeNotice && !errors.inventory_code && (
            <p className="mt-1 text-xs text-amber-700" role="status">
              {row.codeNotice}
            </p>
          )}
        </FullWidth>

        <Field label="Lokasi" htmlFor={id('location_code')} required error={errors.location_code}>
          <SelectInput
            id={id('location_code')}
            value={row.location_code}
            onChange={set('location_code')}
            error={errors.location_code}
            disabled={disabled || lockLocation}
          >
            {!lockLocation && <option value="">Pilih lokasi…</option>}
            {locationOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </SelectInput>
        </Field>

        <Field
          label="Ruangan"
          htmlFor={id('room_id')}
          error={errors.room_id}
          hint={!row.location_code ? 'Pilih lokasi terlebih dahulu.' : roomsLoading ? 'Memuat ruangan…' : 'Opsional.'}
        >
          <SelectInput
            id={id('room_id')}
            value={row.room_id}
            onChange={set('room_id')}
            error={errors.room_id}
            disabled={disabled || !row.location_code || roomsLoading}
          >
            <option value="">Tanpa ruangan</option>
            {roomOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </SelectInput>
          {canCreateRoom && row.location_code && !roomsLoading && (
            <button
              type="button"
              onClick={() => onAddRoom(row.clientId)}
              disabled={disabled}
              className="mt-1.5 text-xs font-medium text-gray-700 underline-offset-2 hover:text-gray-900 hover:underline disabled:opacity-50"
            >
              + Tambah Ruangan
            </button>
          )}
        </Field>

        <Field label="Kategori" htmlFor={id('category_code')} required error={errors.category_code}>
          <SelectInput
            id={id('category_code')}
            value={row.category_code}
            onChange={set('category_code')}
            error={errors.category_code}
            disabled={disabled}
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
          htmlFor={id('subcategory_code')}
          required
          error={errors.subcategory_code}
          hint={!row.category_code ? 'Pilih kategori terlebih dahulu.' : subsLoading ? 'Memuat subkategori…' : undefined}
        >
          <SelectInput
            id={id('subcategory_code')}
            value={row.subcategory_code}
            onChange={set('subcategory_code')}
            error={errors.subcategory_code}
            disabled={disabled || !row.category_code || subsLoading}
          >
            <option value="">Pilih subkategori…</option>
            {subcategoryOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </SelectInput>
        </Field>

        <Field
          label="Tahun Aset"
          htmlFor={id('asset_year')}
          required
          error={errors.asset_year}
          hint={`Tahun perolehan aset (1980–${MAX_YEAR}).`}
        >
          <TextInput
            id={id('asset_year')}
            type="number"
            inputMode="numeric"
            min={1980}
            max={MAX_YEAR}
            value={row.asset_year}
            onChange={set('asset_year')}
            error={errors.asset_year}
            disabled={disabled}
            placeholder="mis. 2025"
          />
        </Field>

        <Field label="Kondisi" htmlFor={id('condition')} error={errors.condition}>
          <SelectInput
            id={id('condition')}
            value={row.condition}
            onChange={set('condition')}
            error={errors.condition}
            disabled={disabled}
          >
            {CONDITION_CHOICES.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </SelectInput>
        </Field>

        <Field label="Merek / Model" htmlFor={id('brand_model')} error={errors.brand_model}>
          <TextInput
            id={id('brand_model')}
            value={row.brand_model}
            onChange={set('brand_model')}
            error={errors.brand_model}
            disabled={disabled}
            maxLength={MAX_LEN.brand_model}
          />
        </Field>
        <Field label="Jenis / Tipe" htmlFor={id('detail_type')} error={errors.detail_type}>
          <TextInput
            id={id('detail_type')}
            value={row.detail_type}
            onChange={set('detail_type')}
            error={errors.detail_type}
            disabled={disabled}
            maxLength={MAX_LEN.detail_type}
          />
        </Field>
        <Field label="Nomor Seri" htmlFor={id('serial_no')} error={errors.serial_no}>
          <TextInput
            id={id('serial_no')}
            value={row.serial_no}
            onChange={set('serial_no')}
            error={errors.serial_no}
            disabled={disabled}
            maxLength={MAX_LEN.serial_no}
          />
        </Field>
        <Field label="Bahan" htmlFor={id('material')} error={errors.material}>
          <TextInput
            id={id('material')}
            value={row.material}
            onChange={set('material')}
            error={errors.material}
            disabled={disabled}
            maxLength={MAX_LEN.material}
          />
        </Field>
        <Field label="Kapasitas" htmlFor={id('capacity_note')} error={errors.capacity_note}>
          <TextInput
            id={id('capacity_note')}
            value={row.capacity_note}
            onChange={set('capacity_note')}
            error={errors.capacity_note}
            disabled={disabled}
            maxLength={MAX_LEN.capacity_note}
          />
        </Field>
        <Field label="Tanggal Pembelian" htmlFor={id('purchase_date')} error={errors.purchase_date}>
          <TextInput
            id={id('purchase_date')}
            type="date"
            value={row.purchase_date}
            onChange={set('purchase_date')}
            error={errors.purchase_date}
            disabled={disabled}
            max="2999-12-31"
          />
        </Field>
        <Field label="Sumber Dana" htmlFor={id('funding_source')} error={errors.funding_source}>
          <TextInput
            id={id('funding_source')}
            value={row.funding_source}
            onChange={set('funding_source')}
            error={errors.funding_source}
            disabled={disabled}
            maxLength={MAX_LEN.funding_source}
          />
        </Field>

        <FullWidth>
          <Field label="Catatan" htmlFor={id('notes')} error={errors.notes}>
            <textarea
              id={id('notes')}
              rows={2}
              value={row.notes}
              onChange={(e) => set('notes')(e.target.value)}
              disabled={disabled}
              className={controlClass(errors.notes)}
            />
          </Field>
        </FullWidth>
      </div>
    </section>
  );
}

export default memo(AssetEntryRow);
