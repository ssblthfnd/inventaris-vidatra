import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { useAuth } from '../auth/AuthContext';
import { api } from './api';

/**
 * Loads the master data the Inventaris filters need, straight from the Tahap 5.3
 * endpoints — nothing about locations / categories / subcategories / rooms is
 * hard-coded in the SPA.
 *
 *  - `/api/locations`  and `/api/categories` are fetched once on mount.
 *  - subcategories are fetched per category (`/api/categories/{code}/subcategories`)
 *    and cached; `ensureSubcategories(codes)` pulls any that are missing.
 *  - rooms are fetched per location (`/api/locations/{code}/rooms`) and cached;
 *    `ensureRooms(codes)` pulls any that are missing.
 *
 * Every list is reused from cache, so switching filters never re-hits the network
 * for data already held.
 *
 * R7.1 — `GET /api/locations` itself is NOT location-scoped by backend (every
 * role, including `unit_admin`, gets every active location back — confirmed
 * by reading `LocationController::index()`), so the returned `locations`
 * here is filtered client-side down to the actor's own location for
 * `unit_admin` before being handed to every consumer (Inventory/Reports
 * filters, AssetForm/BatchAssetForm's location select). This is the ONE
 * central place that narrowing happens — every one of those pages already
 * derives its own location dropdown options straight from this hook's
 * `locations`, so this single change is what makes "unit_admin never gets
 * offered a foreign location" true everywhere at once, per R7.1-C/D/F,
 * without duplicating the filter in each page. This is a UI convenience
 * only, not a security boundary — LocationScope on the backend is what
 * actually rejects an out-of-scope value regardless of what this hook ever
 * returns.
 */
export function useMasterData() {
  const { isUnitAdmin, locationCode } = useAuth();
  const [locationsRaw, setLocationsRaw] = useState([]);
  const [categories, setCategories] = useState([]);
  const [subcategoriesByCategory, setSubcategoriesByCategory] = useState({});
  const [roomsByLocation, setRoomsByLocation] = useState({});
  const [ready, setReady] = useState(false);

  // in-flight guards so React 18 double-invoke / rapid filter changes don't
  // fire duplicate requests
  const pendingSubs = useRef(new Set());
  const pendingRooms = useRef(new Set());

  useEffect(() => {
    let alive = true;
    Promise.all([api.get('/api/locations'), api.get('/api/categories')])
      .then(([loc, cat]) => {
        if (!alive) return;
        setLocationsRaw(loc?.data ?? []);
        setCategories(cat?.data ?? []);
      })
      .catch(() => {
        /* the page's own asset request surfaces auth / network errors */
      })
      .finally(() => {
        if (alive) setReady(true);
      });
    return () => {
      alive = false;
    };
  }, []);

  const ensureSubcategories = useCallback((codes) => {
    for (const code of codes) {
      if (!code) continue;
      if (pendingSubs.current.has(code)) continue;
      setSubcategoriesByCategory((current) => {
        if (current[code]) return current;
        pendingSubs.current.add(code);
        api
          .get(`/api/categories/${encodeURIComponent(code)}/subcategories`)
          .then((res) => {
            setSubcategoriesByCategory((c) => ({ ...c, [code]: res?.data ?? [] }));
          })
          .catch(() => {
            setSubcategoriesByCategory((c) => ({ ...c, [code]: [] }));
          })
          .finally(() => pendingSubs.current.delete(code));
        return current;
      });
    }
  }, []);

  const ensureRooms = useCallback((codes) => {
    for (const code of codes) {
      if (!code) continue;
      if (pendingRooms.current.has(code)) continue;
      setRoomsByLocation((current) => {
        if (current[code]) return current;
        pendingRooms.current.add(code);
        api
          .get(`/api/locations/${encodeURIComponent(code)}/rooms`)
          .then((res) => {
            setRoomsByLocation((c) => ({ ...c, [code]: res?.data ?? [] }));
          })
          .catch(() => {
            setRoomsByLocation((c) => ({ ...c, [code]: [] }));
          })
          .finally(() => pendingRooms.current.delete(code));
        return current;
      });
    }
  }, []);

  const locations = useMemo(
    () => (isUnitAdmin ? locationsRaw.filter((l) => l.code === locationCode) : locationsRaw),
    [locationsRaw, isUnitAdmin, locationCode],
  );

  return {
    ready,
    locations,
    categories,
    subcategoriesByCategory,
    roomsByLocation,
    ensureSubcategories,
    ensureRooms,
  };
}
