import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import AssetEntryRow from '../components/asset-entry/AssetEntryRow';
import RoomFormModal from '../components/master-data/RoomFormModal';
import { api, ApiError } from '../lib/api';
import { CenteredState, FormSkeleton, MAX_LEN, MAX_YEAR, trimOrNull } from '../lib/assetFields';
import { MAX_ENTRY_ROWS as MAX_ROWS, makeClientId, mapItemErrors, rowList } from '../lib/entryRows';
import { parseInventoryCode } from '../lib/inventoryCode';
import { useMasterData } from '../lib/useMasterData';

/**
 * Tambah Aset — multiple entry (Tahap 6.9 R10), `/inventory/new`.
 *
 * One form, one or more independent asset rows ("+ Tambah", max 100), saved
 * together by ONE request: `POST /api/assets/entries { items: [...] }` — all
 * rows are created or none is. Editing an asset stays in `AssetForm`.
 *
 * Rows are keyed by a stable `clientId`, never by array index, so removing a row
 * never moves another row's values or errors. Server errors come back as
 * `items.N.field`; N is the row's position in the submitted request, mapped back
 * to that row's `clientId`.
 *
 * Inventory number (optional, per row): the full code printed on labels
 * (`lib/inventoryCode`). A valid code fills lokasi / kategori / subkategori /
 * tahun and supplies the row's `sequence_no`; empty = the server generates the
 * number. While a code is applied it is the source of truth — changing lokasi,
 * kategori, subkategori or tahun by hand clears it (the row then gets an
 * automatic number) rather than leaving a code that no longer matches the
 * fields. The code itself is never sent, only its parts.
 *
 * "+ Tambah Ruangan" (actors holding `rooms.manage`) opens the existing
 * `RoomFormModal` for the row's location. That room is created on its own,
 * immediately (`POST /api/rooms`) — it is master data, not part of this asset
 * save, so it stays even if the asset entry is abandoned. The modal is rendered
 * OUTSIDE the <form>: the dialog is not a portal, so inside the form a press of
 * Enter in it would submit the assets.
 */

const DEPENDENT_FIELDS = ['location_code', 'category_code', 'subcategory_code', 'asset_year'];
const NO_ERRORS = Object.freeze({});

const newRow = (locationCode = '') => ({
  clientId: makeClientId(),
  inventory_code: '',
  sequence_no: null, // set only while a valid inventory code is applied
  codeTouched: false,
  codeNotice: '',
  location_code: locationCode,
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
});

/** Server field -> the field it is shown on (a number error belongs to the number input). */
const displayField = (field) => (field === 'sequence_no' || field === 'asset_code' ? 'inventory_code' : field);

export default function AssetEntry() {
  const routerLocation = useLocation();
  const { can, isUnitAdmin, refreshUser } = useAuth();
  const md = useMasterData();
  const { ensureRooms, ensureSubcategories, addRoom } = md;

  const fromQuery = typeof routerLocation.state?.from === 'string' ? routerLocation.state.from : '';
  const listTo = fromQuery ? `/inventory?${fromQuery}` : '/inventory';

  const canCreate = can('assets.create');
  const canCreateRoom = can('rooms.manage');
  // unit_admin: exactly one location, fixed (useMasterData already narrows the list)
  const fixedLocation = isUnitAdmin && md.locations.length === 1 ? md.locations[0].code : '';
  const lockLocation = isUnitAdmin;

  const [rows, setRows] = useState(() => [newRow()]);
  const [serverErrors, setServerErrors] = useState({}); // clientId -> { field: message }
  const [submitAttempted, setSubmitAttempted] = useState(false);
  // rows that were part of the last submit attempt get the full checks; a row added
  // afterwards stays quiet until the next attempt
  const [checkedIds, setCheckedIds] = useState(() => new Set());
  const [generalError, setGeneralError] = useState(''); // messages not tied to a row
  const [rejected, setRejected] = useState(false); // the last save was refused (nothing stored)
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null); // { assets: [...], manual: [bool] } in row order
  const [roomModalFor, setRoomModalFor] = useState(null); // clientId of the row adding a room
  const [flash, setFlash] = useState('');

  const dirtyRef = useRef(false);
  const submittingRef = useRef(false);

  /* ---- guard: browser refresh / close with unsaved rows ---- */
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

  /* ---- unit_admin: rows start in (and stay in) the own location ---- */
  useEffect(() => {
    if (!fixedLocation) return;
    setRows((rs) =>
      rs.some((r) => r.location_code === '')
        ? rs.map((r) => (r.location_code === '' ? { ...r, location_code: fixedLocation } : r))
        : rs,
    );
  }, [fixedLocation]);

  /* ---- dependent master data for every row ---- */
  const rowLocations = useMemo(() => [...new Set(rows.map((r) => r.location_code).filter(Boolean))], [rows]);
  const rowCategories = useMemo(() => [...new Set(rows.map((r) => r.category_code).filter(Boolean))], [rows]);
  useEffect(() => {
    if (rowLocations.length) ensureRooms(rowLocations);
  }, [rowLocations, ensureRooms]);
  useEffect(() => {
    if (rowCategories.length) ensureSubcategories(rowCategories);
  }, [rowCategories, ensureSubcategories]);

  /* ---- row updates (the dependent-field rules live here) ---- */
  const clearServerError = useCallback((clientId, field) => {
    setServerErrors((all) => {
      const mine = all[clientId];
      if (!mine || !mine[field]) return all;
      const { [field]: _removed, ...rest } = mine;
      return { ...all, [clientId]: rest };
    });
  }, []);

  const updateRow = useCallback((clientId, update) => {
    dirtyRef.current = true;
    setRows((rs) => rs.map((r) => (r.clientId === clientId ? update(r) : r)));
  }, []);

  const handleField = useCallback(
    (clientId, field, value) => {
      updateRow(clientId, (r) => {
        const next = { ...r, [field]: value };
        if (field === 'location_code' && value !== r.location_code) next.room_id = '';
        if (field === 'category_code' && value !== r.category_code) next.subcategory_code = '';
        if (DEPENDENT_FIELDS.includes(field) && r.sequence_no !== null) {
          next.inventory_code = '';
          next.sequence_no = null;
          next.codeTouched = false;
          next.codeNotice =
            'Nomor inventaris dikosongkan karena lokasi, kategori, subkategori, atau tahun diubah. Nomor akan dibuat otomatis.';
        }
        return next;
      });
      clearServerError(clientId, field);
      if (DEPENDENT_FIELDS.includes(field)) clearServerError(clientId, 'inventory_code');
    },
    [updateRow, clearServerError],
  );

  const handleCode = useCallback(
    (clientId, value) => {
      updateRow(clientId, (r) => {
        const next = { ...r, inventory_code: value, sequence_no: null, codeNotice: '' };
        if (value === '') return { ...next, codeTouched: false };

        const parsed = parseInventoryCode(value);
        if (!parsed.ok) return next;
        const p = parsed.parts;
        // a unit_admin's location is fixed: a code from another location is
        // reported (see rowIssues), never applied
        if (lockLocation && p.location_code !== fixedLocation) return next;

        return {
          ...next,
          sequence_no: p.sequence_no,
          location_code: p.location_code,
          room_id: p.location_code === r.location_code ? r.room_id : '',
          category_code: p.category_code,
          subcategory_code: p.subcategory_code,
          asset_year: p.asset_year,
        };
      });
      clearServerError(clientId, 'inventory_code');
    },
    [updateRow, clearServerError, lockLocation, fixedLocation],
  );

  const handleCodeBlur = useCallback(
    (clientId) => setRows((rs) => rs.map((r) => (r.clientId === clientId && !r.codeTouched ? { ...r, codeTouched: true } : r))),
    [],
  );

  const addRow = () => {
    if (rows.length >= MAX_ROWS) return;
    dirtyRef.current = true;
    setRows((rs) => [...rs, newRow(fixedLocation)]);
  };

  const removeRow = useCallback((clientId) => {
    dirtyRef.current = true;
    setRows((rs) => (rs.length > 1 ? rs.filter((r) => r.clientId !== clientId) : rs));
    setServerErrors((all) => {
      if (!all[clientId]) return all;
      const { [clientId]: _removed, ...rest } = all;
      return rest;
    });
  }, []);

  /* ---- client-side checks (mirror the server; the server stays authoritative) ---- */
  const rowIssues = useCallback(
    (r, { all }) => {
      const e = {};
      if (r.inventory_code !== '') {
        const parsed = parseInventoryCode(r.inventory_code);
        if (!parsed.ok) {
          if (all || r.codeTouched) e.inventory_code = parsed.error;
        } else if (lockLocation && parsed.parts.location_code !== fixedLocation) {
          e.inventory_code = `Nomor inventaris ini milik lokasi ${parsed.parts.location_code}. Anda hanya dapat menambah aset di lokasi ${fixedLocation}.`;
        } else if (!md.locations.some((l) => l.code === parsed.parts.location_code)) {
          e.inventory_code = `Lokasi ${parsed.parts.location_code} pada nomor inventaris tidak ditemukan atau tidak aktif.`;
        } else if (!md.categories.some((c) => c.code === parsed.parts.category_code)) {
          e.inventory_code = `Kategori ${parsed.parts.category_code} pada nomor inventaris tidak ditemukan atau tidak aktif.`;
        } else {
          const subs = md.subcategoriesByCategory[parsed.parts.category_code];
          if (subs && !subs.some((s) => s.code === parsed.parts.subcategory_code)) {
            e.inventory_code = `Subkategori ${parsed.parts.subcategory_code} tidak ditemukan untuk kategori ${parsed.parts.category_code}.`;
          }
        }
      }
      if (!all) return e;

      if (!r.location_code) e.location_code = 'Lokasi wajib dipilih.';
      if (!r.category_code) e.category_code = 'Kategori wajib dipilih.';
      if (!r.subcategory_code) e.subcategory_code = 'Subkategori wajib dipilih.';
      if (!r.asset_year) e.asset_year = 'Tahun aset wajib diisi.';
      else {
        const y = Number(r.asset_year);
        if (!Number.isInteger(y) || y < 1980 || y > MAX_YEAR) e.asset_year = `Tahun harus antara 1980 dan ${MAX_YEAR}.`;
      }
      for (const [k, max] of Object.entries(MAX_LEN)) {
        if (r[k] && r[k].trim().length > max) e[k] = `Maksimal ${max} karakter.`;
      }
      if (r.purchase_date && Number.isNaN(new Date(r.purchase_date).getTime())) e.purchase_date = 'Tanggal tidak valid.';
      return e;
    },
    [lockLocation, fixedLocation, md.locations, md.categories, md.subcategoriesByCategory],
  );

  const errorsByRow = useMemo(() => {
    const out = {};
    for (const r of rows) {
      const merged = { ...rowIssues(r, { all: checkedIds.has(r.clientId) }), ...(serverErrors[r.clientId] ?? {}) };
      out[r.clientId] = Object.keys(merged).length ? merged : NO_ERRORS;
    }
    return out;
  }, [rows, rowIssues, checkedIds, serverErrors]);

  const rowsWithErrors = rows
    .map((r, i) => (errorsByRow[r.clientId] !== NO_ERRORS ? i + 1 : null))
    .filter((n) => n !== null);

  /* ---- submit ---- */
  const buildItem = (r) => ({
    location_code: r.location_code,
    category_code: r.category_code,
    subcategory_code: r.subcategory_code,
    asset_year: Number(r.asset_year),
    sequence_no: r.sequence_no,
    room_id: r.room_id === '' ? null : Number(r.room_id),
    condition: r.condition === '' ? null : r.condition,
    brand_model: trimOrNull(r.brand_model),
    detail_type: trimOrNull(r.detail_type),
    serial_no: trimOrNull(r.serial_no),
    material: trimOrNull(r.material),
    capacity_note: trimOrNull(r.capacity_note),
    purchase_date: trimOrNull(r.purchase_date),
    funding_source: trimOrNull(r.funding_source),
    notes: trimOrNull(r.notes),
  });

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    setSubmitAttempted(true);
    setCheckedIds(new Set(rows.map((r) => r.clientId)));
    setGeneralError('');
    setRejected(false);
    setFlash('');

    // the row summary below is derived from the rows themselves, so it stays
    // right when rows are later fixed, added or removed
    if (rows.some((r) => Object.keys(rowIssues(r, { all: true })).length)) return;

    const submitted = rows; // positions in this request = server error indexes
    setServerErrors({});
    setSubmitting(true);
    submittingRef.current = true;

    try {
      const res = await api.post('/api/assets/entries', { items: submitted.map(buildItem) });
      dirtyRef.current = false;
      setResult({ assets: res?.data ?? [], manual: submitted.map((r) => r.sequence_no !== null) });
    } catch (e) {
      if (!(e instanceof ApiError)) {
        setGeneralError('Terjadi kesalahan tak terduga. Coba lagi.');
        return;
      }
      if (e.status === 401) {
        refreshUser();
        return;
      }
      if (e.status === 403) {
        setGeneralError('Anda tidak memiliki izin untuk menambah aset di salah satu lokasi yang dipilih. Tidak ada aset yang disimpan.');
        return;
      }
      if (e.status === 422 && e.errors) {
        const { byRow, rowNumbers, general } = mapItemErrors(e.errors, submitted, displayField);
        setServerErrors(byRow);
        setRejected(true);
        setGeneralError(general.join(' ') || (rowNumbers.length ? '' : 'Periksa kembali isian yang ditandai.'));
        return;
      }
      setGeneralError(e.message || 'Gagal menyimpan aset. Coba lagi.');
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  };

  const startOver = () => {
    dirtyRef.current = false;
    setRows([newRow(fixedLocation)]);
    setServerErrors({});
    setSubmitAttempted(false);
    setCheckedIds(new Set());
    setGeneralError('');
    setRejected(false);
    setFlash('');
    setResult(null);
  };

  /* ---- inline room creation ---- */
  const roomRow = rows.find((r) => r.clientId === roomModalFor) ?? null;
  const openAddRoom = useCallback((clientId) => setRoomModalFor(clientId), []);

  const handleRoomCreated = (res) => {
    const room = res?.data;
    const rowIndex = rows.findIndex((r) => r.clientId === roomModalFor);
    setRoomModalFor(null);
    if (!room) return;

    addRoom(room);
    const selectsIt = roomRow !== null && roomRow.location_code === room.location?.code;
    if (selectsIt) {
      updateRow(roomRow.clientId, (r) => ({ ...r, room_id: String(room.id) }));
      clearServerError(roomRow.clientId, 'room_id');
    }
    setFlash(
      `Ruangan "${room.name}" berhasil dibuat${selectsIt ? ` dan dipilih pada Aset ${rowIndex + 1}` : ''}. ` +
        'Ruangan ini tersimpan sebagai data master dan tetap ada walaupun penambahan aset dibatalkan.',
    );
  };

  /* ---- render gates ---- */
  if (!canCreate) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk menambah aset."
        backTo="/inventory"
        backLabel="Kembali ke Inventaris"
      />
    );
  }
  if (!md.ready) return <FormSkeleton />;

  /* ---- success ---- */
  if (result) {
    return (
      <div className="mx-auto max-w-3xl space-y-5">
        <Link to={listTo} className="inline-flex items-center gap-1.5 rounded text-sm font-medium text-gray-600 hover:text-gray-900">
          <span aria-hidden="true">←</span> Kembali ke Inventaris
        </Link>

        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-5" role="status">
          <h1 className="text-base font-semibold text-emerald-900">{result.assets.length} aset berhasil ditambahkan</h1>
          <p className="mt-1 text-sm text-emerald-800">Kode aset di bawah sesuai urutan baris yang Anda isi.</p>
        </div>

        <ol className="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
          {result.assets.map((asset, i) => (
            <li key={asset.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
              <div>
                <span className="text-gray-500">Aset {i + 1} · </span>
                <Link to={`/inventory/${asset.id}`} className="font-mono font-medium text-gray-900 hover:underline">
                  {asset.asset_code}
                </Link>
                <span className="ml-2 text-gray-600">{asset.subcategory?.name ?? ''}</span>
              </div>
              <span className="text-xs text-gray-500">{result.manual[i] ? 'Nomor manual' : 'Nomor otomatis'}</span>
            </li>
          ))}
        </ol>

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Link
            to={listTo}
            className="rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:border-gray-400"
          >
            Lihat Inventaris
          </Link>
          <button
            type="button"
            onClick={startOver}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
          >
            Tambah aset lagi
          </button>
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
        <h1 className="text-xl font-semibold tracking-tight">Tambah Aset</h1>
        <p className="mt-1 text-sm text-gray-500">
          Isi satu atau beberapa aset, lalu simpan sekaligus. Setiap baris adalah satu aset. Jika satu baris belum
          benar, tidak ada aset yang disimpan sampai semua baris diperbaiki.
        </p>
      </header>

      {md.locations.length === 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
          Tidak ada lokasi aktif yang dapat dipilih.
        </div>
      )}

      {flash && (
        <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">
          {flash}
        </div>
      )}

      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        {rows.map((r, i) => (
          <AssetEntryRow
            key={r.clientId}
            row={r}
            number={i + 1}
            errors={errorsByRow[r.clientId]}
            locations={md.locations}
            categories={md.categories}
            subcategories={md.subcategoriesByCategory[r.category_code]}
            rooms={md.roomsByLocation[r.location_code]}
            roomsLoading={Boolean(r.location_code) && !(r.location_code in md.roomsByLocation)}
            subsLoading={Boolean(r.category_code) && !(r.category_code in md.subcategoriesByCategory)}
            lockLocation={lockLocation}
            canRemove={rows.length > 1}
            canCreateRoom={canCreateRoom}
            disabled={submitting}
            onField={handleField}
            onCode={handleCode}
            onCodeBlur={handleCodeBlur}
            onRemove={removeRow}
            onAddRoom={openAddRoom}
          />
        ))}

        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={addRow}
            disabled={submitting || rows.length >= MAX_ROWS}
            className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-400 px-3.5 py-2 text-sm font-medium text-gray-700 hover:border-gray-600 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <span aria-hidden="true" className="text-base leading-none">+</span> Tambah
          </button>
          <span className="text-xs text-gray-500">
            {rows.length} dari maksimal {MAX_ROWS} baris
          </span>
        </div>

        {generalError && (
          <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
            {generalError}
          </div>
        )}
        {submitAttempted && rowsWithErrors.length > 0 && (
          <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
            {rowList(rowsWithErrors)} perlu diperbaiki.{rejected ? ' Tidak ada aset yang disimpan.' : ''}
          </div>
        )}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Link
            to={listTo}
            className="rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:border-gray-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
          >
            Batal
          </Link>
          <button
            type="submit"
            disabled={submitting}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? 'Menyimpan…' : rows.length === 1 ? 'Simpan Aset' : `Simpan ${rows.length} Aset`}
          </button>
        </div>
      </form>

      {/* outside the <form>: the dialog is not a portal (see docblock) */}
      <RoomFormModal
        open={roomModalFor !== null}
        mode="create"
        locations={md.locations}
        defaultLocationCode={roomRow?.location_code ?? ''}
        onClose={() => setRoomModalFor(null)}
        onSuccess={handleRoomCreated}
      />
    </div>
  );
}
