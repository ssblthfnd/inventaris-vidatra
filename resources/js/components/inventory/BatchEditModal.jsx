import { useEffect, useMemo, useState } from 'react';

import LifecycleConfirmDialog from '../LifecycleConfirmDialog';
import { api, ApiError } from '../../lib/api';
import { controlClass, trimOrNull } from '../../lib/assetFields';

/**
 * Batch edit dialog (Tahap 5.8.6) — one atomic `PATCH /api/assets/batch` request for
 * every selected asset. Only three fields are exposed: `room_id`, `condition`,
 * `notes` — everything else (classification, identity, lifecycle) is out of scope by
 * design (see the backend `BatchUpdateAssetRequest`).
 *
 * A field is only sent when its checkbox is on — an unchecked field is NEVER
 * touched, and checking a field never defaults to "clear it"; the operator must make
 * an explicit choice (a real placeholder option, distinct from "Belum diisi" / null).
 *
 * A room can only ever belong to one location, so batch room edits are only offered
 * when every selected asset shares the same location — otherwise no room could ever
 * be valid for all of them, and the backend would reject the whole batch anyway.
 */

const CONDITION_CHOICES = [
  { value: '', label: '— Pilih kondisi —' },
  { value: 'null', label: 'Belum diisi' },
  { value: 'baik', label: 'Baik' },
  { value: 'kurang_baik', label: 'Kurang Baik' },
  { value: 'rusak_berat', label: 'Rusak Berat' },
];

const CONDITION_LABELS = {
  baik: 'Baik',
  kurang_baik: 'Kurang Baik',
  rusak_berat: 'Rusak Berat',
};

function checkboxRow(id, checked, onChange, label, disabled = false) {
  return (
    <label
      htmlFor={id}
      className={`flex items-center gap-2 text-sm font-medium ${disabled ? 'text-gray-400' : 'text-gray-800'}`}
    >
      <input
        id={id}
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
      />
      {label}
    </label>
  );
}

export default function BatchEditModal({ open, assets, roomsByLocation, ensureRooms, onClose, onSuccess }) {
  const [enableRoom, setEnableRoom] = useState(false);
  const [enableCondition, setEnableCondition] = useState(false);
  const [enableNotes, setEnableNotes] = useState(false);

  const [roomChoice, setRoomChoice] = useState(''); // '' | 'clear' | '<id>'
  const [conditionChoice, setConditionChoice] = useState(''); // '' | 'null' | enum value
  const [notesMode, setNotesMode] = useState('replace'); // 'replace' | 'clear'
  const [notesText, setNotesText] = useState('');

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  // reset all local form state whenever the dialog is (re)opened for a new selection
  useEffect(() => {
    if (!open) return;
    setEnableRoom(false);
    setEnableCondition(false);
    setEnableNotes(false);
    setRoomChoice('');
    setConditionChoice('');
    setNotesMode('replace');
    setNotesText('');
    setError('');
    setFieldErrors({});
  }, [open]);

  const locationCodes = useMemo(
    () => [...new Set(assets.map((a) => a.location?.code).filter(Boolean))],
    [assets],
  );
  const singleLocation = locationCodes.length === 1 ? locationCodes[0] : null;

  useEffect(() => {
    if (enableRoom && singleLocation) ensureRooms([singleLocation]);
  }, [enableRoom, singleLocation, ensureRooms]);

  const roomOptions = singleLocation ? (roomsByLocation[singleLocation] ?? []) : [];

  if (!open) return null;

  const roomIdValue = roomChoice === '' ? undefined : roomChoice === 'clear' ? null : Number(roomChoice);
  const conditionValue = conditionChoice === '' ? undefined : conditionChoice === 'null' ? null : conditionChoice;
  const notesValue = notesMode === 'clear' ? null : trimOrNull(notesText);

  const roomReady = !enableRoom || (singleLocation !== null && roomIdValue !== undefined);
  const conditionReady = !enableCondition || conditionValue !== undefined;
  const anyFieldChecked = enableRoom || enableCondition || enableNotes;
  const canSubmit = anyFieldChecked && roomReady && conditionReady && !busy;

  /* ---------------------------------------------------------------- preview */
  const alreadyRoomCount =
    enableRoom && roomIdValue !== undefined
      ? assets.filter((a) => (a.room?.id ?? null) === roomIdValue).length
      : 0;
  const alreadyConditionCount =
    enableCondition && conditionValue !== undefined
      ? assets.filter((a) => (a.condition ?? null) === conditionValue).length
      : 0;

  const previewLines = [];
  if (enableRoom && roomIdValue !== undefined) {
    const label = roomIdValue === null ? 'Tanpa ruangan' : (roomOptions.find((r) => r.id === roomIdValue)?.name ?? '—');
    previewLines.push({ label: 'Ruangan', value: label });
  }
  if (enableCondition && conditionValue !== undefined) {
    previewLines.push({
      label: 'Kondisi',
      value: conditionValue === null ? 'Belum diisi' : CONDITION_LABELS[conditionValue],
    });
  }
  if (enableNotes) {
    previewLines.push({
      label: 'Catatan',
      value: notesMode === 'clear' ? 'Dikosongkan' : notesValue === null ? '(kosong)' : `"${notesValue}"`,
    });
  }

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError('');
    setFieldErrors({});

    const changes = {};
    if (enableRoom) changes.room_id = roomIdValue;
    if (enableCondition) changes.condition = conditionValue;
    if (enableNotes) changes.notes = notesValue;

    try {
      const res = await api.patch('/api/assets/batch', {
        asset_ids: assets.map((a) => a.id),
        changes,
      });
      setBusy(false);
      onSuccess(res);
    } catch (e) {
      setBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 403) {
          setError('Anda tidak memiliki izin untuk mengubah aset.');
          return;
        }
        if (e.status === 404) {
          setError('Salah satu aset tidak ditemukan.');
          return;
        }
        if (e.status === 409) {
          setError('Data berubah oleh proses lain. Silakan muat ulang daftar aset dan coba lagi.');
          return;
        }
        if (e.status === 422) {
          const mapped = {};
          for (const [key, msgs] of Object.entries(e.errors ?? {})) {
            mapped[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
          }
          setFieldErrors(mapped);
          setError(e.message || 'Periksa kembali perubahan yang dipilih.');
          return;
        }
        if (e.status >= 500) {
          setError('Terjadi kesalahan pada server. Tidak ada perubahan yang disimpan.');
          return;
        }
        setError(e.message || 'Gagal menyimpan perubahan. Coba lagi.');
        return;
      }
      setError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  const fieldError = (key) => fieldErrors[key] || fieldErrors[`changes.${key}`];

  return (
    <LifecycleConfirmDialog
      open={open}
      title={`Edit ${assets.length} aset sekaligus`}
      confirmLabel="Simpan Perubahan"
      confirmDisabled={!canSubmit}
      busy={busy}
      error={error}
      onConfirm={handleSubmit}
      onClose={onClose}
    >
      <p className="text-gray-600">Field yang ingin diubah:</p>

      <div className="space-y-4">
        {/* Ruangan */}
        <div className="rounded-lg border border-gray-200 p-3">
          {checkboxRow('batch-room', enableRoom, setEnableRoom, 'Ruangan')}
          {enableRoom && (
            <div className="mt-2 space-y-1.5">
              {singleLocation === null ? (
                <p className="rounded-md bg-amber-50 px-2.5 py-2 text-xs text-amber-700">
                  Aset yang dipilih berasal dari lebih dari satu lokasi. Ruangan tidak dapat
                  diedit massal untuk kombinasi ini — sebuah ruangan hanya berlaku untuk satu
                  lokasi.
                </p>
              ) : (
                <>
                  <select
                    value={roomChoice}
                    onChange={(e) => setRoomChoice(e.target.value)}
                    className={controlClass(fieldError('room_id'))}
                    aria-label="Pilih ruangan"
                  >
                    <option value="">— Pilih ruangan —</option>
                    <option value="clear">Tanpa ruangan (kosongkan)</option>
                    {roomOptions.map((r) => (
                      <option key={r.id} value={r.id}>
                        {r.name}
                      </option>
                    ))}
                  </select>
                  {fieldError('room_id') && (
                    <p className="text-xs text-red-600" role="alert">
                      {fieldError('room_id')}
                    </p>
                  )}
                  {alreadyRoomCount > 0 && (
                    <p className="text-xs text-gray-500">
                      {alreadyRoomCount} aset sudah berada di ruangan tersebut. Aset itu tidak
                      akan mengalami perubahan pada field ini.
                    </p>
                  )}
                </>
              )}
            </div>
          )}
        </div>

        {/* Kondisi */}
        <div className="rounded-lg border border-gray-200 p-3">
          {checkboxRow('batch-condition', enableCondition, setEnableCondition, 'Kondisi')}
          {enableCondition && (
            <div className="mt-2 space-y-1.5">
              <select
                value={conditionChoice}
                onChange={(e) => setConditionChoice(e.target.value)}
                className={controlClass(fieldError('condition'))}
                aria-label="Pilih kondisi"
              >
                {CONDITION_CHOICES.map((c) => (
                  <option key={c.value} value={c.value}>
                    {c.label}
                  </option>
                ))}
              </select>
              {fieldError('condition') && (
                <p className="text-xs text-red-600" role="alert">
                  {fieldError('condition')}
                </p>
              )}
              {alreadyConditionCount > 0 && (
                <p className="text-xs text-gray-500">
                  {alreadyConditionCount} aset sudah memiliki kondisi tersebut. Aset itu tidak
                  akan mengalami perubahan pada field ini.
                </p>
              )}
            </div>
          )}
        </div>

        {/* Catatan */}
        <div className="rounded-lg border border-gray-200 p-3">
          {checkboxRow('batch-notes', enableNotes, setEnableNotes, 'Catatan')}
          {enableNotes && (
            <div className="mt-2 space-y-2">
              <div className="flex gap-4 text-sm text-gray-700">
                <label className="flex items-center gap-1.5">
                  <input
                    type="radio"
                    name="notes-mode"
                    checked={notesMode === 'replace'}
                    onChange={() => setNotesMode('replace')}
                    className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
                  />
                  Ganti catatan
                </label>
                <label className="flex items-center gap-1.5">
                  <input
                    type="radio"
                    name="notes-mode"
                    checked={notesMode === 'clear'}
                    onChange={() => setNotesMode('clear')}
                    className="h-4 w-4 border-gray-300 text-gray-900 focus:ring-gray-900"
                  />
                  Kosongkan catatan
                </label>
              </div>
              {notesMode === 'replace' && (
                <textarea
                  value={notesText}
                  onChange={(e) => setNotesText(e.target.value)}
                  rows={3}
                  placeholder="Catatan baru untuk seluruh aset yang dipilih"
                  className={controlClass(fieldError('notes'))}
                />
              )}
              {fieldError('notes') && (
                <p className="text-xs text-red-600" role="alert">
                  {fieldError('notes')}
                </p>
              )}
            </div>
          )}
        </div>
      </div>

      {previewLines.length > 0 && (
        <div className="rounded-lg bg-gray-50 p-3 text-sm">
          <p className="font-medium text-gray-800">
            Anda akan mengubah {assets.length} aset.
          </p>
          <ul className="mt-1.5 space-y-0.5 text-gray-600">
            {previewLines.map((line) => (
              <li key={line.label}>
                {line.label} → {line.value}
              </li>
            ))}
          </ul>
          <p className="mt-1.5 text-xs text-gray-400">
            Perubahan akan diterapkan ke seluruh aset yang dipilih.
          </p>
        </div>
      )}
    </LifecycleConfirmDialog>
  );
}
