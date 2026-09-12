<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 *
 * Read shape for the user-management API (Tahap 6.4) — deliberately a
 * SEPARATE resource from {@see UserResource} (used by `POST /api/login` and
 * `GET /api/me`) rather than adding fields to that one: `UserResource`'s exact
 * 5-key shape is contract-tested by `AuthFoundationTest::
 * test_me_returns_the_authenticated_user` (`assertExactJson`) as the
 * self-session bootstrap payload, and this feature's own consumer (an admin
 * list/edit view) needs `created_at` that endpoint has no reason to carry.
 * Same underlying model, same hidden `password`/`remember_token` attributes
 * (enforced by the model's `#[Hidden]` attribute either way) — just a
 * different set of exposed fields for a different audience.
 *
 * Never exposes: password, password hash, remember_token, or any Sanctum
 * token data.
 */
class UserManagementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
