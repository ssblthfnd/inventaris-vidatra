import { useCallback, useEffect, useState } from 'react';

import { useAuth } from '../auth/AuthContext';
import LifecycleConfirmDialog from '../components/LifecycleConfirmDialog';
import ResetPasswordDialog from '../components/users/ResetPasswordDialog';
import UserFormModal from '../components/users/UserFormModal';
import { api, ApiError } from '../lib/api';
import { CenteredState } from '../lib/assetFields';

const ROLE_LABELS = { admin: 'Admin', operator: 'Operator', viewer: 'Viewer' };
const ROLE_FILTER_OPTIONS = [
  { value: '', label: 'Semua role' },
  { value: 'admin', label: 'Admin' },
  { value: 'operator', label: 'Operator' },
  { value: 'viewer', label: 'Viewer' },
];
const STATUS_FILTER_OPTIONS = [
  { value: '', label: 'Semua status' },
  { value: 'true', label: 'Aktif' },
  { value: 'false', label: 'Nonaktif' },
];

const SEARCH_DEBOUNCE_MS = 350;

function formatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' });
}

export default function Users() {
  const { user: currentUser, isAdmin } = useAuth();

  const [qInput, setQInput] = useState('');
  const [q, setQ] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  const [result, setResult] = useState(null);
  const [phase, setPhase] = useState('loading'); // loading | ready | error
  const [error, setError] = useState('');
  const [retryKey, setRetryKey] = useState(0);

  const [flash, setFlash] = useState('');
  const [flashTone, setFlashTone] = useState('success');

  const [formModal, setFormModal] = useState(null); // { mode: 'create'|'edit', user? }
  const [resetTarget, setResetTarget] = useState(null); // user
  const [statusTarget, setStatusTarget] = useState(null); // user (toggle confirm)
  const [statusBusy, setStatusBusy] = useState(false);
  const [statusError, setStatusError] = useState('');

  useEffect(() => {
    const t = setTimeout(() => {
      setQ(qInput);
      setPage(1);
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(t);
  }, [qInput]);

  const load = useCallback(() => {
    if (!isAdmin) return undefined;
    let alive = true;
    setPhase((p) => (result === null ? 'loading' : p));

    const params = new URLSearchParams();
    if (q.trim()) params.set('q', q.trim());
    if (role) params.set('role', role);
    if (status) params.set('is_active', status);
    if (page > 1) params.set('page', String(page));

    api
      .get(`/api/users?${params.toString()}`)
      .then((res) => {
        if (!alive) return;
        setResult(res);
        setPhase('ready');
        setError('');
      })
      .catch((e) => {
        if (!alive) return;
        setError(e instanceof ApiError ? e.message : 'Gagal memuat daftar pengguna.');
        setPhase('error');
      });

    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q, role, status, page, retryKey, isAdmin]);

  useEffect(() => load(), [load]);

  useEffect(() => {
    if (!flash) return undefined;
    const t = setTimeout(() => setFlash(''), 5000);
    return () => clearTimeout(t);
  }, [flash]);

  if (!isAdmin) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Hanya admin yang dapat mengakses Manajemen Pengguna."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }

  const users = result?.data ?? [];
  const meta = result?.meta;

  const handleFormSuccess = (res, action) => {
    setFormModal(null);
    setFlashTone('success');
    setFlash(action === 'create' ? 'Pengguna berhasil dibuat.' : 'Perubahan berhasil disimpan.');
    setRetryKey((k) => k + 1);
  };

  const handleResetSuccess = (res) => {
    setResetTarget(null);
    setFlashTone('success');
    setFlash(res?.message || 'Kata sandi berhasil direset.');
  };

  const openStatusToggle = (u) => {
    setStatusError('');
    setStatusTarget(u);
  };

  const confirmStatusToggle = async () => {
    if (!statusTarget) return;
    setStatusBusy(true);
    setStatusError('');
    try {
      await api.put(`/api/users/${statusTarget.id}`, {
        name: statusTarget.name,
        email: statusTarget.email,
        role: statusTarget.role,
        is_active: !statusTarget.is_active,
      });
      setStatusBusy(false);
      setStatusTarget(null);
      setFlashTone('success');
      setFlash(statusTarget.is_active ? 'Pengguna dinonaktifkan.' : 'Pengguna diaktifkan kembali.');
      setRetryKey((k) => k + 1);
    } catch (e) {
      setStatusBusy(false);
      if (e instanceof ApiError) {
        if (e.status === 422) {
          setStatusError(
            Object.values(e.errors ?? {})[0]?.[0] || e.message || 'Aksi ini tidak diizinkan.',
          );
          return;
        }
        setStatusError(e.message || 'Gagal menyimpan perubahan. Coba lagi.');
        return;
      }
      setStatusError('Terjadi kesalahan tak terduga. Coba lagi.');
    }
  };

  return (
    <div className="space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Pengguna</h1>
          <p className="mt-1 text-sm text-gray-500">Kelola akun dan akses pengguna aplikasi.</p>
        </div>
        <button
          type="button"
          onClick={() => setFormModal({ mode: 'create' })}
          className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-sm font-medium text-white hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
        >
          <span aria-hidden="true" className="text-base leading-none">+</span> Tambah Pengguna
        </button>
      </header>

      {flash && (
        <div
          role="status"
          className={
            flashTone === 'error'
              ? 'rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800'
              : 'rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm text-emerald-800'
          }
        >
          {flash}
        </div>
      )}

      {/* search + filters */}
      <div className="flex flex-wrap gap-2">
        <input
          type="search"
          value={qInput}
          onChange={(e) => setQInput(e.target.value)}
          placeholder="Cari nama atau email…"
          className="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 sm:w-64"
        />
        <select
          value={role}
          onChange={(e) => {
            setRole(e.target.value);
            setPage(1);
          }}
          className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-900"
        >
          {ROLE_FILTER_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-gray-900"
        >
          {STATUS_FILTER_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      </div>

      {/* body */}
      {phase === 'error' ? (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-6 text-center">
          <p className="text-sm text-red-700">{error || 'Gagal memuat daftar pengguna.'}</p>
          <button
            type="button"
            onClick={() => setRetryKey((k) => k + 1)}
            className="mt-3 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            Coba lagi
          </button>
        </div>
      ) : phase === 'loading' && result === null ? (
        <p className="text-sm text-gray-500">Memuat…</p>
      ) : users.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white px-4 py-12 text-center">
          <p className="text-sm font-medium text-gray-700">Tidak ada pengguna yang ditemukan.</p>
          <p className="mt-1 text-sm text-gray-500">Coba ubah kata pencarian atau filter yang digunakan.</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-gray-200 bg-white">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
                <th className="px-4 py-2.5">Nama</th>
                <th className="px-4 py-2.5">Email</th>
                <th className="px-4 py-2.5">Role</th>
                <th className="px-4 py-2.5">Status</th>
                <th className="px-4 py-2.5">Dibuat</th>
                <th className="px-4 py-2.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => {
                const isSelf = u.id === currentUser?.id;
                return (
                  <tr key={u.id} className="border-t border-gray-100">
                    <td className="px-4 py-2.5 font-medium text-gray-900">
                      {u.name}
                      {isSelf && <span className="ml-1.5 text-xs font-normal text-gray-400">(Anda)</span>}
                    </td>
                    <td className="px-4 py-2.5 text-gray-700">{u.email}</td>
                    <td className="px-4 py-2.5 text-gray-700">{ROLE_LABELS[u.role] ?? u.role}</td>
                    <td className="px-4 py-2.5">
                      <span
                        className={[
                          'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                          u.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500',
                        ].join(' ')}
                      >
                        {u.is_active ? 'Aktif' : 'Nonaktif'}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-gray-500">{formatDate(u.created_at)}</td>
                    <td className="px-4 py-2.5">
                      <div className="flex justify-end gap-1.5">
                        <button
                          type="button"
                          onClick={() => setFormModal({ mode: 'edit', user: u })}
                          className="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-gray-400"
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          onClick={() => setResetTarget(u)}
                          className="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-gray-400"
                        >
                          Reset Sandi
                        </button>
                        <button
                          type="button"
                          onClick={() => openStatusToggle(u)}
                          disabled={isSelf}
                          className={[
                            'rounded-md border px-2.5 py-1 text-xs font-medium',
                            isSelf
                              ? 'cursor-not-allowed border-gray-200 text-gray-300'
                              : u.is_active
                                ? 'border-red-300 text-red-700 hover:border-red-400'
                                : 'border-emerald-300 text-emerald-700 hover:border-emerald-400',
                          ].join(' ')}
                        >
                          {u.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.total > 0 && meta.last_page > 1 && (
        <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
          <span>
            Menampilkan {(meta.current_page - 1) * meta.per_page + 1}–
            {Math.min(meta.current_page * meta.per_page, meta.total)} dari {meta.total} pengguna
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => setPage((p) => p - 1)}
              disabled={meta.current_page <= 1}
              className="rounded-md border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50"
            >
              ‹ Sebelumnya
            </button>
            <button
              type="button"
              onClick={() => setPage((p) => p + 1)}
              disabled={meta.current_page >= meta.last_page}
              className="rounded-md border border-gray-300 px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Selanjutnya ›
            </button>
          </div>
        </div>
      )}

      <UserFormModal
        open={formModal !== null}
        mode={formModal?.mode}
        user={formModal?.user}
        currentUserId={currentUser?.id}
        onClose={() => setFormModal(null)}
        onSuccess={handleFormSuccess}
      />

      <ResetPasswordDialog
        open={resetTarget !== null}
        user={resetTarget}
        onClose={() => setResetTarget(null)}
        onSuccess={handleResetSuccess}
      />

      <LifecycleConfirmDialog
        open={statusTarget !== null}
        title={statusTarget?.is_active ? 'Nonaktifkan pengguna ini?' : 'Aktifkan pengguna ini?'}
        tone={statusTarget?.is_active ? 'danger' : 'default'}
        confirmLabel={statusTarget?.is_active ? 'Nonaktifkan' : 'Aktifkan'}
        busy={statusBusy}
        error={statusError}
        onConfirm={confirmStatusToggle}
        onClose={() => !statusBusy && setStatusTarget(null)}
      >
        {statusTarget && (
          <>
            <p>
              <span className="font-medium text-gray-900">{statusTarget.name}</span> ({statusTarget.email})
            </p>
            <p className="text-xs text-gray-500">
              {statusTarget.is_active
                ? 'Pengguna yang dinonaktifkan tidak dapat masuk atau menggunakan sesi yang sedang berjalan.'
                : 'Pengguna akan dapat masuk kembali seperti biasa.'}
            </p>
          </>
        )}
      </LifecycleConfirmDialog>
    </div>
  );
}
