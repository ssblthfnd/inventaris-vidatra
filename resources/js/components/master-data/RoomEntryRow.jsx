import { memo } from 'react';

import { controlClass } from '../../lib/assetFields';

/**
 * One room in the multiple-entry form (Tahap 6.9 R10, `pages/RoomEntry.jsx`).
 * Presentational only — values and changes go through the page, keyed by the
 * row's stable `clientId` (also used in element ids). Same fields as room
 * create in `RoomFormModal`: lokasi, nama, PIC, catatan.
 */
function RoomEntryRow({ row, number, errors, locations, lockLocation, canRemove, disabled, onField, onRemove }) {
  const id = (field) => `${field}-${row.clientId}`;
  const set = (field) => (e) => onField(row.clientId, field, e.target.value);
  const hasErrors = Object.keys(errors).length > 0;

  const fieldError = (field) =>
    errors[field] ? (
      <p className="mt-1 text-xs text-red-600" role="alert">
        {errors[field]}
      </p>
    ) : null;

  return (
    <section
      aria-label={`Ruangan ${number}`}
      className={`rounded-xl border bg-white p-4 ${hasErrors ? 'border-red-300' : 'border-gray-200'}`}
    >
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-sm font-semibold text-gray-800">Ruangan {number}</h2>
        {canRemove && (
          <button
            type="button"
            onClick={() => onRemove(row.clientId)}
            disabled={disabled}
            className="rounded-md px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
          >
            Hapus baris
          </button>
        )}
      </div>

      <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div>
          <label htmlFor={id('location_code')} className="mb-1 block text-xs font-medium text-gray-600">
            Lokasi <span className="text-red-600">*</span>
          </label>
          <select
            id={id('location_code')}
            value={row.location_code}
            onChange={set('location_code')}
            disabled={disabled || lockLocation}
            className={controlClass(errors.location_code)}
          >
            {!lockLocation && <option value="">Pilih lokasi…</option>}
            {locations.map((l) => (
              <option key={l.code} value={l.code}>
                {l.name}
              </option>
            ))}
          </select>
          {fieldError('location_code')}
        </div>

        <div>
          <label htmlFor={id('name')} className="mb-1 block text-xs font-medium text-gray-600">
            Nama Ruangan <span className="text-red-600">*</span>
          </label>
          <input
            id={id('name')}
            type="text"
            value={row.name}
            onChange={set('name')}
            disabled={disabled}
            maxLength={100}
            className={controlClass(errors.name)}
          />
          {fieldError('name')}
        </div>

        <div>
          <label htmlFor={id('pic')} className="mb-1 block text-xs font-medium text-gray-600">
            PIC
          </label>
          <input
            id={id('pic')}
            type="text"
            value={row.pic}
            onChange={set('pic')}
            disabled={disabled}
            maxLength={100}
            className={controlClass(errors.pic)}
          />
          {fieldError('pic')}
        </div>

        <div>
          <label htmlFor={id('notes')} className="mb-1 block text-xs font-medium text-gray-600">
            Catatan
          </label>
          <input
            id={id('notes')}
            type="text"
            value={row.notes}
            onChange={set('notes')}
            disabled={disabled}
            maxLength={255}
            className={controlClass(errors.notes)}
          />
          {fieldError('notes')}
        </div>
      </div>
    </section>
  );
}

export default memo(RoomEntryRow);
