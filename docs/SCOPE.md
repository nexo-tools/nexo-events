# SCOPE — Nexo Events

<!-- Living record: every new idea lands here (docs: commit) BEFORE being implemented. -->

## Value proposition

Free event registration and QR ticketing, end to end: anyone creates an event, people register and receive a QR ticket by email, and the organizer validates entry at the door by scanning with their phone. Part of the Nexo ecosystem (Nexo Links, Nexo Agenda, Nexo Short, Nexo ID): open source, multi-instance, self-hostable, privacy-first (cookieless, zero third-party requests at runtime).

Source input: a pre-planning evaluation brief (2026-07-20), kept out of this repository — it is Spanish, pre-decision working material. Everything from it that still governs the product was carried into this document and the ADRs, which supersede it.

## MVP

### In

- **Organizer accounts** — mandatory to create events (anti-abuse, closed decision). Standalone local auth; email verified before an event can be published (ADR-003, ADR-007).
- **Event management**: create/edit with title, description, date/time, venue, optional capacity; status lifecycle including cancel and admin kill-switch; manual registration close. *(Event image deferred post-v1 — see Amendments.)*
- **Public event page** with shareable URL (slug), SEO base and i18n (es/en/pt) from the first commit, per ecosystem standards.
- **Attendee registration with email only** — no attendee account (conversion first; closed decision 2026-07-19). One ticket per email per event.
- **Atomic capacity**: two simultaneous registrations for the last spot resolve to exactly one ticket (sold-out). Mandatory race-condition tests (AC-traced).
- **QR ticket**: opaque server-side token (no attendee data in the QR), shown on screen and delivered by transactional email (ADR-004, ADR-005).
- **Door check-in**: web app using the organizer's phone camera — valid → green; already used / revoked / invalid → red with reason. Atomic (double-scan resolves to one entry). Organizer-only in MVP.
- **Manual check-in fallback**: organizer finds the attendee in the registered list and checks them in through the same atomic path (broken QR / dead camera at the door). *Addition beyond the brief — confirm at Gate 0.*
- **Live registered/checked-in list** for the organizer.
- **Anti-abuse**: rate limiting on all public writes, event report mechanism, per-event kill-switch (closed decision; ADR-007).
- **Cookieless product analytics**: page views and registration counters via the VisitorHash pattern (no cookies, no raw IPs).
- **Multi-instance attribution**: `NEXO_ATTRIBUTION_*` footer (ecosystem pattern).

### Out (with the why)

- **Payments / paid tickets** — a project in itself (gateway, refunds, invoicing, legal). v1 is free-events-only by closed decision; v2 targets AR/LatAm with Mercado Pago as natural candidate.
- **Offline check-in with sync** — venues without signal are real in LatAm, but v1 is online-only to ship; recorded as a known limitation (ADR-004).
- **Attendee accounts** — email-only wins conversion for free events; optional "save your tickets" account arrives with Nexo ID integration (ADR-003).
- **Staff/collaborator scanning roles** — organizer-only keeps MVP auth simple; multi-scanner events are v2 (ADR-004 — confirm at Gate 0).
- **Ticket types (GA/VIP), per-type quotas, discount codes** — complexity that free v1 doesn't need.
- **Waitlist on sold-out, ticket transfer between people** — post-v1 backlog.
- **Automatic email reminders** — only the ticket email is essential; more sends burn the free email tier.
- **Ecosystem integrations (Nexo Short link, Nexo ID SSO, Nexo Links feature, Nexo Agenda bridge)** — none block the MVP; they land post-launch via public APIs (ADR-006).

## Product principles

- **The QR is a pointer, not a document**: validity lives in the DB; tokens are opaque, revocable, and carry no personal data.
- **Correctness at the door over features**: check-in and capacity are atomic with tests that prove it; a duplicate entry or an oversold room is the one failure users never forgive.
- **A downed instance during a live event leaves people at the door** — production ops (monitoring, deploy freeze during active events, tested backups) are part of the product, not an afterthought.
- **Privacy as pitch**: cookieless, no third-party requests at runtime, no raw IPs stored — like every Nexo tool.
- **Self-hostable standalone**: one Laravel app + MySQL + any SMTP; no hard dependency on other Nexo services.

## Backlog post-v1

<!-- Each item with the why it was postponed. -->

- Payments with Mercado Pago (AR/LatAm) — v2 flagship; deferred because it multiplies scope (fees, refunds, legal).
- Offline check-in with sync — needs conflict resolution design; online-only shipped first.
- Staff scanning roles — needs invitations/permissions model.
- Attendee accounts + "my tickets" — via Nexo ID once its client pattern exists (nexoid ADR-004 / Phase 3).
- Ticket types, quotas per type, discount codes; waitlist; ticket transfer; email reminders — brief §7.
- Nexo Short auto short-link per event; Nexo Links featured event; Nexo Agenda bridge — post-launch integrations (ADR-006).
- Event image upload — deferred at the 2026-07-26 sign-off (see Amendments).

## Amendments

<!-- Dated changes to the scope above, with the why. Never edit history silently. -->

- **2026-07-26 — Event image upload deferred post-v1.** The MVP "in" list promised an event image; it was never built (no column, no upload path). Rather than add it now, it moves to the backlog. Why: it is not part of the core loop (create → register → ticket → door), and an upload field on a public multi-organizer tool brings its own surface — storage limits, image moderation, and files that a DB-only backup would not cover. Keeping v1 DB-only keeps the restore drill (PLAN 8.4) honest. Public event pages ship with the shared OG image until this lands.
