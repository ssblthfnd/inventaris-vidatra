import { useAuth } from '../auth/AuthContext';
import RoomAliasesPanel from '../components/master-data/RoomAliasesPanel';
import RoomsPanel from '../components/master-data/RoomsPanel';
import { CenteredState } from '../lib/assetFields';

/**
 * Master Data — `/master-data` (Tahap 6.8.1), admin-only. Same "always
 * visible in nav to admin only, page also self-gates" pattern as `Users.jsx`
 * (backend `can:admin` stays authoritative regardless).
 *
 * Tahap 6.8.1 added the Ruangan (Rooms) section; Tahap 6.8.2 adds Alias
 * Ruangan (Room Aliases) below it — both are plain stacked sections on this
 * one page, not tabs (the page still has only two sections; a tab switcher
 * would be premature UI for that). Note: `RoomAliasesPanel`'s own backend
 * routes are `can:operator`, but this whole PAGE stays `isAdmin`-gated for
 * now — an operator cannot reach it today even though the API would allow
 * their alias writes. Widening page access to operators is a frontend-only
 * decision for a future stage to make deliberately, not a side effect of
 * this one. Locations, Categories/Subcategories are deliberately still NOT
 * built — this page remains a thin container for whichever section a later
 * stage adds next.
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
    <div className="space-y-8">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Master Data</h1>
        <p className="mt-1 text-sm text-gray-500">Kelola data referensi aplikasi.</p>
      </header>

      <RoomsPanel />
      <RoomAliasesPanel />
    </div>
  );
}
