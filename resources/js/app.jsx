import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';

import { AuthProvider } from './auth/AuthContext';
import ThemeToggle from './components/ThemeToggle';
import AppShell from './layouts/AppShell';
import AssetDetail from './pages/AssetDetail';
import AssetForm from './pages/AssetForm';
import BatchAssetForm from './pages/BatchAssetForm';
import Dashboard from './pages/Dashboard';
import Imports from './pages/Imports';
import Inventory from './pages/Inventory';
import Landing from './pages/Landing';
import Login from './pages/Login';
import MasterData from './pages/MasterData';
import Reports from './pages/Reports';
import Rooms from './pages/Rooms';
import Trash from './pages/Trash';
import Users from './pages/Users';
import GuestRoute from './routes/GuestRoute';
import ProtectedRoute from './routes/ProtectedRoute';

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          {/* Public landing page for guests; a signed-in user goes to /dashboard as before. */}
          <Route
            path="/"
            element={
              <GuestRoute>
                <Landing />
              </GuestRoute>
            }
          />

          <Route
            path="/login"
            element={
              <GuestRoute>
                <Login />
              </GuestRoute>
            }
          />

          <Route
            element={
              <ProtectedRoute>
                <AppShell />
              </ProtectedRoute>
            }
          >
            <Route path="/dashboard" element={<Dashboard />} />
            <Route path="/inventory" element={<Inventory />} />
            <Route path="/inventory/new" element={<AssetForm mode="create" />} />
            <Route path="/inventory/batch" element={<BatchAssetForm />} />
            <Route path="/inventory/:assetId" element={<AssetDetail />} />
            <Route path="/inventory/:assetId/edit" element={<AssetForm mode="edit" />} />
            <Route path="/imports" element={<Imports />} />
            <Route path="/reports" element={<Reports />} />
            <Route path="/rooms" element={<Rooms />} />
            <Route path="/trash" element={<Trash />} />
            <Route path="/users" element={<Users />} />
            <Route path="/master-data" element={<MasterData />} />
          </Route>

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
        {/* every page, signed in or not */}
        <ThemeToggle />
      </AuthProvider>
    </BrowserRouter>
  );
}

createRoot(document.getElementById('app')).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
