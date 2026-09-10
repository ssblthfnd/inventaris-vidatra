import { Navigate, useLocation } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import LoadingScreen from '../components/LoadingScreen';

/**
 * Gate for authenticated-only areas. Performs a real redirect to `/login`
 * (not a conditional hide) once auth state has finished loading.
 */
export default function ProtectedRoute({ children }) {
  const { isAuthenticated, loading } = useAuth();
  const location = useLocation();

  if (loading) return <LoadingScreen message="Memeriksa sesi…" />;

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  return children;
}
