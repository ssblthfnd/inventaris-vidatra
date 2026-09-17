<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 *
 * R7.1 — gained `location_code` (additive; the exact-shape contract test in
 * `AuthFoundationTest::test_me_returns_the_authenticated_user` updated
 * alongside it). The frontend has no other way to learn "what is MY own
 * unit" for a `unit_admin` actor — every other consumer of this resource
 * (`POST /api/login`, `GET /api/me`) is the self-session bootstrap payload,
 * and a `unit_admin`'s own scoped UI (nav, location-locked filters, the
 * Rooms page) needs this value client-side. Always `null` for every
 * non-`unit_admin` role, mirroring the column itself.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role?->value,
            'location_code' => $this->resource->location_code,
            'is_active' => $this->resource->is_active,
        ];
    }
}
