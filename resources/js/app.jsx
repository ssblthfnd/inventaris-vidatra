import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';

import { AuthProvider } from './auth/AuthContext';
import AppShell from './layouts/AppShell';
import AssetDetail from './pages/AssetDetail';
import AssetForm from './pages/AssetForm';
import BatchAssetForm from './pages/BatchAssetForm';
import Dashboard from './pages/Dashboard';
import Imports from './pages/Imports';
import Inventory from './pages/Inventory';
import Login from './pages/Login';
import MasterData from './pages/MasterData';
import Reports from './pages/Reports';
import Rooms from './pages/Rooms';
import Users from './pages/Users';
import GuestRoute from './routes/GuestRoute';
import ProtectedRoute from './routes/ProtectedRoute';

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
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
            <Route path="/users" element={<Users />} />
            <Route path="/master-data" element={<MasterData />} />
          </Route>

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}

createRoot(document.getElementById('app')).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
