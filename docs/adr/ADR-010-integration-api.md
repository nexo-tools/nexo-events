# ADR-010 — Integration API v1: per-account hashed tokens, env-gated, no new dependencies

- **Date:** 2026-08-12
- **Status:** Accepted

## Context

ADR-006 fixed the integration posture — tools talk over public HTTP APIs with explicit
contracts, never shared databases — but at planning time there was nothing to integrate
against, so no API was built. That changed: the first real external consumer exists.
La Herrería (a real venue, a Next.js site outside the Nexo org) needs to create events in
its own Nexo Events account from its admin panel and link the public `/e/{slug}` page from
its landing. The owner (2026-08-12) approved building this now, as a capability of the
product rather than a bespoke bridge: the site is the *first* consumer, not the only
imaginable one.

Constraints that shape the design: the tool is LIVE and standalone-first (self-hosters
must see zero behavior change); `composer.json` is deliberately minimal (framework +
php-jwt + qr — no auth packages); the house already has a proven opaque-token pattern
(`Ticket.token_hash`, sha256, raw value shown once — ADR-004/ADR-008); everything
multi-instance is env-configured and off by default (SSO, beacon); routes must survive
`route:cache`.

## Decision

1. **Own token table, not Sanctum.** `api_tokens` (user FK, `name`, `token_hash` sha256
   unique, `abilities` json, `last_used_at`, `revoked_at`). Raw token `Str::random(40)`,
   shown once. Sanctum would be the first auth dependency in the repo to deliver a subset
   of what ~60 lines of house-pattern code deliver (we need exactly: hash lookup, scopes,
   revocation, last-used) — and DATABASE-STANDARD compliance is required either way.
2. **Scopes from day one**: `events:read`, `events:write`. Cheap now, impossible to
   retrofit politely later.
3. **Env-gated, off by default.** `NEXO_API_ENABLED=false` keeps standalone behavior
   byte-identical. The gate lives in the `AuthenticateApiToken` middleware (404 when off);
   routes are **always registered** — conditional registration breaks under `route:cache`.
4. **`/api/v1` versioned surface, event lifecycle only.** Create (always draft), read,
   partial update, publish/close/cancel with the exact web-panel semantics (ADR-007
   verified-email publish gate included), plus `GET /me` as the account-linking handshake.
   **No attendee PII ever crosses the API**: the blast radius of a leaked token is the
   owner's event metadata, never registrants.

   > **Amended 2026-10-28 by [ADR-011](ADR-011-event-collaborators.md).** Still true of every
   > response: no attendee data crosses the API. But a token holding the separate, opt-in
   > scope `collaborators:manage` can invite a collaborator who — after creating and
   > verifying an account with that email and accepting — sees the **names and status** of
   > the registrants of the events that integration created (never emails or tickets, never
   > other events), until revoked or until 7 days after the event. A token without that
   > scope keeps exactly the radius above. Mitigations and the full statement are in ADR-011.
5. **Idempotent creation** via nullable `events.external_ref` +
   `UNIQUE(organizer_id, external_ref)`: consumer retries are safe by construction.
6. **Shared core, not parallel code paths**: creation logic moves to
   `app/Services/EventCreator` and the publish/close/cancel transitions to
   `app/Services/EventLifecycle` (the EventRegistrar pattern), both consumed by the web
   controller and the API controller; validation reuses `EventRequest`. A second copy of
   the cancellation mail loop would be the exact drift this ADR exists to avoid — the two
   surfaces must be unable to disagree, not merely intended to agree.
7. **Instance clock at the boundary**: `starts_at` is accepted as ISO-8601 with an explicit
   offset and converted to `APP_TIMEZONE` before persisting. The database keeps storing what
   the web form has always stored; the offset is the consumer's way of saying which instant
   it means, not a second timezone the app has to carry (DATABASE-STANDARD §3, and the
   documented per-instance-timezone exception for this tool).

## Alternatives considered

- **Laravel Sanctum** — rejected: first auth dependency, larger surface (SPA cookies,
  token expiry policies we don't use), and the house token pattern is already proven,
  audited and standard-compliant.
- **A single token column on `users`** — rejected: irrevocable without breaking the only
  consumer, no scopes, no second client ever, invisible usage.
- **OAuth client-credentials via Nexo ID** — rejected for v1: nexoid is an OIDC identity
  provider for humans, not an API authorization server; coupling the first API consumer
  to a second live service contradicts "never blocking" (ADR-006) and buys nothing at
  one consumer. Revisit if third-party integrations multiply.
- **Bespoke endpoint keyed by a shared env secret** — rejected: not multi-account, not
  revocable per consumer, and turns the first integration into a hack the second consumer
  has to undo.
- **Webhooks nexo→consumer** — deferred: the consumer polls one counter on demand; no
  real use case yet (backlog with an explicit trigger).

## Consequences

- Phase 10 gains concrete tasks (10.1–10.6) and its first shipped integration item; the
  SPEC is `docs/specs/SPEC-integration-api.md`.
- Two new migrations (`api_tokens`, `events.external_ref`) — both covered by
  `DatabaseStandardTest`.
- New guardian obligations: rate limits on `api/*` writes, `api/*` excluded from silent
  SSO, standalone suite green with the flag off.
- A small organizer-facing surface (`/app/integraciones`) enters the panel, translated
  es/en/pt — and rides the same flag, so with the API off the panel is not merely empty but
  absent (404, no dashboard link). The price is operational and accepted: the flag has to be
  on before the first token can be issued.
- `AuthenticateApiToken` joins the framework's middleware priority list, ahead of
  `ThrottleRequests`. Without that, Laravel's own priority sorting reorders the route's
  middleware and the throttle runs first — which would key the limiter by IP instead of by
  token and let a disabled API answer 429 instead of 404. An invisible dependency written
  down here because a future middleware edit can undo it silently; a test asserts the
  effective order.
- The pattern (token table + middleware + versioned routes) is deliberately copyable to
  siblings (nexoagenda availability is the obvious next candidate); promotion to
  `templates/` + `nexo-doctor` waits for the **second** consumer — one consumer is a
  feature, two are a standard.
