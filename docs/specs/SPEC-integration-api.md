# SPEC — Integration API v1 (PLAN Phase 10, tasks 10.1–10.6) · v1.1 (tasks 10.7–10.11) · v1.2 (task 10.14) · v1.3 (task 10.15)

> Written before the code. Governing decision: **ADR-010** (per-account hashed API tokens,
> env-gated, no new dependencies), which puts into practice the posture **ADR-006** committed
> to in planning: siblings and external consumers talk to Nexo Events over a public HTTP API
> with an explicit contract — never a shared database.
>
> **v1.1 (2026-10-28)** adds, without breaking any v1 call: three fields to the event
> payload (`access_info`, `payment_info`, `checked_in_count` — SPEC-ticket-info, ADR-012) and
> a separate, opt-in scope `collaborators:manage` with three endpoints to invite, list and
> revoke event collaborators (SPEC-event-collaborators, ADR-011). The v1.1 criteria landed
> with tasks 10.8 (payload) and 10.11 (endpoints), each in bold together with its tests —
> bold is what `ApiSpecTraceabilityTest` reads.
>
> **v1.2 (2026-11-21, task 10.14)** adds one optional field, again without breaking any
> call: `access_address` (≤255), the venue's exact address, private like `access_info`
> (SPEC-ticket-info AC-TICKET-14..18). Accepted on `POST /events` and `PATCH /events/{id}`,
> returned next to `access_info`. A body without it behaves exactly as in v1.1
> (AC-API-24).
>
> **v1.3 (2026-11-22, task 10.15)** adds one more optional field, again without breaking
> any call: `info_url` (≤2048, absolute `https` only), the event's public "More
> information" link, shown on `/e/{slug}` as a button with its domain (SPEC-ticket-info
> AC-TICKET-19..23). Accepted on `POST /events` and `PATCH /events/{id}`, returned after
> `payment_info`. A body without it behaves exactly as in v1.2 (AC-API-25..27). Scopes are
> unchanged: it is an event field, so `events:write` sets it and `events:read` sees it.

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

**In (v1.1):** `access_info` / `payment_info` accepted on create and update and returned in
the payload, plus `checked_in_count`; scope `collaborators:manage` with
`GET|POST /events/{id}/collaborators` and `DELETE /events/{id}/collaborators/{cid}`, limited
to events the integration created (`external_ref` set); a mail to the owner for every
invitation made through the API.

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

### v1.1 — collaborators: a separate scope, a narrow reach, and a loud trail

Inviting a collaborator grants a third party sight of attendee **names** (never emails) for
one event (ADR-011). That is not something `events:write` may do as a side effect, so it is
its own scope, `collaborators:manage`, unticked by default in the panel and offered with a
warning. Its reach is narrowed to events that carry an `external_ref` — the ones an
integration created — so a token can never open up an event the organizer made by hand.
Every invitation made through a token mails the owner (token name, invited email, event)
and records `invited_via` = the token's name, visible in the collaborator list.

The raw invitation link is returned once, in the `POST` response (`invite_url`), so the
consumer can offer it through another channel when email is slow or filtered. It is never
returned by a read: the `GET` list carries no link and no hash. Sharing it widens nothing,
because acceptance requires a signed-in, verified account with the invited email
(SPEC-event-collaborators).

**Blast radius, written down** (amends ADR-010 §4): a leaked token holding
`collaborators:manage` can invite an address its holder controls and, after that address
creates and verifies an account and accepts, read the **names and status** of the
registrants of the events created through that integration — never emails, never ticket
tokens, never other events — until the owner revokes the collaborator or the token, or
the access window closes 7 days after the event. Mitigations: separate opt-in scope;
`external_ref` scoping; a mail to the owner per invitation; `invited_via` visible; same
verified email to accept; single-use links; at most 10 active collaborators; immediate
revocation.

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
| `GET /events/{id}/collaborators` *(v1.1)* | collaborators:manage | `[{id, email, status, invited_at, expires_at, accepted_at, invited_via}]`, `status` ∈ `pending · accepted · expired · revoked`; no link, no hash; 404 if the event has no `external_ref` |
| `POST /events/{id}/collaborators` *(v1.1)* | collaborators:manage | `{email, locale?}` → 201 `{…, invite_url}` + two mails (invitee, owner). Pending or expired email → 200, rotates the link and re-sends (with `invite_url`); accepted → 200, no mail, no `invite_url`; revoked → 201, fresh invitation; more than 10 active → 422 `{code: "too_many_collaborators"}`; unverified owner → 422 `{code: "email_unverified"}` |
| `DELETE /events/{id}/collaborators/{cid}` *(v1.1)* | collaborators:manage | sets `revoked_at`; 204, idempotent; access ends on the collaborator's next request |

In v1.1 `POST /events` and `PATCH /events/{id}` also accept `access_info` (≤1000) and
`payment_info` (≤300) — same `EventRequest` rules as the web form. In v1.2 they also accept
`access_address` (≤255), same rule as the web form; leaving it out of a `PATCH` keeps it,
`null` clears it. In v1.3 they also accept `info_url` (≤2048, `url:https`: absolute `https`
only — `http`, `javascript:`, `data:`, `mailto:` and relative values are a 422), same rule
as the web form, same `PATCH` semantics; trimmed, and `""` is `null`. A consumer that owns the
content but not the tickets (the first one) sends `capacity` only on creation and never
sends `status`: after creation, capacity and registration state belong to whoever manages
the tickets.

**Event payload** — one shape for every event-returning endpoint, frozen by AC-API-6c:
`{id, slug, title, description, status, starts_at, venue, capacity, issued_tickets_count,
is_sold_out, public_url}` in v1, plus `access_info`, `payment_info` and `checked_in_count`
(tickets in `checked_in`) in v1.1 — fourteen keys — plus `access_address` right after
`access_info` in v1.2: fifteen keys — plus `info_url` right after `payment_info` in v1.3:
sixteen keys. One presenter, so "no attendee PII" is a property of one
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
  field is a deliberate contract change, not a silent one. *v1.1 rewrite (landed with task
  10.8, together with its test): fourteen keys — the eleven above, then `access_info`,
  `payment_info`, `checked_in_count`; `checked_in_count` counts only `checked_in` tickets.
  v1.2 rewrite (task 10.14, with its test): fifteen keys — `access_address` between
  `access_info` and `payment_info`. v1.3 rewrite (task 10.15, with its test): sixteen keys
  — `info_url` between `payment_info` and `checked_in_count`.*
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
  tokens) — asserted by test on every event-returning endpoint. *Extended in 10.11 to all
  ten endpoints, the three collaborator ones included (landed). Extended in 10.13: the
  optional attendee phone (SPEC-ticket-info AC-TICKET-12) is asserted absent too.*

### Extraction, limits, guardians

- **AC-API-12** — Web event creation goes through `EventCreator` and web publish/close/
  cancel through `EventLifecycle`; the existing web tests pass unchanged.
- **AC-API-13** — Named env-tunable rate limits keyed by token: reads
  (`NEXO_API_RATE_READ`, default 60/min), writes (`NEXO_API_RATE_WRITE`, default 20/min);
  a deliberate-violation test gets 429. Two tokens of the same account do not share a
  bucket, and the gate still wins: with the API disabled the answer is 404, never 429.
  *Extended in 10.11: `POST …/collaborators` above the write limit gets 429.*
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

### Collaborators (v1.1 — task 10.11)

- **AC-API-16** — A token without `collaborators:manage` gets 403 on the three
  collaborator endpoints; with it, `GET` lists `{id, email, status, invited_at, expires_at,
  accepted_at, invited_via}` and nothing about attendees.
- **AC-API-17** — `POST` with a new email answers 201 with `invite_url`, queues
  `CollaboratorInvited` to the invitee and `CollaboratorInvitedByIntegration` to the owner
  naming the token; repeated on a pending email it answers 200 and rotates the hash; on an
  accepted one, 200 and no mail.
- **AC-API-18** — `DELETE` sets `revoked_at` and answers 204 twice in a row; the
  collaborator gets 403 on their next request.
- **AC-API-19** — The three endpoints answer 404 for another organizer's event and for
  the token owner's own event when it has no `external_ref`.
- **AC-API-20** — The eleventh active invitation answers 422 `too_many_collaborators`;
  an unverified owner gets 422 `email_unverified`.
- **AC-API-21** — The panel refuses invented scopes (AC-API-15 widened) and shows the
  warning next to the `collaborators:manage` box.
- **AC-API-22** — With `NEXO_API_ENABLED=false` the three endpoints answer 404, and
  `route:cache` contains them.
- **AC-API-23** — The `GET` list never contains `invite_url` nor any hash.

### v1.2 — private address (task 10.14)

- **AC-API-24** — `POST /events` with `access_address` stores it and returns it next to
  `access_info`; without it the event is created as in v1.1 with `access_address: null`.
  `PATCH` sets it, keeps it when the body does not mention it, clears it with `null`; more
  than 255 characters is a 422 on both, as on the web form.

### v1.3 — "More information" link (task 10.15)

- **AC-API-25** — `POST /events` with `info_url` stores it trimmed and returns it right
  after `payment_info` (sixteen keys); without it the event is created as in v1.2 with
  `info_url: null`, and `""` is stored as `null`. `GET` returns it.
- **AC-API-26** — `PATCH` sets `info_url`, keeps it when the body does not mention it and
  clears it with `null`; `http`, `javascript:`, `data:`, `mailto:`, relative values and more
  than 2048 characters are a 422 on `POST` and `PATCH` alike, as on the web form, and write
  nothing.
- **AC-API-27** — With `NEXO_API_ENABLED=false` a `POST` or `PATCH` carrying `info_url`
  answers 404 and writes nothing, while the web form and `/e/{slug}` keep saving and showing
  the link exactly as with the API on.
