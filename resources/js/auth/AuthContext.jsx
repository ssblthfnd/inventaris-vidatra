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
    return {
      user,
      loading,
      isAuthenticated: Boolean(user),
      login,
      logout,
      refreshUser,
      // UI-only role helpers — real enforcement is always backend authorization.
      isAdmin: role === 'admin',
      isOperator: role === 'admin' || role === 'operator',
      isViewer: Boolean(user),
    };
  }, [user, loading, login, logout, refreshUser]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth() must be used inside <AuthProvider>');
  return ctx;
}
