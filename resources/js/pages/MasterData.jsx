import { useAuth } from '../auth/AuthContext';
import CategoriesPanel from '../components/master-data/CategoriesPanel';
import LocationsPanel from '../components/master-data/LocationsPanel';
import RoomAliasesPanel from '../components/master-data/RoomAliasesPanel';
import RoomsPanel from '../components/master-data/RoomsPanel';
import SubcategoriesPanel from '../components/master-data/SubcategoriesPanel';
import { CenteredState } from '../lib/assetFields';

/**
 * Master Data — `/master-data` (Tahap 6.8.1). Same "gated in the nav list,
 * page also self-gates" pattern as `Users.jsx` (backend stays authoritative
 * regardless).
 *
 * Tahap 6.8.1 added Ruangan (Rooms); Tahap 6.8.2 added Alias Ruangan (Room
 * Aliases); Tahap 6.8.3 added Lokasi (Locations); Tahap 6.8.4 adds Kategori
 * and Subkategori. All five are plain stacked sections on this one page, not
 * tabs — ordered top-down by structural dependency (Lokasi -> Kategori ->
 * Subkategori -> Ruangan -> Alias Ruangan), matching how an admin would
 * actually work through onboarding new master data. A tab switcher remains
 * premature UI for five sections that read fine stacked.
 *
 * Stage 6.9 R9.3 — the page is gated on `canManageMasterData` (structural
 * master-data abilities from `/api/me`) instead of literal `isAdmin`, so
 * `super_admin` reaches it too; each panel is additionally shown only when
 * the actor holds that panel's own ability. The Rooms and Room Aliases
 * panels list every location (unscoped endpoints), so they also need a
 * global scope. An operator still never reaches this page (holds no
 * structural master-data ability) even though the API allows its generic
 * alias writes — widening page access to operators stays a deliberate
 * future decision, not a side effect of this one.
 */
export default function MasterData() {
  const { can, canManageMasterData, canManageRoomAliases, isGlobalScope } = useAuth();

  if (!canManageMasterData) {
    return (
      <CenteredState
        title="Akses ditolak"
        message="Anda tidak memiliki izin untuk mengakses Master Data."
        backTo="/dashboard"
        backLabel="Kembali ke Dashboard"
      />
    );
  }

  return (
    <div className="space-y-8">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Master Data</h1>
        <p className="mt-1 text-sm text-gray-500">Kelola data referensi aplikasi.</p>
      </header>

      {can('locations.manage') && <LocationsPanel />}
      {can('categories.manage') && <CategoriesPanel />}
      {can('subcategories.manage') && <SubcategoriesPanel />}
      {can('rooms.manage') && isGlobalScope && <RoomsPanel />}
      {canManageRoomAliases && <RoomAliasesPanel />}
    </div>
  );
}
