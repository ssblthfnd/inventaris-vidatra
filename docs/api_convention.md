# API Convention — Inventaris Vidatra

Status: **HTTP/API foundation established (Tahap 5.0), hardened (Tahap 5.2).**
Inventory endpoints (assets, master data, mutations, imports, reports) do **not exist
yet** — they arrive from **Tahap 5.3+** and must follow the conventions below.

---

## 1. Base path & routing

- All API endpoints live under **`/api`** (`routes/api.php`, `apiPrefix: 'api'` in `bootstrap/app.php`).
- The React SPA is served by Laravel for every **non-`/api`** path via the `{any}` route
  in `routes/web.php`. That route's constraint (`^(?!api($|/)).*$`) **explicitly excludes
  `/api/*`**, so an unknown or wrong-method API request produces a JSON `404` / `405`
  instead of the SPA HTML shell.

### Route naming

`api.login`, `api.logout`, `api.me`. Future resource routes follow
`api.<resource>.<action>` (e.g. `api.assets.index`, `api.assets.store`).

---

## 2. Authentication — Laravel Sanctum (SPA cookie / session)

- Package: `laravel/sanctum` **v4.3** · guard: **`web`** (`config('sanctum.guard')`,
  `SANCTUM_STATEFUL_DOMAINS`). **No bearer tokens, no `localStorage`.** First-party
  session cookie only.
- `$middleware->statefulApi()` prepends `EnsureFrontendRequestsAreStateful` to the `api`
  group; a request whose `Origin`/`Referer` host is in `SANCTUM_STATEFUL_DOMAINS` is
  authenticated via the session.
- `$middleware->redirectGuestsTo(fn () => null)` — this app has **no server-rendered
  login page**; an unauthenticated request is answered with a JSON `401`, never a
  redirect (the framework default `route('login')` would 500).

### Flow (SPA)

1. `GET /sanctum/csrf-cookie` → sets `XSRF-TOKEN` cookie (auto-registered by Sanctum).
2. `POST /api/login` `{ email, password }` → establishes the session, returns the user.
3. Any `GET/POST /api/…` → authenticated by the session cookie (`auth:sanctum`).
4. `POST /api/logout` → invalidates the session.

### Endpoints (current)

| Method | Path | Middleware | Result |
|---|---|---|---|
| `POST` | `/api/login` | `api` | `200` + `{data:user}` · `422` invalid input / wrong credentials · `403` account deactivated |
| `POST` | `/api/logout` | `api`, `auth:sanctum`, `auth.active` | `200 { "message": "Logged out." }` |
| `GET`  | `/api/me` | `api`, `auth:sanctum`, `auth.active` | `200` + `{data:user}` · `401` unauthenticated · `403` deactivated |

### `is_active`

- **Login** rejects `is_active = false` with **`403`** (`{"message":"Akun Anda telah dinonaktifkan."}`).
- **`auth.active`** middleware (`App\Http\Middleware\EnsureActiveUser`) rejects an
  already-authenticated user who is later deactivated, with the same **`403`**, on every
  protected route.

### Same-origin / CORS

Laravel serves the SPA at the **same origin**, so no CORS config is needed and none is
added. If a separate frontend dev origin is ever introduced, publish `config/cors.php`
with `supports_credentials => true` and `paths` including `api/*` + `sanctum/csrf-cookie`,
plus matching `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS`.

---

## 3. Authorization — role Gates (no permission package)

`users.role` `ENUM('admin','operator','viewer')` + `users.is_active`. Enum:
`App\Enums\UserRole`. Helpers on `App\Models\User`: `isAdmin()`, `hasRole(...)`,
`canWriteInventory()`.

| Role | Scope |
|---|---|
| `viewer` | all READ operations |
| `operator` | READ + write **assets / mutations / imports / room aliases** |
| `admin` | all operator rights + **user management** + **structural master-data changes** |
| *inactive (any role)* | **denied everything** |

### Gates — the single source of truth

Defined in `App\Providers\AuthServiceProvider::boot()`. An **inactive** user fails every Gate.

| Gate | Passes for |
|---|---|
| `viewer` | any active user |
| `operator` | active `admin` or `operator` |
| `admin` | active `admin` |

### Enforcement pattern (for Tahap 5.3+ endpoints)

Use the built-in **`can:` route middleware** on route groups — declarative, no controller
boilerplate, and it returns the correct `403`:

```php
Route::middleware(['auth:sanctum', 'auth.active', 'can:operator'])->group(function () {
    // asset write / mutation / import / alias routes
});

Route::middleware(['auth:sanctum', 'auth.active', 'can:viewer'])->group(function () {
    // all read routes
});
```

For finer per-record checks (Policies), add
`use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` to the base `Controller` when
the first Policy is introduced, then `$this->authorize(...)`. **Do not** wrap Gates in a
custom authorization abstraction.

---

## 4. Response envelope

Laravel-native `Illuminate\Http\Resources\Json\JsonResource` + paginator. **No custom
`{ success, status, message, errors, data }` envelope.**

| Shape | Body |
|---|---|
| Single resource | `{ "data": { … } }` |
| Non-paginated collection | `{ "data": [ … ] }` |
| Paginated collection | `{ "data": [ … ], "meta": { "current_page", "last_page", "per_page", "total" } }` |

Paginated list endpoints (Tahap 5.3+) return a subclass of
`App\Http\Resources\PaginatedResourceCollection`, which trims the pagination block to
those four `meta` keys and drops `links`.

---

## 5. Errors

Every `/api/*` response is JSON (`shouldRenderJsonWhen(api/* || expectsJson)` in
`bootstrap/app.php`) — including `401`, `403`, `404`, `405`, `422`, `500`.

| Code | Meaning | Body |
|---|---|---|
| `401` | **not authenticated** (`auth:sanctum` failed) | `{ "message": "Unauthenticated." }` |
| `403` | **authenticated but not permitted** / account deactivated | `{ "message": "…" }` |
| `404` | route or record not found | `{ "message": "…" }` |
| `405` | wrong HTTP method for the route | `{ "message": "…" }` |
| `409` | domain conflict (e.g. duplicate asset business identity) — *Tahap 5.4* | `{ "message": "…" }` |
| `422` | validation failure | `{ "message": "…", "errors": { "field": ["…"] } }` |
| `429` | rate limited | `{ "message": "…" }` |
| `500` | unexpected error | `{ "message": "…" }` |

**401 vs 403 — never confused:** a role/Gate denial is always `403`, never `401` and
never `422`. `401` means "log in"; `403` means "you may not".

Validation errors keep the exact Laravel shape (`FormRequest` rules /
`ValidationException::withMessages()`) — do not reshape.

---

## 6. Route model binding

API routes run through the `api` group (`SubstituteBindings` included). Implicit route
model binding (`/api/assets/{asset}`) is available for Tahap 5.3+ without extra setup;
scoped/soft-deleted binding is configured per route when needed.

---

## 7. Pagination request convention — *Planned (Tahap 5.3+)*

- `?page=<n>` — page number (Laravel default).
- `?per_page=<n>` — page size; the controller clamps to a max (e.g. 100), default 20.
- Filter / sort parameter names are defined per resource when those endpoints are built.

---

## 8. Test database convention

- **Development DB:** `inventaris_vidatra` (`.env`) — never touched by the test suite.
- **Test DB:** `inventaris_vidatra_test` (`phpunit.xml` + `.env.testing`).
- SQLite cannot be used — the domain schema needs MySQL 8 features (STORED generated
  column, `CHECK`, composite FKs, `utf8mb4_0900_ai_ci`).
- `tests/TestCase.php::refreshApplication()` **aborts the run** if the default connection
  is not `inventaris_vidatra_test`.
- Feature tests use `RefreshDatabase` + factories (never the real 394-row inventory data).
  HTTP authorization is verified with ephemeral probe routes registered inside the test.
- The Tahap 4 pipeline is additionally covered by `php artisan inventory:selftest`
  (26-case transactional matrix against the real MySQL dev DB, rolled back).

---

## 9. Not yet implemented — planned for future stages

The following are **conventions only** — no code exists yet:

| Stage | Adds |
|---|---|
| 5.3 | master-data + asset read APIs (list/show), `can:viewer` |
| 5.4 | asset write API + asset-number generator, `can:operator`, `409` on identity conflict |
| 5.5 | mutation-log API |
| 5.6 | room / room-alias management + import/report HTTP layer |
| 5.7 | full API feature-test matrix |

Do not document any of these as available endpoints until their stage lands.
