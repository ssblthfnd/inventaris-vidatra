import { Navigate } from 'react-router-dom';

import { useAuth } from '../auth/AuthContext';
import LoadingScreen from '../components/LoadingScreen';

/** Keeps already-authenticated users away from `/login`. */
export default function GuestRoute({ children }) {
  const { isAuthenticated, loading } = useAuth();

  if (loading) return <LoadingScreen message="Memeriksa sesi…" />;

  if (isAuthenticated) return <Navigate to="/dashboard" replace />;

  return children;
}
