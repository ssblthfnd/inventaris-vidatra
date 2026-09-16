<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ResetUserPasswordRequest;
use App\Http\Requests\Api\StoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Http\Requests\Api\UserIndexRequest;
use App\Http\Resources\UserManagementCollection;
use App\Http\Resources\UserManagementResource;
use App\Models\User;
use App\Services\Asset\AssetWriteService;
use Illuminate\Http\JsonResponse;

/**
 * User management API (Tahap 6.4), `can:users.manage` only (Stage 6.9 R2 —
 * was `can:admin`; today only `admin`/`super_admin` satisfy that ability) —
 * a unit_admin/operator/viewer gets 403 for every action here (route
 * middleware, see routes/api.php), before this controller is ever reached.
 *
 * Deliberately no service layer: unlike asset writes (sequence generation,
 * mutation logging, concurrency retry — real complexity that justifies
 * {@see AssetWriteService}), user CRUD here is plain
 * Eloquent with validation-level business rules ({@see UpdateUserRequest}'s
 * self-protection closures) — adding a service class around that would be
 * over-engineering for what it does.
 *
 * No `destroy()` / `DELETE` route exists. `assets.created_by`/`updated_by`,
 * `mutation_logs.performed_by`, `import_batches.uploaded_by` and
 * `room_aliases.created_by` all reference `users` with `nullOnDelete()` — a
 * hard delete would silently NULL OUT attribution on historical audit
 * records the app deliberately built out in Tahap 5.8.8/5.8.9 (who created/
 * moved/wrote off an asset, who performed a mutation). Deactivation
 * (`is_active = false`, via `update()`) is therefore the only lifecycle
 * mechanism here — it already fully revokes access (see below) without
 * destroying history.
 *
 * Deactivation session behaviour needed NO new code: {@see
 * \App\Http\Middleware\EnsureActiveUser} already re-checks `users.is_active`
 * on every authenticated request (Tahap 5.2), and `AuthController::login()`
 * already rejects an inactive user at login. Setting `is_active = false` here
 * is the exact same column that mechanism already enforces — a deactivated
 * user is locked out on their very next request, no separate token/session
 * revocation invented.
 */
class UserController extends ApiController
{
    public function index(UserIndexRequest $request): UserManagementCollection
    {
        $users = User::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $like = '%'.$this->escapeLike((string) $request->string('q')).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->perPage())
            ->withQueryString();

        return new UserManagementCollection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            // Stage 6.9 R2 — absent key means "not provided", same as every
            // other role: StoreUserRequest already guarantees whatever is
            // persisted here is a structurally valid (role, location_code)
            // pair (UserLocationValidator), so no further coercion happens.
            'location_code' => $validated['location_code'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            // hashed automatically by User's `password => 'hashed'` cast — never
            // call Hash::make() here, that would double-hash it.
            'password' => $validated['password'],
        ]);

        return (new UserManagementResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserManagementResource
    {
        return new UserManagementResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserManagementResource
    {
        $user->update($request->validated());

        return new UserManagementResource($user->fresh());
    }

    /**
     * A deliberate, separate action from `update()` — see
     * {@see ResetUserPasswordRequest}. Never returns the new password.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $user->update(['password' => $request->validated('password')]);

        return response()->json(['message' => 'Kata sandi berhasil direset.']);
    }
}
