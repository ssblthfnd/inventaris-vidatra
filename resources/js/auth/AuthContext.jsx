import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { api, ApiError, setUnauthorizedHandler } from '../lib/api';

/**
 * Global authentication state (Tahap 5.7).
 *
 * Backed entirely by the Sanctum session cookie — this context holds only the
 * in-memory user object. On mount it probes `GET /api/me`:
 *   200 -> authenticated    401 -> guest    403 -> inactive (no access, treated as guest)
 */
const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const refreshUser = useCallback(async () => {
    try {
      const res = await api.get('/api/me');
      setUser(res?.data ?? null);
      return res?.data ?? null;
    } catch (e) {
      // 401 (guest) and 403 (inactive account) both mean "no app access"
      setUser(null);
      return null;
    }
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => setUser(null));

    let alive = true;
    (async () => {
      await refreshUser();
      if (alive) setLoading(false);
    })();

    return () => {
      alive = false;
      setUnauthorizedHandler(null);
    };
  }, [refreshUser]);

  const login = useCallback(async (email, password) => {
    const res = await api.post('/api/login', { email, password });
    const nextUser = res?.data ?? null;
    setUser(nextUser);
    return nextUser;
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/api/logout');
    } catch (e) {
      // session may already be gone on the server — clear locally regardless
      if (!(e instanceof ApiError)) throw e;
    } finally {
      setUser(null);
    }
  }, []);

  const value = useMemo(() => {
    const role = user?.role ?? null;

    /**
     * Stage 6.9 R9.3 — capabilities come from the backend, not from a role
     * list re-declared here. `GET /api/me` (and the login response) carries
     * `abilities`: exactly `App\Support\PermissionRegistry::abilitiesForRole()`,
     * the same source every `can:<ability>` route gate reads (WHAT); and
     * `is_global_scope`: whether `LocationScope` resolves this user to an
     * unrestricted scope (WHERE) — needed only for the few unscoped list
     * endpoints that admit global actors alone (import history, the flat
     * room browser, the generic room-alias list).
     *
     * `can(ability)` is the primitive; the named flags below are thin,
     * documented groupings of it for the call sites that already used them
     * (R7.1), so no page carries its own role → capability matrix anymore.
     * All of this is UI-ONLY — what to show/hide/navigate to. Every backend
     * endpoint re-checks independently; a hidden button is never the
     * security boundary (see each page's 403 handling).
     */
    const abilities = new Set(Array.isArray(user?.abilities) ? user.abilities : []);
    const can = (ability) => abilities.has(ability);
    const isGlobalScope = user?.is_global_scope === true;

    // Coarse "may write inventory at all" (create/edit/batchEdit/delete/writeOff/restore/revert/moveRoom
    // are granted together to every role today); use can('assets.<x>') where one action matters on its own.
    const canWriteInventory = can('assets.edit');
    const canImport = can('assets.import');
    const canViewReports = can('assets.report');
    const canManageRooms = can('rooms.manage'); // NOT operator — never granted it
    const canManageUsers = can('users.manage');
    const canExportAssets = can('assets.export'); // not unit_admin (product decision)
    const canPrintLabels = can('assets.printLabel'); // not unit_admin (product decision)
    // GET /api/imports is an unscoped every-batch list: assets.import (WHAT) + global scope (WHERE).
    const canViewImportHistory = can('assets.import') && isGlobalScope;
    // Generic alias list/CRUD (Master Data); the list is unscoped, so global scope too.
    const canManageRoomAliases = can('roomAliases.manage') && isGlobalScope;
    // Import room mapping "Simpan sebagai alias" — narrower than canManageRoomAliases; scope is
    // enforced per location by the backend (RoomMappingResolver).
    const canResolveRoomAliases = can('roomAliases.resolve');
    // Structural master data (locations/categories/subcategories) — the /master-data page.
    const canManageMasterData = can('locations.manage') || can('categories.manage') || can('subcategories.manage');

    return {
      user,
      loading,
      isAuthenticated: Boolean(user),
      login,
      logout,
      refreshUser,
      can,
      isGlobalScope,
      // UI-only role helpers for genuinely role-specific UI (labels, unit-name badge) —
      // never for deciding what an actor may do; use can()/the flags below for that.
      isSuperAdmin: role === 'super_admin',
      isUnitAdmin: role === 'unit_admin',
      // A unit_admin's own assigned location (02/03/04); null for every other role.
      locationCode: role === 'unit_admin' ? (user?.location_code ?? null) : null,
      isViewer: Boolean(user),
      canWriteInventory,
      canImport,
      canViewReports,
      canManageRooms,
      canManageUsers,
      canExportAssets,
      canPrintLabels,
      canViewImportHistory,
      canManageRoomAliases,
      canResolveRoomAliases,
      canManageMasterData,
    };
  }, [user, loading, login, logout, refreshUser]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth() must be used inside <AuthProvider>');
  return ctx;
}
