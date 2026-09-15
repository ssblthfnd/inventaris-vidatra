import { useAuth } from '../auth/AuthContext';
import RoomsPanel from '../components/master-data/RoomsPanel';
import { CenteredState } from '../lib/assetFields';

/**
 * Master Data — `/master-data` (Tahap 6.8.1), admin-only. Same "always
 * visible in nav to admin only, page also self-gates" pattern as `Users.jsx`
 * (backend `can:admin` stays authoritative regardless).
 *
 * Tahap 6.8.1 implements only the Ruangan (Rooms) section. Locations,
 * Categories/Subcategories and Room Aliases are deliberately NOT built yet —
 * this page is kept as a thin container so a later stage can add them
 * (e.g. as tabs) without this stage inventing tab-switching UI for sections
 * that don't exist yet.
 */
export default function MasterData() {
  const { isAdmin } = useAuth();

  if (!isAdmin) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Hanya admin yang dapat mengakses Master Data."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Master Data</h1>
        <p className="mt-1 text-sm text-gray-500">Kelola data referensi aplikasi.</p>
      </header>

      <RoomsPanel />
    </div>
  );
}
