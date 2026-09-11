import { useEffect, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';

const ROLE_LABELS = {
  admin: 'Administrator',
  operator: 'Operator',
  viewer: 'Viewer',
};

/**
 * Navigation. Asset mutation/audit history is shown inline on Asset Detail
 * ("Riwayat Perubahan", Tahap 5.8.9) — there is no separate global history page,
 * so no corresponding sidebar entry exists here.
 */
const NAV = [
  { label: 'Dashboard', to: '/dashboard', ready: true },
  { label: 'Inventaris', to: '/inventory', ready: true },
];

function NavItems({ onNavigate }) {
  return (
    <nav className="flex flex-col gap-1 p-3">
      {NAV.map((item) =>
        item.ready ? (
          <NavLink
            key={item.label}
            to={item.to}
            onClick={onNavigate}
            className={({ isActive }) =>
              [
                'rounded-md px-3 py-2 text-sm font-medium transition-colors',
                isActive ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100',
              ].join(' ')
            }
          >
            {item.label}
          </NavLink>
        ) : (
          <span
            key={item.label}
            aria-disabled="true"
            title="Segera hadir"
            className="flex items-center justify-between rounded-md px-3 py-2 text-sm font-medium text-gray-400"
          >
            {item.label}
            <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-400">
              Segera
            </span>
          </span>
        ),
      )}
    </nav>
  );
}

export default function AppShell() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  // close the mobile drawer / user menu on navigation
  useEffect(() => {
    setSidebarOpen(false);
    setMenuOpen(false);
  }, [location.pathname]);

  const handleLogout = async () => {
    setLoggingOut(true);
    await logout();
    navigate('/login', { replace: true });
  };

  const roleLabel = ROLE_LABELS[user?.role] ?? user?.role ?? '';

  return (
    <div className="min-h-screen bg-gray-50 text-gray-900">
      <header className="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-gray-200 bg-white px-4">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={() => setSidebarOpen((v) => !v)}
            className="rounded-md p-2 text-gray-600 hover:bg-gray-100 md:hidden"
            aria-label="Buka menu"
          >
            <span className="block h-0.5 w-5 bg-current" />
            <span className="mt-1 block h-0.5 w-5 bg-current" />
            <span className="mt-1 block h-0.5 w-5 bg-current" />
          </button>
          <span className="text-base font-semibold tracking-tight">Inventaris Vidatra</span>
        </div>

        <div className="relative">
          <button
            type="button"
            onClick={() => setMenuOpen((v) => !v)}
            className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100"
          >
            <span className="hidden text-right sm:block">
              <span className="block font-medium leading-tight">{user?.name}</span>
              <span className="block text-xs text-gray-500">{roleLabel}</span>
            </span>
            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white">
              {(user?.name ?? '?').slice(0, 1).toUpperCase()}
            </span>
          </button>

          {menuOpen && (
            <div className="absolute right-0 mt-2 w-52 rounded-md border border-gray-200 bg-white py-1 shadow-lg">
              <div className="border-b border-gray-100 px-3 py-2 sm:hidden">
                <p className="text-sm font-medium">{user?.name}</p>
                <p className="text-xs text-gray-500">{user?.email}</p>
              </div>
              <p className="hidden px-3 py-2 text-xs text-gray-500 sm:block">{user?.email}</p>
              <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
                className="block w-full px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 disabled:opacity-60"
              >
                {loggingOut ? 'Keluar…' : 'Keluar'}
              </button>
            </div>
          )}
        </div>
      </header>

      {/*
        Root layout row. It spans the full viewport width and is NOT centered
        (`mx-auto` / `max-w-*` live on the page content below, never here) so the
        sidebar is always anchored to the left edge and its position can never be
        affected by the width or height of the active page.
      */}
      <div className="flex">
        {/* desktop sidebar — first flex child, constant width, viewport-left */}
        <aside className="hidden w-56 shrink-0 border-r border-gray-200 bg-white md:block">
          <div className="sticky top-14">
            <NavItems />
          </div>
        </aside>

        {/* mobile drawer */}
        {sidebarOpen && (
          <div className="fixed inset-0 z-40 md:hidden">
            <button
              type="button"
              aria-label="Tutup menu"
              className="absolute inset-0 bg-black/30"
              onClick={() => setSidebarOpen(false)}
            />
            <div className="absolute left-0 top-0 h-full w-64 border-r border-gray-200 bg-white shadow-xl">
              <div className="flex h-14 items-center border-b border-gray-200 px-4 font-semibold">
                Inventaris Vidatra
              </div>
              <NavItems onNavigate={() => setSidebarOpen(false)} />
            </div>
          </div>
        )}

        {/* content column — `min-w-0` lets it shrink instead of pushing the
            sidebar; `max-w-*` / `mx-auto` are scoped here, to the content only */}
        <main className="min-w-0 flex-1 px-4 py-6 sm:px-6">
          <div className="mx-auto w-full max-w-5xl">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}
