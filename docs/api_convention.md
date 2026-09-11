# API Convention — Inventaris Vidatra

Status: **HTTP/API foundation established (Tahap 5.0), hardened (Tahap 5.2),
read-only inventory + master-data endpoints (Tahap 5.3), asset write API & lifecycle
(Tahap 5.4), mutation history read API (Tahap 5.5), dashboard aggregation API
(Tahap 5.6), multi-value inventory filters (Tahap 5.8.1).**
Still to come: non-relocation mutation types, room / room-alias management, filtered
reporting, and the import/report HTTP layer (Tahap 5.7+). Those must follow the
conventions below.

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

## 7. Pagination request convention

- `?page=<n>` — page number (Laravel default). Validated `integer|min:1`.
- `?per_page=<n>` — page size, default `20`. Validated `integer|min:1|max:100`.
  **A `per_page` above 100 is rejected with `422` — it is never silently clamped.**
- Only the **assets** list is paginated. Master-data lists are finite collections
  (see §11).

---

## 8. Read API — inventory & master data (Tahap 5.3)

All routes below sit behind `auth:sanctum` + `auth.active` + **`can:viewer`**
(`routes/api.php`). Every active user — `viewer`, `operator`, `admin` — has full read
access. Unauthenticated → `401`; authenticated-but-inactive → `403`. The asset **write**
endpoints (Tahap 5.4) are in §12.

| Method | Path | Name | Notes |
|---|---|---|---|
| `GET` | `/api/assets` | `api.assets.index` | paginated; filters + search + sort below |
| `GET` | `/api/assets/{asset}` | `api.assets.show` | implicit binding by `id`; `404` if missing **or soft-deleted** |
| `GET` | `/api/locations` | `api.locations.index` | finite collection, active only, `?q=` |
| `GET` | `/api/locations/{location}` | `api.locations.show` | bound by `code`; `404` if inactive |
| `GET` | `/api/categories` | `api.categories.index` | finite collection, active only, `?q=` |
| `GET` | `/api/categories/{category}` | `api.categories.show` | bound by `code`; `404` if inactive |
| `GET` | `/api/categories/{category}/subcategories` | `api.categories.subcategories.index` | scoped to the parent category, active only, `?q=` |
| `GET` | `/api/subcategories/{subcategory}` | `api.subcategories.show` | bound by `id`; `404` if inactive |
| `GET` | `/api/locations/{location}/rooms` | `api.locations.rooms.index` | scoped to the parent location, active only, `?q=` |
| `GET` | `/api/rooms/{room}` | `api.rooms.show` | bound by `id`; `404` if inactive |

### 8.1 `GET /api/assets` — query parameters

**Filters** (all optional). Every filter below is **multi-value** since Tahap 5.8.1:

| Param | Rule (per value) | Effect |
|---|---|---|
| `location_code` | `exists:locations,code` | `where location_code IN (...)` |
| `category_code` | `exists:categories,code` | `where category_code IN (...)` |
| `subcategory_code` | bare code, or category-qualified `CC.SSS` — see §8.1.2 | category-aware `OR` of `(category_code, subcategory_code)` pairs |
| `room_id` | `exists:rooms,id`; must belong to a selected `location_code` when that filter is present | `where room_id IN (...)` |
| `condition` | one of `baik`, `kurang_baik`, `rusak_berat` (`App\Enums\AssetCondition`), or the sentinel `unknown` = `condition IS NULL` | `where (condition IN (...) [OR condition IS NULL])` |
| `is_written_off` | boolean (`1`/`0`) | `where is_written_off = ?` — see §8.1.1 |
| `asset_year` | `integer`, `1980 .. currentYear+1` | `where asset_year IN (...)` |

`asset_year`'s upper bound is **next calendar year**, not the importer's `2100` — a
*query* filter for a year further out is meaningless. Absurd values (`-999`, `999999`)
are rejected `422`.

#### 8.1.1 Multi-value contract

- **Syntax** — the natural Laravel array form: `?category_code[]=02&category_code[]=03`.
  A bare scalar (`?category_code=02`) is still accepted and normalised to a
  one-element list, so every pre-5.8.1 request keeps working unchanged.
- **Semantics** — **OR within one filter, AND between filters.**
  `?category_code[]=02&category_code[]=03&condition[]=baik` ⇒
  `(category 02 OR 03) AND (condition baik)`.
- **Duplicates** — a repeated value in one filter
  (`?category_code[]=02&category_code[]=02`) is **`422`** (`distinct`), not silently
  de-duplicated. The `errors` key is the bare filter name (`category_code`), never
  `category_code.0` — the single-value 5.3 error shape is preserved.
- **Blank entries** (`?condition[]=`) are dropped before validation.
- **`condition[]=unknown`** filters `condition IS NULL`. Combined with real values it
  is `OR`-ed: `?condition[]=baik&condition[]=unknown` ⇒
  `condition = 'baik' OR condition IS NULL`. The string `unknown` is the only accepted
  non-enum token (the same term the dashboard API uses for NULL); `yes`/`maybe`/… →
  `422`.
- **`is_written_off[]=1&is_written_off[]=0`** — both statuses accepted ⇒ equivalent to
  *no* status filter (a no-op after validation). One value still filters normally.
- **Empty result** from a valid filter combination is `200` with `data: []`, never
  `404`.

#### 8.1.2 `subcategory_code[]` — category-aware

`subcategory_code` is **never** a global `WHERE subcategory_code IN (...)` — `code` is
not unique across categories (schema_design.md §3.2). Each value is one of:

- a **bare code** (`001`): resolved to every `(category_code, code)` pair that exists,
  restricted to `category_code[]` when that filter is present;
- a **category-qualified pair** `CC.SSS` (`02.001`): the exact pair `(02, 001)`. When
  `category_code[]` is present, the qualified category must be one of its values
  (else `422`).

The resolved pairs become `WHERE (category_code = ? AND subcategory_code = ?) OR …`,
so subcategory `001` of category `02` can never match category `03`. Example:
`?category_code[]=02&category_code[]=03&subcategory_code[]=02.001` returns only the
category-`02` `001` assets.

**Search** — `?q=<term>`, max 100 chars, case-insensitive partial match (bound `LIKE`,
`%`/`_`/`\` escaped — never string interpolation) across:
`asset_code`, `sequence_no`, `brand_model`, `serial_no`, `material`, `notes`, and the
related **room name**.

**Sort** — `?sort=<col>&direction=<asc|desc>`. `sort` whitelist (server-side; anything
else → `422`): `asset_code`, `asset_year`, `sequence_no`, `brand_model`, `serial_no`,
`purchase_date`, `created_at`. Defaults: `sort=asset_code`, `direction=asc`. A stable
tie-break on `id` is always appended.

### 8.2 Soft-delete vs written-off

Two independent lifecycle concepts — never conflated (see also §12.4):

- **Soft-deleted** (`deleted_at` set) = an erroneous record. **Never returned** by the
  read API — the default `SoftDeletes` scope applies and `withTrashed()` is not used on
  any read path. `GET /api/assets/{id}` for a soft-deleted asset → `404`. The row and
  its inventory number are kept forever (the number is never reissued).
- **Written-off** (`is_written_off = true`) = a real asset, disposed of in business
  terms. Appears in listings normally; filter with `is_written_off=1` / `=0`. It still
  holds its asset number and stays fully readable.

### 8.3 `AssetResource` shape

Exposes identity + descriptive columns and nested `location {code,name,alias}`,
`category {code,name}`, `subcategory {code,name}`, `room {id,name}` (or `null`).
`subcategory` is always resolved from the asset's **own** `(category_code,
subcategory_code)` pair — a same-code subcategory from another category is never
substituted.

**Never exposed:** `import_row_id`, `created_by`, `updated_by`, `created_at`,
`updated_at`, `deleted_at`, and anything credential-like. `asset_code` is emitted
verbatim from the DB (STORED generated column) — never recomputed in PHP.

---

## 9. N+1 avoidance

`GET /api/assets` eager-loads `location`, `category`, `room`, and bulk-resolves the
composite `subcategory` for the whole page in **one** query
(`Asset::loadSubcategoriesFor()`). Query count does not grow with the number of rows on
the page. No caching / search-engine / denormalization layer is introduced (premature).

---

## 10. Route model binding

- `Location` `{location}` and `Category` `{category}` bind by their natural PK `code`.
- `Asset`, `Subcategory`, `Room` bind by `id`.
- A missing record yields a JSON `404`. Inactive master records are treated as missing
  (`404`) at the `show` endpoints; the `index` endpoints omit them.

---

## 11. Master-data collections are not paginated

`locations`, `categories`, `subcategories` (per category) and `rooms` (per location)
are small, bounded reference sets whose natural client use is a **dropdown / lookup
source** that wants the whole list at once. They are returned as plain
`{ "data": [ … ] }` collections (no `meta`), filterable with `?q=`. Only `assets`
— unbounded and growing — is paginated. If any of these sets ever grows large enough to
need paging, it gets `per_page` then, consistently with §7.

---

## 12. Write API — asset write & lifecycle (Tahap 5.4)

All routes below sit behind `auth:sanctum` + `auth.active` + **`can:operator`**
(`routes/api.php`). `operator` **and** `admin` pass (the `operator` Gate already admits
admins). `viewer` / inactive → `403`; unauthenticated → `401`. Business logic lives in
`App\Services\Asset\{AssetWriteService, AssetNumberGenerator, AssetMutationRecorder}` —
controllers stay thin.

| Method | Path | Name | Success | Notes |
|---|---|---|---|---|
| `POST` | `/api/assets` | `api.assets.store` | `201` + `{data}` | server allocates `sequence_no`; DB generates `asset_code` |
| `PUT` / `PATCH` | `/api/assets/{asset}` | `api.assets.update` | `200` + `{data}` | partial (PATCH-style) for both verbs |
| `DELETE` | `/api/assets/{asset}` | `api.assets.destroy` | `204` (no body) | soft delete only |
| `POST` | `/api/assets/{asset}/restore` | `api.assets.restore` | `200` + `{data}` | the only route that resolves a trashed asset (`->withTrashed()`) |
| `POST` | `/api/assets/{asset}/write-off` | `api.assets.write-off` | `200` + `{data}` | `409` if already written-off |
| `POST` | `/api/assets/{asset}/unwrite-off` | `api.assets.unwrite-off` | `200` + `{data}` | `409` if not written-off |

`update`, `destroy`, `write-off`, `unwrite-off` use the **default** binding — a
soft-deleted asset is not found → `404`. There are **no** endpoints for mutation logs,
and none for editing master-data codes.

### 12.1 Create (`POST /api/assets`)

Accepted body: `location_code`, `category_code`, `subcategory_code`, `asset_year`,
`room_id`, `condition`, `is_written_off`, `written_off_on`, `written_off_note`,
`quantity`, `brand_model`, `serial_no`, `material`, `purchase_date`, `funding_source`,
`detail_type`, `capacity_note`, `notes`.

- `sequence_no` and `asset_code` are **`prohibited`** — sending either is a `422`
  (explicit rejection, not a silent drop). The server allocates the sequence; the
  database generates `asset_code` (STORED column, never built in PHP).
- `quantity` defaults to `1` and **must** be `1` (`422` otherwise — no grouped assets).
- `is_written_off` defaults to `false`. If `true`, `written_off_on` is **required**
  (`date`, not in the future); `condition` is **not** changed as a side effect.
- Defaults: `quantity → 1`, `is_written_off → false`. `condition` may be `null`.

Master-data validation (§9):

| Field | Rule |
|---|---|
| `location_code` | exists **and** `is_active` |
| `category_code` | exists **and** `is_active` |
| `subcategory_code` | composite — `(category_code, subcategory_code)` must exist and be active; never a bare `exists:subcategories,code` |
| `room_id` | exists, `is_active`, **and** `location_code` = the asset's location (a room from another location → `422`) |
| `asset_year` | `integer`, `1980 .. currentYear+1` |
| `condition` | `null` or one of `baik` / `kurang_baik` / `rusak_berat` |

The database composite FKs (`fk_assets_subcategory`, `fk_assets_room`) remain as
defence-in-depth behind the validation.

### 12.2 Asset numbering

`asset_code = location_code.category_code.subcategory_code.sequence_no.asset_year`
(e.g. `01.02.001.001.2025`). The generator only ever produces the **sequence_no**.

- **Scope** of a sequence family is `location_code + category_code + subcategory_code`
  — **NOT** the year. Numbering is **continuous across years**: 2025 → `001,002,003`,
  2026 → `004,005,006`.
- The "family" of an existing sequence is its leading numeric run:
  `005`, `005A`, `005B` → family 5; `0017B` → 17; `0001` → 1. The next number is
  `MAX(family) + 1` computed **numerically** in MySQL
  (`CAST(REGEXP_SUBSTR(sequence_no,'^[0-9]+') AS UNSIGNED)`), never lexically
  (`'1000' < '999'` as strings).
- New numbers are zero-padded to 3 digits through `999`, then plain: `999 → 1000 → 1001`.
  Letter suffixes are **historical-only** and never generated.
- **Soft-deleted rows are counted** — a retired number is never handed out again.
- Historical Excel sequences are the source of truth and are **never normalised**
  (`0001`, `005A`, `0017B` stay verbatim). The generator is used **only** for assets
  created through this write API — the importer never calls it.

### 12.3 Update (`PUT|PATCH /api/assets/{asset}`)

- Partial semantics for both verbs. `sequence_no` and `asset_code` can never change
  (`prohibited` → `422`). Missing placement keys are backfilled from the current asset
  so the composite / room↔location rules always see a complete picture.
- `is_written_off`, `written_off_on`, `written_off_note` are **`prohibited`** — status
  changes go through the dedicated `write-off` / `unwrite-off` endpoints.
- Changing `category_code` / `subcategory_code` / `location_code` re-generates
  `asset_code` at the database (the model is refreshed so the Resource shows the new
  value); `sequence_no` is untouched.
- A relocation — a change of `location_code` **and/or** `room_id` — writes one
  append-only `mutation_logs` row in the **same transaction** (§12.5), `event_type =
  MOVE_ROOM` (Tahap 5.8.8). A change of only descriptive fields or `condition` writes
  a generic `event_type = EDIT` row instead (Tahap 5.8.8) — never both for one
  request. A no-op request (resubmitting identical values) writes **no** row at all.

### 12.4 Soft delete / restore

- `DELETE` performs `SoftDeletes::delete()` only — **never** a hard delete. The row,
  its number and its mutation history all remain. After delete, `GET` detail → `404`
  and a second `DELETE` → `404`.
- `POST …/restore` clears `deleted_at`. **Same** id, `sequence_no` and `asset_code`; no
  new number consumed, no duplicate created (the trashed row already owns its slot in
  `uq_assets_number`, which counts trashed rows). Restoring a live asset is a `200`
  no-op.

### 12.5 Written-off lifecycle

- `POST …/write-off` — body `written_off_on` (required, `date`, not future) +
  optional `written_off_note`. Server sets `is_written_off = true`. `condition` is not
  touched. `409` if the asset is already written-off (the existing date is **not**
  silently overwritten).
- `POST …/unwrite-off` — no body. Clears `is_written_off`, `written_off_on`,
  `written_off_note`. `409` if the asset is not written-off.
- The client can never send `is_written_off` directly to either endpoint (`prohibited`).

### 12.6 Mutation log

`mutation_logs` is **append-only** — no update / delete endpoint, ever. Tahap 5.4
records exactly one legacy type: **`pindah_ruangan`** (location and/or room change),
still written to the `type` column exactly as before for backward compatibility. Each
row captures `asset_id`, `type`, `mutation_date` (today), `from_location_code` /
`to_location_code`, `from_room_id` / `to_room_id`, `from_room_label` / `to_room_label`
(name snapshots — the master room can later be renamed or disabled),
`condition_before` / `condition_after`, an optional `mutation_note`, `performed_by`,
`created_at`. It is written inside the asset-update transaction: if the log write fails,
the asset change rolls back too.

**Tahap 5.8.8 — generic audit foundation.** Every asset-changing operation (create,
edit, write-off, unwrite-off, soft delete, restore, batch edit, batch delete) now
writes one `mutation_logs` row through the same `AssetMutationRecorder`, classified by
a new `event_type` column: `CREATE`, `EDIT`, `MOVE_ROOM`, `WRITE_OFF`, `UNWRITE_OFF`,
`SOFT_DELETE`, `RESTORE`, `BATCH_EDIT`, `BATCH_DELETE` (`App\Enums\MutationEventType`).
`type` (the legacy column) is left `NULL` for every event that isn't a room move — it
is never repurposed or force-mapped onto a non-matching legacy value. Every row also
gets `before_snapshot` / `after_snapshot` (full relevant-asset-state JSON — see
`AssetMutationRecorder::snapshot()` for the exact field list) and an optional
`batch_operation_id` (a UUID shared by every per-asset event one batch HTTP request
produces; `NULL` for individual operations). A batch-driven room move is classified
`BATCH_EDIT`, not `MOVE_ROOM` — but still sets the legacy `type='pindah_ruangan'`, so
the original relocation ledger and its `mutation_type` filter stay correct. No mutation
row is ever written for a request that changes nothing (snapshot-equality, not raw
Eloquent dirty-tracking, decides this — see the recorder's doc block).

### 12.7 Transaction & concurrency

- Every write is wrapped in `DB::transaction`.
- Create: the owning `subcategories` row is locked `FOR UPDATE`
  (`AssetNumberGenerator::lockScope`) before the sequence is read, so concurrent
  creates in the same `(category, subcategory)` are **serialised** — the second waits
  for the first to commit, then sees its row.
- `uq_assets_number` (`location_code, category_code, subcategory_code, sequence_no,
  asset_year`) is the **final defence**. On a duplicate-key / deadlock / lock-wait race
  (`1062` / `1213` / `1205`) `AssetWriteService` retries the whole create up to 4 times,
  re-running the generator against the now-committed state.
- No new sequence table is introduced — the existing rows are the source of truth.
- True parallel DB testing is impractical in the PHPUnit harness; the retry path is
  covered by injecting a generator that returns a colliding value, and the unique
  index is asserted to hold.

### 12.8 Status codes

`201` create · `200` update / write-off / unwrite-off / restore · `204` delete ·
`404` unknown or soft-deleted target · `409` write-off/unwrite-off state conflict ·
`422` validation · `403` role denial · `401` unauthenticated.

---

## 13. Mutation History API (Tahap 5.5)

Read-only history of an asset's mutations. Behind `auth:sanctum` + `auth.active` +
**`can:viewer`** — every active user reads it (`viewer` / `operator` / `admin` → `200`;
inactive → `403`; unauthenticated → `401`).

| Method | Path | Name |
|---|---|---|
| `GET` | `/api/assets/{asset}/mutations` | `api.assets.mutations.index` |

- **Read-only.** There is deliberately **no** `POST` / `PUT` / `PATCH` / `DELETE` here,
  and mutation rows are never soft-deleted. `mutation_logs` is append-only and is
  written **only** by `App\Services\Asset\AssetMutationRecorder` (Tahap 5.4). `POST`
  to the path → `405`; a `.../mutations/{id}` path → `404`.
- **Asset-scoped.** Results are `where asset_id = {asset.id}` (`$asset->mutationLogs()`)
  — another asset's rows are never returned.
- **Soft-deleted asset → `404`.** Default route binding, no `withTrashed()` — history is
  not a way around lifecycle visibility.
- **Empty history → `200`** with `{ "data": [], "meta": { …, "total": 0 } }` — never `404`.

### 13.1 Historical snapshots — do not reconstruct from current master data

> Mutation history reads historical snapshots and must not be reconstructed from
> current master data.

- `from.room_label` / `to.room_label` are the room **names as they were** when the move
  happened. They are emitted verbatim from `mutation_logs.*_room_label` and are **never**
  re-resolved through the `rooms` relation — a room can be renamed or disabled afterwards
  and the history must still show the original name.
- `condition_before` / `condition_after` are the event's own snapshots, not the asset's
  current condition.
- `performed_by` is the **only** live reference (current identity). It exposes `id` and
  `name` only — never `email` / `role` / `is_active`. A performer who has since been
  deactivated is still shown; history is not filtered by `users.is_active`.

### 13.2 Pagination

Same convention as §7: `{ "data": [], "meta": { current_page, last_page, per_page,
total } }`, no `links`. `per_page` default `20`, validated `integer|min:1|max:100`
(> 100 → `422`, never clamped). `page` validated `integer|min:1`.

### 13.3 Sorting

`?sort=<col>&direction=<asc|desc>`. `sort` whitelist (server-side; anything else →
`422`): `mutation_date`, `created_at`, `id`. Default **`sort=mutation_date`,
`direction=desc`**, with **`id desc` always appended** as a deterministic tie-breaker
(`mutation_date` is the business date; `created_at` is the technical timestamp — they
are not mixed).

### 13.4 Filters (all optional, AND)

| Param | Rule | Effect |
|---|---|---|
| `mutation_type` | `in:pindah_ruangan` (the only legacy type the system records) | `where type` |
| `event_type` | one of `App\Enums\MutationEventType`'s cases (Tahap 5.8.8) | `where event_type` |
| `performed_by` | `integer`, `exists:users,id` | `where performed_by` |
| `date_from` | `date_format:Y-m-d` | `where mutation_date >=` |
| `date_to` | `date_format:Y-m-d`, and `>= date_from` when both given | `where mutation_date <=` |

Date bounds are **inclusive** (`date_from <= mutation_date <= date_to`). A blank query
param (`?date_from=`) is treated as absent.

### 13.5 Search — `?q=<term>`

Max 100 chars, case-insensitive partial match via bound `LIKE` with `%` / `_` / `\`
escaped through the shared `ApiController::escapeLike()`. Columns:
`from_room_label`, `to_room_label`, `from_location_code`, `to_location_code`,
`mutation_note` (the `notes` column). Not searched: `performed_by` (no join).

### 13.6 Resource shape (`MutationLogResource`)

```json
{
  "id": 12,
  "mutation_type": "pindah_ruangan",
  "mutation_date": "2026-09-08",
  "from": { "location_code": "01", "room_id": 2, "room_label": "Ruangan Personalia & SARPRAS" },
  "to":   { "location_code": "01", "room_id": 5, "room_label": "Ruangan Keuangan" },
  "condition_before": "baik",
  "condition_after": "baik",
  "mutation_note": null,
  "performed_by": { "id": 1, "name": "..." },
  "created_at": "2026-09-08T09:14:00+00:00",
  "event_type": "MOVE_ROOM",
  "batch_operation_id": null,
  "before_snapshot": { "...": "full relevant-asset-state snapshot, see AssetMutationRecorder::snapshot()" },
  "after_snapshot": { "...": "same shape as before_snapshot" }
}
```

The last four fields were added in Tahap 5.8.8 — purely additive on top of the Tahap
5.5 shape above. `mutation_type` / `from` / `to` / `condition_before` /
`condition_after` keep meaning exactly what they always did (populated only for a
room move); `event_type` / `before_snapshot` / `after_snapshot` are populated for
every event, room moves included.

`performed_by` is `null` when the row has no performer. Not exposed: `asset_id`, raw
`type` / `notes` keys, `updated_at`, `deleted_at`, and any performer field beyond
`id` + `name`.

### 13.7 N+1

The performer is eager-loaded (`->with('createdBy')`) — one query for the whole page.
Room **labels are snapshot columns**, so no `rooms` query is issued. Query count does
not grow with the number of rows on the page.

### 13.8 Not on the asset detail endpoint

`GET /api/assets/{asset}` (§8) is **not** changed — it does not embed mutation history.
History is paginated and lives on its own endpoint to keep the detail payload light.

---

## 14. Dashboard API (Tahap 5.6)

One read-only aggregation endpoint for the (future) React dashboard. Behind
`auth:sanctum` + `auth.active` + **`can:viewer`** — every active user (`viewer` /
`operator` / `admin`) → `200`; inactive → `403`; unauthenticated → `401`.

| Method | Path | Name |
|---|---|---|
| `GET` | `/api/dashboard` | `api.dashboard` |

`GET` only — `POST` / `PUT` / `PATCH` / `DELETE` → `405`. **No query params / filters**
in this stage (it is the global dashboard). All counting is done with database
aggregation (`COUNT` / `GROUP BY` / conditional `SUM` / `JOIN`) in
`App\Services\Dashboard\DashboardService` — never `Asset::all()` then group in PHP.

### 14.1 Asset scope

Every count is over **active assets** = the default `assets` query (`deleted_at IS
NULL`, no `withTrashed()`).

- **Written-off assets are still counted** — `is_written_off = true` is a business
  status, not a deletion. They appear in `total_assets`, `by_condition`, `by_location`,
  `by_category`, `by_room`, and are also totalled separately in `summary.written_off`.
- **Soft-deleted assets are excluded everywhere.**

### 14.2 Response

```json
{
  "data": {
    "summary": {
      "total_assets": 394,
      "written_off": 26,
      "by_condition": { "baik": 378, "kurang_baik": 0, "rusak_berat": 11, "unknown": 5 }
    },
    "by_location": [ { "code": "01", "name": "YAYASAN", "asset_count": 394 } ],
    "by_category": [ { "code": "02", "name": "MEUBELAIR", "asset_count": 186 } ],
    "by_room":     [ { "id": 5, "name": "Ruangan Keuangan", "location_code": "01", "location_name": "YAYASAN", "asset_count": 53 } ],
    "recent_mutations": []
  }
}
```

| Field | Meaning |
|---|---|
| `summary.total_assets` | count of all active assets |
| `summary.written_off` | active assets with `is_written_off = true` |
| `summary.by_condition` | active assets grouped by `condition`; **`unknown` = `condition IS NULL`** (NULL is never coerced to another value); the four keys are always present |
| `by_location` | **every active location**, `is_active = true`, incl. those with `asset_count = 0`; ordered by `code` asc; inactive locations are omitted |
| `by_category` | **every active category**, same rules; ordered by `code` asc |
| `by_room` | only rooms that currently hold **≥ 1 active asset** (rooms with `0` are omitted); grouping is location-aware, so same-named rooms in different locations stay separate rows; ordered by `location_code`, then room `name`, then `id`. Includes the room's `is_active` state implicitly — a disabled room that still holds active assets is shown, because those assets are real |
| `recent_mutations` | the **10** most recent `mutation_logs` across all assets, ordered `mutation_date` desc → `created_at` desc → `id` desc; each row is a `MutationLogResource` (§13.6) — historical room-label snapshots, performer as `{id,name}` only; `[]` when there is no history (still `200`) |

`recent_mutations` reuses `MutationLogResource`; it is **not** a new browsable
mutation-history endpoint. There is no by-subcategory breakdown (§11 composite-key
rule untouched). `asset_code` is not rebuilt anywhere.

---

## 15. Test database convention

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

## 16. Not yet implemented — planned for future stages

The following are **conventions only** — no code exists yet:

| Stage | Adds |
|---|---|
| 5.7 | non-relocation mutation types (`perbaikan`, `perubahan_kondisi`, …); room / room-alias management; import/report HTTP layer; filtered/parameterised reporting; full API feature-test matrix |

Do not document any of these as available endpoints until their stage lands.
