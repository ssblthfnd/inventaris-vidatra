<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Http\RedirectResponse;

/**
 * Public QR-code redirect layer (Tahap 6.0): `GET /a/{asset}`.
 *
 * Printed asset labels encode `{QR_BASE_URL}/a/{asset->id}` — never asset_code,
 * location, or room, so a physical tag keeps pointing at the same record across
 * room moves or other lifecycle changes. This controller does nothing but resolve
 * that id and forward to the SPA's asset-detail path; it never returns asset data
 * itself, and it never touches authentication.
 *
 * The target path (`/inventory/{id}`) is served by the SPA catch-all in
 * routes/web.php and is itself behind React Router's `ProtectedRoute`, which
 * redirects to `/login` (preserving the intended path) if the visitor isn't signed
 * in, then back here after login. The actual asset data still comes from the
 * existing authenticated, authorized `GET /api/assets/{asset}` endpoint —
 * unchanged by this route.
 *
 * The route is registered `->withTrashed()`: a soft-deleted asset is still the
 * record the physical tag was printed for, so the redirect still resolves it.
 * Whether the signed-in user may actually see a trashed asset's detail remains
 * entirely governed by AssetController::show()'s existing authorization.
 */
class AssetQrRedirectController extends Controller
{
    public function __invoke(Asset $asset): RedirectResponse
    {
        return redirect('/inventory/'.$asset->id);
    }
}
