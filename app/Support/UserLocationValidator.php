<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Http\Requests\Api\StoreUserRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Models\Location;

/**
 * Stage 6.9 R2 — the single place that decides whether a (role,
 * location_code) PAIR is structurally valid for a user record. Used by both
 * {@see StoreUserRequest} and {@see UpdateUserRequest}'s `withValidator()`
 * after-hooks so the business rule is defined once, not re-derived per
 * request class.
 *
 * The invariant (Stage 6.9 approved design):
 *   unit_admin       -> location_code required, must be one of
 *                       UserRole::UNIT_ADMIN_LOCATION_CODES, and that
 *                       location must actually exist and be active.
 *   everything else  -> location_code must be NULL (admin/super_admin/
 *                       operator/viewer are all global roles today).
 *
 * Deliberately a pure function returning a message-or-null rather than a
 * Closure/Rule object — keeps it trivially unit-testable, and both call
 * sites need an `after()` hook regardless (Store, because a plain closure
 * rule is skipped entirely when the field is absent from the payload;
 * Update, because the effective value may need merging with the target's
 * existing row first) so there's no simpler Rule-object shortcut to prefer.
 */
final class UserLocationValidator
{
    /** @return string|null a validation error message, or null if the pair is valid. */
    public static function validate(UserRole $role, ?string $locationCode): ?string
    {
        if ($role === UserRole::UnitAdmin) {
            if ($locationCode === null) {
                return 'Lokasi wajib diisi untuk role Unit Admin.';
            }

            if (! in_array($locationCode, UserRole::UNIT_ADMIN_LOCATION_CODES, true)) {
                return 'Lokasi untuk Unit Admin harus salah satu dari 02 (SD), 03 (SMP), atau 04 (SMA).';
            }

            $isActiveLocation = Location::query()
                ->where('code', $locationCode)
                ->where('is_active', true)
                ->exists();

            if (! $isActiveLocation) {
                return 'Lokasi tidak ditemukan atau tidak aktif.';
            }

            return null;
        }

        if ($locationCode !== null) {
            return "Lokasi harus kosong untuk role {$role->label()}.";
        }

        return null;
    }
}
