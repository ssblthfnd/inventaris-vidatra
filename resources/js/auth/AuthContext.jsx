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
     * R7.1 — named capability flags, one per group of abilities this SPA
     * actually branches UI on. Each mirrors a real set from
     * `App\Support\PermissionRegistry` (see that file's per-role MAP) or a
     * legacy Gate in `AuthServiceProvider` — the comment on each names its
     * backend counterpart so the two never silently drift apart. These are
     * UI-ONLY: every one of them exists purely to decide what to show/hide/
     * navigate to, and every backend endpoint re-checks independently
     * regardless of what this object says — see each page's own fetch/submit
     * error handling for the real (403) enforcement.
     *
     * A few of these are DELIBERATELY narrower than `PermissionRegistry`
     * declares for `super_admin`/`unit_admin`, because several existing
     * routes were never migrated off a LEGACY Gate (`can:admin` /
     * `can:operator`) that only literal `admin`/`operator` satisfy —
     * confirmed by reading routes/api.php, not assumed. Exposing UI for a
     * capability the backend doesn't actually grant yet would just produce a
     * guaranteed 403 on click, so these flags reflect ACTUAL current access,
     * not the aspirational full PermissionRegistry map:
     *   - `canExportAssets`, `canPrintLabels`, `canViewImportHistory`,
     *     `canManageMasterData` (locations/categories/subcategories +
     *     admin's flat room browser) are still `admin`/`operator`
     *     (`canManageMasterData`: `admin` only) legacy-gated — `super_admin`
     *     does not yet have them despite PermissionRegistry listing them as
     *     '*'. This is a known, pre-existing backend gap (same category as
     *     room aliases staying `can:operator`), reported separately —
     *     R7.1 does not fix it, only avoids exposing UI that would 403.
     */
    const canWriteInventory = ['admin', 'operator', 'super_admin', 'unit_admin'].includes(role); // assets.create/edit/batchEdit/delete/writeOff/restore/revert/moveRoom
    const canImport = ['admin', 'operator', 'super_admin', 'unit_admin'].includes(role); // assets.import
    const canViewReports = ['admin', 'operator', 'super_admin', 'unit_admin'].includes(role); // assets.report
    const canManageRooms = ['admin', 'super_admin', 'unit_admin'].includes(role); // rooms.manage (NOT operator — never granted it)
    const canManageUsers = ['admin', 'super_admin'].includes(role); // users.manage
    const canExportAssets = role === 'admin' || role === 'operator'; // assets.export — legacy can:operator, super_admin/unit_admin excluded
    const canPrintLabels = role === 'admin' || role === 'operator'; // label PDF routes — legacy can:operator
    const canViewImportHistory = role === 'admin' || role === 'operator'; // GET /api/imports (history list) — legacy can:operator, deliberately not extended to unit_admin (R6 "Scope Control") or super_admin (unmigrated gate)
    const canManageMasterData = role === 'admin'; // locations/categories/subcategories + admin room browser — legacy can:admin, literal admin only

    return {
      user,
      loading,
      isAuthenticated: Boolean(user),
      login,
      logout,
      refreshUser,
      // UI-only role helpers — real enforcement is always backend authorization.
      isAdmin: role === 'admin',
      isSuperAdmin: role === 'super_admin',
      isUnitAdmin: role === 'unit_admin',
      // A unit_admin's own assigned location (02/03/04); null for every other role.
      locationCode: role === 'unit_admin' ? (user?.location_code ?? null) : null,
      isOperator: canWriteInventory, // kept for any external caller; every in-app call site now uses the named flags below
      isViewer: Boolean(user),
      canWriteInventory,
      canImport,
      canViewReports,
      canManageRooms,
      canManageUsers,
      canExportAssets,
      canPrintLabels,
      canViewImportHistory,
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
