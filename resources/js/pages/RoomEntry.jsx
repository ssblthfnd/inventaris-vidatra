import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import RoomEntryRow from '../components/master-data/RoomEntryRow';
import { api, ApiError } from '../lib/api';
import { CenteredState, FormSkeleton } from '../lib/assetFields';
import { MAX_ENTRY_ROWS, makeClientId, mapItemErrors, rowList } from '../lib/entryRows';
import { useMasterData } from '../lib/useMasterData';

/**
 * Tambah Ruangan — multiple entry (Tahap 6.9 R10), `/rooms/new`.
 *
 * One or more room rows ("+ Tambah", max 100) saved together by ONE request:
 * `POST /api/rooms/entries { items: [...] }` — all rows are created or none is.
 * Reached from the "Tambah Ruangan" button of both room screens (the Master
 * Data Rooms panel and `/rooms`); editing / (de)activating a room stays in
 * `RoomFormModal` there.
 *
 * Locations come from `useMasterData()` (active only, and only the own one for
 * unit_admin, whose rows are fixed to it). The backend checks every row's
 * location again (RoomPolicy) and rejects the whole request for any row outside
 * the actor's scope.
 *
 * Rows are keyed by a stable `clientId`; `items.N.field` errors map back to the
 * submitted row N (`lib/entryRows`).
 */

const NO_ERRORS = Object.freeze({});
const MAX_LEN = { name: 100, pic: 100, notes: 255 };

const newRow = (locationCode = '') => ({ clientId: makeClientId(), location_code: locationCode, name: '', pic: '', notes: '' });

export default function RoomEntry() {
  const routerLocation = useLocation();
  const { can, isUnitAdmin, isGlobalScope, canManageMasterData, refreshUser } = useAuth();
  const md = useMasterData();

  const backTo =
    typeof routerLocation.state?.from === 'string'
      ? routerLocation.state.from
      : canManageMasterData && isGlobalScope
        ? '/master-data'
        : '/rooms';

  const fixedLocation = isUnitAdmin && md.locations.length === 1 ? md.locations[0].code : '';

  const [rows, setRows] = useState(() => [newRow()]);
  const [serverErrors, setServerErrors] = useState({});
  const [checkedIds, setCheckedIds] = useState(() => new Set());
  const [generalError, setGeneralError] = useState(''); // messages not tied to a row
  const [rejected, setRejected] = useState(false); // the last save was refused (nothing stored)
  const [submitting, setSubmitting] = useState(false);
  const [created, setCreated] = useState(null); // RoomResource[] in row order

  const dirtyRef = useRef(false);
  const submittingRef = useRef(false);

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

  useEffect(() => {
    if (!fixedLocation) return;
    setRows((rs) =>
      rs.some((r) => r.location_code === '')
        ? rs.map((r) => (r.location_code === '' ? { ...r, location_code: fixedLocation } : r))
        : rs,
    );
  }, [fixedLocation]);

  const handleField = useCallback((clientId, field, value) => {
    dirtyRef.current = true;
    setRows((rs) => rs.map((r) => (r.clientId === clientId ? { ...r, [field]: value } : r)));
    setServerErrors((all) => {
      const mine = all[clientId];
      // a location change can resolve a duplicate-name error too
      const fields = field === 'location_code' ? ['location_code', 'name'] : [field];
      if (!mine || !fields.some((f) => mine[f])) return all;
      const rest = { ...mine };
      fields.forEach((f) => delete rest[f]);
      return { ...all, [clientId]: rest };
    });
  }, []);

  const removeRow = useCallback((clientId) => {
    dirtyRef.current = true;
    setRows((rs) => (rs.length > 1 ? rs.filter((r) => r.clientId !== clientId) : rs));
    setServerErrors((all) => {
      if (!all[clientId]) return all;
      const { [clientId]: _removed, ...rest } = all;
      return rest;
    });
  }, []);

  const addRow = () => {
    if (rows.length >= MAX_ENTRY_ROWS) return;
    dirtyRef.current = true;
    setRows((rs) => [...rs, newRow(fixedLocation)]);
  };

  const rowIssues = (r) => {
    const e = {};
    if (!r.location_code) e.location_code = 'Lokasi wajib dipilih.';
    if (!r.name.trim()) e.name = 'Nama ruangan wajib diisi.';
    for (const [k, max] of Object.entries(MAX_LEN)) {
      if (r[k].trim().length > max) e[k] = `Maksimal ${max} karakter.`;
    }
    return e;
  };

  const errorsByRow = useMemo(() => {
    const out = {};
    for (const r of rows) {
      const merged = { ...(checkedIds.has(r.clientId) ? rowIssues(r) : {}), ...(serverErrors[r.clientId] ?? {}) };
      out[r.clientId] = Object.keys(merged).length ? merged : NO_ERRORS;
    }
    return out;
  }, [rows, checkedIds, serverErrors]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    setGeneralError('');
    setRejected(false);
    setCheckedIds(new Set(rows.map((r) => r.clientId)));
    // the row summary below is derived from the rows themselves
    if (rows.some((r) => Object.keys(rowIssues(r)).length)) return;

    const submitted = rows;
    setServerErrors({});
    setSubmitting(true);
    submittingRef.current = true;

    try {
      const res = await api.post('/api/rooms/entries', {
        items: submitted.map((r) => ({ location_code: r.location_code, name: r.name.trim(), pic: r.pic.trim(), notes: r.notes.trim() })),
      });
      dirtyRef.current = false;
      setCreated(res?.data ?? []);
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
        setGeneralError('Anda tidak memiliki izin untuk menambah ruangan di salah satu lokasi yang dipilih. Tidak ada ruangan yang disimpan.');
        return;
      }
      if (e.status === 422 && e.errors) {
        const { byRow, rowNumbers, general } = mapItemErrors(e.errors, submitted);
        setServerErrors(byRow);
        setRejected(true);
        setGeneralError(general.join(' ') || (rowNumbers.length ? '' : 'Periksa kembali isian yang ditandai.'));
        return;
      }
      setGeneralError(
        e.status >= 500 ? 'Terjadi kesalahan pada server. Tidak ada ruangan yang disimpan.' : e.message || 'Gagal menyimpan. Coba lagi.',
      );
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  };

  const startOver = () => {
    dirtyRef.current = false;
    setRows([newRow(fixedLocation)]);
    setServerErrors({});
    setCheckedIds(new Set());
    setGeneralError('');
    setRejected(false);
    setCreated(null);
  };

  if (!can('rooms.manage')) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk menambah ruangan."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }
  if (!md.ready) return <FormSkeleton />;

  if (created) {
    return (
      <div className="mx-auto max-w-3xl space-y-5">
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-5" role="status">
          <h1 className="text-base font-semibold text-emerald-900">{created.length} ruangan berhasil ditambahkan</h1>
        </div>
        <ol className="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white">
          {created.map((room, i) => (
            <li key={room.id} className="px-4 py-3 text-sm">
              <span className="text-gray-500">Ruangan {i + 1} · </span>
              <span className="font-medium text-gray-900">{room.name}</span>
              <span className="ml-2 text-gray-600">{room.location?.name}</span>
            </li>
          ))}
        </ol>
        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Link
            to={backTo}
            className="rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:border-gray-400"
          >
            Kembali ke daftar ruangan
          </Link>
          <button
            type="button"
            onClick={startOver}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
          >
            Tambah ruangan lagi
          </button>
        </div>
      </div>
    );
  }

  const rowsWithErrors = rows.map((r, i) => (errorsByRow[r.clientId] !== NO_ERRORS ? i + 1 : null)).filter((n) => n !== null);

  return (
    <div className="mx-auto max-w-3xl space-y-5 pb-4">
      <Link to={backTo} className="inline-flex items-center gap-1.5 rounded text-sm font-medium text-gray-600 hover:text-gray-900">
        <span aria-hidden="true">←</span> Kembali ke daftar ruangan
      </Link>

      <header>
        <h1 className="text-xl font-semibold tracking-tight">Tambah Ruangan</h1>
        <p className="mt-1 text-sm text-gray-500">
          Isi satu atau beberapa ruangan, lalu simpan sekaligus. Jika satu baris belum benar, tidak ada ruangan yang
          disimpan sampai semua baris diperbaiki.
        </p>
      </header>

      {md.locations.length === 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">
          Tidak ada lokasi aktif yang dapat dipilih.
        </div>
      )}

      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        {rows.map((r, i) => (
          <RoomEntryRow
            key={r.clientId}
            row={r}
            number={i + 1}
            errors={errorsByRow[r.clientId]}
            locations={md.locations}
            lockLocation={isUnitAdmin}
            canRemove={rows.length > 1}
            disabled={submitting}
            onField={handleField}
            onRemove={removeRow}
          />
        ))}

        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={addRow}
            disabled={submitting || rows.length >= MAX_ENTRY_ROWS}
            className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-gray-400 px-3.5 py-2 text-sm font-medium text-gray-700 hover:border-gray-600 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <span aria-hidden="true" className="text-base leading-none">+</span> Tambah
          </button>
          <span className="text-xs text-gray-500">
            {rows.length} dari maksimal {MAX_ENTRY_ROWS} baris
          </span>
        </div>

        {generalError && (
          <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
            {generalError}
          </div>
        )}
        {rowsWithErrors.length > 0 && (
          <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
            {rowList(rowsWithErrors)} perlu diperbaiki.{rejected ? ' Tidak ada ruangan yang disimpan.' : ''}
          </div>
        )}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Link
            to={backTo}
            className="rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:border-gray-400"
          >
            Batal
          </Link>
          <button
            type="submit"
            disabled={submitting}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? 'Menyimpan…' : rows.length === 1 ? 'Simpan Ruangan' : `Simpan ${rows.length} Ruangan`}
          </button>
        </div>
      </form>
    </div>
  );
}
