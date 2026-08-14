# SPEC — Integration API v1 (PLAN Phase 10, tasks 10.1–10.6)

> Written before the code. Governing decision: **ADR-010** (per-account hashed API tokens,
> env-gated, no new dependencies), which puts into practice the posture **ADR-006** committed
> to in planning: siblings and external consumers talk to Nexo Events over a public HTTP API
> with an explicit contract — never a shared database.

## Purpose

The first real external consumer exists: La Herrería's website (Next.js) needs to create
events in its own Nexo Events account programmatically — title, description, date — and get
back the public URL (`/e/{slug}`) to link from its landing page. Today there is no API of
any kind: no `routes/api.php`, no tokens, everything is session + CSRF. This SPEC adds the
smallest API that serves that consumer **without changing anything** for standalone
installs: routes are always registered but return 404 until `NEXO_API_ENABLED=true`.

## Scope

**In:** an `api_tokens` table + Bearer authentication middleware; `/api/v1` routes for the
event lifecycle (create, read, update, publish, close, cancel) plus a `GET /me` handshake;
idempotent creation via `events.external_ref`; a minimal organizer panel
(`/app/integraciones`) to issue and revoke tokens; named env-tunable rate limits;
guardian-test updates.

**Out (explicitly):** attendee data over the API (no ticket lists, no emails — a leaked
token must never expose PII); event images (SCOPE amendment 2026-07-26 stands); webhooks
or nexo→consumer callbacks; OAuth/client-credentials via Nexo ID (revisit if a third-party
ecosystem ever materializes); Sanctum (ADR-010); admin/instance-operator API; write access
to anything but the token owner's events.

## Design decisions

### Tokens are the house pattern, not a framework feature

A token is `Str::random(40)`, shown once at creation; only its sha256 lands in
`api_tokens.token_hash` (unique) — exactly how `Ticket.token_hash` already works (ADR-004,
ADR-008). `abilities` (json) carries scopes `events:read` / `events:write`; `revoked_at`
kills a token immediately; `last_used_at` gives the organizer visibility. No Sanctum:
see ADR-010.

### The gate lives in middleware, never in route registration

`NEXO_API_ENABLED=false` (default) must keep the standalone story intact **and** survive
`route:cache`: conditionally registered routes are a cached-routes trap. Routes always
exist; `AuthenticateApiToken` returns 404 before touching auth when the flag is off.

### One source of validation and one of business rules

`Api\EventApiController` reuses `EventRequest` for validation and the same transition
rules the web panel enforces — including the ADR-007 publish gate (verified email) and the
queued cancellation mails. Event creation is extracted from `EventController::store`
(4 lines) into `app/Services/EventCreator`, and the publish/close/cancel transitions into
`app/Services/EventLifecycle`, both shared by the two entry points; web behavior is
byte-identical and existing tests must stay green. Sharing the transitions is what makes
"same rules as the panel" a fact rather than a copy: the cancellation mail loop exists
once, so it cannot drift on one side only.

`EventRequest` stays the single rule set. The one adaptation it needs is `PATCH`, which is
a partial update by contract: on `PATCH` the `required` rules become `sometimes`, and every
other rule (types, lengths, bounds) is untouched. The web form uses `POST`/`PUT`, so its
validation is unchanged.

### Times are normalized to the instance clock on the way in

`starts_at` arrives as ISO-8601 **with an explicit offset** (the consumer's own timezone is
not this instance's business) and is converted to `APP_TIMEZONE` before it is persisted.
Skipping the conversion is not a no-op: Eloquent stores the wall time of whatever offset it
was handed, so `2026-09-02T00:00:00Z` would land as `00:00` local — three hours off in
Buenos Aires, and silently, because the event page would show exactly what was stored.
Every timestamp the API returns is ISO-8601 in the instance timezone.

### Middleware order is part of the contract

`AuthenticateApiToken` runs **before** the throttle middleware — it is added to the
framework's middleware priority list ahead of `ThrottleRequests`, because Laravel's
priority sorting otherwise hoists `throttle` to the front of the route's middleware. Two
things depend on that order: a disabled API answers 404 rather than burning limiter quota,
and the limiter can key by token instead of by IP.

### Idempotent creation, because the consumer will retry

`events.external_ref` (nullable `string(190)`) + `UNIQUE(organizer_id, external_ref)`. A
`POST` with an `external_ref` the organizer already used returns **200 with the existing
event** instead of creating a duplicate — a retry after a timeout is safe by construction.
Creation always yields a **draft**; publishing is a separate call, so the consumer can
persist the link before the event goes public (no publish-but-link-lost window).
`external_ref` is deliberately outside `Event`'s `#[Fillable]`: it is the consumer's own
key, set once at creation by the service, never mass-assignable and never editable through
`PATCH` (repointing it would silently break the other side's idempotency).

## API contract

Base `/api/v1` · `Authorization: Bearer <token>` · JSON errors `{message, errors?}` ·
401 invalid/revoked token · 403 missing scope · 404 API disabled, or resource not owned
(no existence leak) · 422 validation · 429 throttled.

| Endpoint | Scope | Behavior |
|---|---|---|
| `GET /me` | any | `{email, email_verified, scopes}` — link-account handshake |
| `POST /events` | write | `{external_ref?, title, description?, starts_at (ISO-8601 with offset), venue?, capacity?}` → 201 **event payload**; existing `external_ref` → 200 with the existing event |
| `GET /events/{id}` | read | the **event payload** |
| `PATCH /events/{id}` | write | partial update; slug never regenerates (published links never break) → event payload |
| `POST /events/{id}/publish` | write | web-panel semantics; 422 `{message, code: "email_unverified"}`; idempotent on published |
| `POST /events/{id}/close` · `/cancel` | write | web-panel semantics (cancel queues attendee mails) |

**Event payload** — one shape for every event-returning endpoint, frozen by AC-API-6c:
`{id, slug, title, description, status, starts_at, venue, capacity, issued_tickets_count,
is_sold_out, public_url}`. One presenter, so "no attendee PII" is a property of one
function instead of a habit repeated in six places. Creation returns the same shape as a
read: a superset of the `{id, slug, status, public_url}` the first consumer needs, which
costs nothing and saves it a round trip.

## Acceptance criteria

### Gate & auth

- **AC-API-1** — With `NEXO_API_ENABLED=false` (the default), every `/api/v1/*` request
  returns 404. Routes stay registered (a `route:cache`d app behaves identically). The full
  suite passes with the flag off: standalone intact.
- **AC-API-2** — A request with no token, an unknown token, or a revoked token gets 401
  and writes nothing; `last_used_at` is untouched.
- **AC-API-3** — Creating a token stores only the sha256 hash; the raw token appears once
  in the creation response/view and nowhere else (not in logs, not in the DB).
- **AC-API-4** — A token with only `events:read` gets 403 on every mutating endpoint;
  a token with only `events:write` gets 403 on `GET /events/{id}`. `GET /me` works with
  any valid token.

### Events

- **AC-API-5** — `POST /events` creates a **draft** owned by the token's user, applying
  the same validation as the web form (same rules, JSON-shaped errors), and returns
  `id`, `slug` and `public_url` (the `/e/{slug}` URL).
- **AC-API-5b** — `starts_at` sent with an explicit offset is persisted converted to the
  instance timezone: `2026-09-02T00:00:00Z` on an instance in `America/Argentina/Buenos_Aires`
  is stored as `2026-09-01 21:00`, the same instant the web form would have stored, and is
  returned as ISO-8601 in the instance offset. A value without an offset is read in the
  instance timezone (the web form's behavior, unchanged).
- **AC-API-6** — Two `POST /events` with the same `external_ref` for the same organizer
  yield one event: the second returns 200 with the first one. A DB-level unique constraint
  on `(organizer_id, external_ref)` backs the check. Different organizers may reuse the
  same `external_ref`.
- **AC-API-6b** — `external_ref` is optional: a `POST /events` without it creates a normal
  draft (201) with no idempotency check, and repeating that request creates a second
  event. Two events with `external_ref` NULL never collide with the unique constraint.
- **AC-API-6c** — `GET /events/{id}` returns exactly these fields: `id`, `slug`, `title`,
  `description`, `status`, `starts_at`, `venue`, `capacity`, `issued_tickets_count`,
  `is_sold_out`, `public_url`. The test enumerates the key set, so adding or dropping a
  field is a deliberate contract change, not a silent one.
- **AC-API-7** — `PATCH /events/{id}` updates only the provided fields and never
  regenerates the slug, even when `title` changes. A field absent from the body keeps its
  stored value; `external_ref` is not updatable.
- **AC-API-8** — Accessing or mutating another organizer's event returns 404 (not 403):
  the API does not reveal which ids exist.
- **AC-API-9** — `POST .../publish` by an unverified account returns 422 with an
  actionable message and `code: "email_unverified"` — a machine-readable code, because the
  consumer has to tell this apart from a validation error to show the right instruction;
  by a verified account it publishes and is idempotent on an already-published event.
  Transitions match the web panel exactly (same service).
- **AC-API-10** — `POST .../cancel` cancels and queues the same attendee mails as the web
  flow; `.../close` closes registrations. Responses reflect the new status.
- **AC-API-11** — No API response ever contains attendee PII (names, emails, ticket
  tokens) — asserted by test on every event-returning endpoint.

### Extraction, limits, guardians

- **AC-API-12** — Web event creation goes through `EventCreator` and web publish/close/
  cancel through `EventLifecycle`; the existing web tests pass unchanged.
- **AC-API-13** — Named env-tunable rate limits keyed by token: reads
  (`NEXO_API_RATE_READ`, default 60/min), writes (`NEXO_API_RATE_WRITE`, default 20/min);
  a deliberate-violation test gets 429. Two tokens of the same account do not share a
  bucket, and the gate still wins: with the API disabled the answer is 404, never 429.
- **AC-API-14** — `api/*` is excluded from silent-SSO redirects (guardian
  `NexoSsoSilentExclusionsTest` extended) and the new migrations pass
  `DatabaseStandardTest`.

### Panel

- **AC-API-15** — An organizer can create (name + scopes), list (name, scopes,
  `last_used_at`, created date) and revoke tokens at `/app/integraciones`; revocation
  takes effect on the next request. The page is translated (es/en/pt) and passes the
  i18n `--check`.
- **AC-API-15b** — The panel rides the same flag as the API: with `NEXO_API_ENABLED=false`
  `/app/integraciones` returns 404 and the dashboard shows no link to it. A self-hoster who
  never turns the API on sees no trace of it — the standalone guarantee covers the UI, not
  only the endpoints. (Consequence to keep in mind operationally: the first token can only
  be issued once the flag is on.)
