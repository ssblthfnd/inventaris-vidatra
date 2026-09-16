<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Stage 6.9 R1 — foundation only. Answers exactly one question: which
 * `locations.code` values may a given user act on?
 *
 *   admin / super_admin / operator / viewer -> global (every code).
 *   unit_admin                              -> exactly the one code they're
 *                                              assigned to (never 01, never
 *                                              NULL — see {@see for()}).
 *
 * NOT wired into any Gate, middleware, controller, or FormRequest yet — this
 * class exists so later Stage 6.9 phases (inventory/master-data/import/
 * export/report/dashboard/revert scope enforcement) have one already-tested
 * place to ask "is this location in scope?" instead of each of them growing
 * its own `$user->location_code !== $thing->location_code` check.
 *
 * Deliberately NOT a general-purpose scoping framework: it knows nothing
 * about assets, rooms, or any other model, only about location codes as
 * plain strings, and it makes no assumption that a future domain (e.g.
 * procurement) will reuse this exact shape — a global/single-code split is
 * correct for unit_admin today but is not assumed to fit every future scope.
 */
final class LocationScope
{
    private function __construct(
        private readonly bool $global,
        private readonly ?string $locationCode,
    ) {}

    /**
     * Resolve a user's scope. For `unit_admin`, this is where the security
     * invariant lives: an account whose `location_code` is NULL, `01`, or
     * anything outside {02,03,04} is a corrupt/impossible state, and it MUST
     * NOT silently resolve to "global" just because something went wrong.
     * Laravel's standard authorization-denial exception is thrown instead —
     * the same exception `Gate::authorize()`/`$this->authorize()` already
     * throw everywhere else in this app (yields a 403 wherever this ends up
     * inside a request), so a broken unit_admin account fails the same way
     * as a legitimate out-of-scope request rather than as a distinct 500.
     * Both cases mean "this actor may not proceed" — differentiating
     * "corrupt data" from "legitimate denial" is deferred until this class
     * is actually called from request handling in a later phase.
     */
    public static function for(User $user): self
    {
        if ($user->role !== UserRole::UnitAdmin) {
            return new self(global: true, locationCode: null);
        }

        $code = $user->location_code;

        if ($code === null || ! in_array($code, UserRole::UNIT_ADMIN_LOCATION_CODES, true)) {
            throw new AuthorizationException(
                "unit_admin user #{$user->id} has an invalid location_code ('".
                ($code ?? 'NULL')."') — must be exactly one of 02/03/04. Refusing ".
                'to resolve a scope for this account rather than risk treating it as global.'
            );
        }

        return new self(global: false, locationCode: $code);
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    public function allows(string $locationCode): bool
    {
        return $this->global || $this->locationCode === $locationCode;
    }

    /**
     * @param  list<string>  $locationCodes
     * @return list<string>
     */
    public function filterCodes(array $locationCodes): array
    {
        if ($this->global) {
            return $locationCodes;
        }

        return array_values(array_filter(
            $locationCodes,
            fn (string $code): bool => $code === $this->locationCode
        ));
    }

    /**
     * @param  string|list<string>  $locationCodes
     *
     * @throws AuthorizationException if any supplied code is outside scope.
     *                                Never throws for a global scope.
     */
    public function assertAllowed(string|array $locationCodes): void
    {
        if ($this->global) {
            return;
        }

        foreach ((array) $locationCodes as $code) {
            if (! $this->allows($code)) {
                throw new AuthorizationException("Location '{$code}' is outside this user's scope.");
            }
        }
    }
}
