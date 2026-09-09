# API Convention — Inventaris Vidatra

Status: **foundation established in Tahap 5.0.** Inventory endpoints (assets, master
data, mutations, imports, reports) are added from Tahap 5.1 onward and must follow the
conventions below.

---

## 1. Base path

All API endpoints live under **`/api`** (registered in `routes/api.php`,
`apiPrefix: 'api'` in `bootstrap/app.php`).

The React SPA is served by Laravel for every non-`/api` path via the `{any}` catch-all
in `routes/web.php`. API routes are registered first, so `/api/*` is never intercepted
by the SPA fallback.

---

## 2. Authentication — Laravel Sanctum (SPA cookie / session)

- Package: `laravel/sanctum` **v4.3** · guard: `web` (`config/sanctum.stateful` + `SANCTUM_STATEFUL_DOMAINS`).
- **No bearer tokens, no `localStorage`.** First-party session cookie only.
- `$middleware->statefulApi()` in `bootstrap/app.php` prepends
  `EnsureFrontendRequestsAreStateful` to the `api` group; requests whose `Origin`/`Referer`
  host is in `SANCTUM_STATEFUL_DOMAINS` are authenticated via the session.

### Flow (SPA)

1. `GET /sanctum/csrf-cookie` → sets `XSRF-TOKEN` cookie (route auto-registered by Sanctum).
2. `POST /api/login` `{ email, password }` → establishes the session, returns the user.
3. Any `GET/POST /api/…` → authenticated by the session cookie (`auth:sanctum`).
4. `POST /api/logout` → invalidates the session.

### Endpoints (Tahap 5.0)

| Method | Path | Middleware | Result |
|---|---|---|---|
| `POST` | `/api/login` | `api` | `200` + user · `422` invalid input / wrong credentials · `403` account deactivated |
| `POST` | `/api/logout` | `api`, `auth:sanctum`, `auth.active` | `200 { "message": "Logged out." }` |
| `GET`  | `/api/me` | `api`, `auth:sanctum`, `auth.active` | `200` + current user · `401` unauthenticated · `403` deactivated |

### `is_active`

- **Login** rejects `is_active = false` with **`403`** (credentials may be valid; access denied).
- The **`auth.active`** middleware (`App\Http\Middleware\EnsureActiveUser`) rejects an
  already-authenticated user who is later deactivated, with **`403`**, on every protected route.

### Same-origin note / CORS

Tahap 5.0 assumes Laravel serves the SPA at the **same origin** (`http://localhost:8000`),
so no CORS configuration is needed. When a separate frontend dev origin is introduced
(e.g. Vite on `:5173`), publish `config/cors.php` and set
`supports_credentials => true` with `paths` including `api/*` and `sanctum/csrf-cookie`,
plus `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` for that host.

---

## 3. Authorization — role column (no permission package)

`users.role` `ENUM('admin','operator','viewer')` + `users.is_active`. Enum:
`App\Enums\UserRole`. Helpers on `App\Models\User`: `isAdmin()`, `hasRole(...)`,
`canWriteInventory()`.

| Role | Scope |
|---|---|
| `viewer` | read-only |
| `operator` | read + write **assets / mutations / imports / room aliases** |
| `admin` | everything, incl. user management & structural master-data changes |

**Gates** (`App\Providers\AuthServiceProvider`) — the single source of truth, used by
controllers (`Gate::allows()` / `$this->authorize()`) and by the `can:` route middleware
in Tahap 5.1+. An **inactive** user fails every Gate.

| Gate | Passes for |
|---|---|
| `viewer` | any active user |
| `operator` | active `admin` or `operator` |
| `admin` | active `admin` |

---

## 4. Response envelope

Laravel-native `Illuminate\Http\Resources\Json\JsonResource` + paginator. **No custom
`{ success, status, message, errors, data }` envelope.**

### Single resource

```json
{ "data": { } }
```

### Paginated collection

List endpoints return a subclass of `App\Http\Resources\PaginatedResourceCollection`,
which trims the pagination block to:

```json
{
  "data": [ ],
  "meta": { "current_page": 1, "last_page": 5, "per_page": 20, "total": 94 }
}
```

(No `links` block.)

### Non-paginated collection

```json
{ "data": [ ] }
```

---

## 5. Validation errors

Standard Laravel JSON validation response (`422 Unprocessable Content`):

```json
{
  "message": "The email field is required.",
  "errors": { "email": ["The email field is required."] }
}
```

Produced by `FormRequest` rules and `ValidationException::withMessages()`. Do not
reshape it.

---

## 6. HTTP status conventions

| Code | Use |
|---|---|
| `200` | success with body |
| `201` | resource created |
| `204` | success, no body (rare — prefer `200 { "message" }`) |
| `401` | not authenticated (`auth:sanctum` failed) |
| `403` | authenticated but not permitted / account deactivated |
| `404` | resource not found / not visible to the caller |
| `409` | domain conflict (e.g. duplicate asset business identity) |
| `422` | validation failure (Laravel shape) |
| `429` | rate limited |

`bootstrap/app.php` → `shouldRenderJsonWhen(api/* || expectsJson)` guarantees JSON error
bodies for the API.

---

## 7. Pagination request convention (for Tahap 5.1+ list endpoints)

- `?page=<n>` — page number (Laravel default).
- `?per_page=<n>` — page size; controller clamps to a sane max (e.g. 100), default 20.
- Filtering / sorting parameter names are defined per resource when those endpoints are built.

---

## 8. Test database convention

- **Development DB:** `inventaris_vidatra` (`.env`).
- **Test DB:** `inventaris_vidatra_test` (`phpunit.xml` env + `.env.testing`). Never the dev DB.
- SQLite cannot be used — the domain schema needs MySQL 8 features (STORED generated
  column, `CHECK`, composite FKs, `utf8mb4_0900_ai_ci`).
- `tests/TestCase.php::refreshApplication()` **aborts the run** if the default connection
  is not `inventaris_vidatra_test` (guards against `migrate:fresh` hitting dev data).
- Feature tests use `RefreshDatabase` (runs `migrate:fresh` once on the test DB, then a
  transaction per test).
- Pure logic tests (`tests/Unit/Import/*`, enum/resource shape) extend
  `PHPUnit\Framework\TestCase` and touch no database.
- The Tahap 4 pipeline is additionally covered by `php artisan inventory:selftest`
  (26-case transactional matrix against the real MySQL dev DB, rolled back).
