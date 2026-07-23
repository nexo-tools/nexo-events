# PLAN — Nexo Events

> Execution follows the `planning-by-stages` skill (alvaro standards repo): one numbered task at a time, checklist marked at the moment, SPEC before code, AC ↔ test traceability by name, one commit per task (`"N,M description"`), CI green before the next task, gate per phase with owner sign-off.
>
> Phase 1 is broken into numbered tasks now by explicit planning directive; its task list gets reconciled against the Phase 1 SPEC's ACs when the phase opens (task 1.1). Later phases list objective, key work, and gate criteria only.

## Phase 0 — Planning & foundations (current)

**Objective:** decisions made and recorded, scope fixed, project formalized. Zero product code.

- [x] 0.1 Read the standards system + evaluation brief (`nexoevents.md`); separate closed product decisions, hard technical requirements, and inputs to re-evaluate.
- [x] 0.2 Resolve Alvaro's decisions: stack (Laravel + Hostinger), attendee model (email-only v1), nexoid coordination path, open source multi-instance confirmed.
- [x] 0.3 `docs/SCOPE.md` — value proposition, MVP in/out with whys, product principles, backlog.
- [x] 0.4 Foundational ADRs 001–007, status Proposed; accounts ADR coordinated with nexoid ADR-004 (cross-note in their AGENTS.md).
- [x] 0.5 `docs/PLAN.md` (this file) with phases and gates.
- [x] 0.6 Formalization: `AGENTS.md` (EN), `CLAUDE.md` → AGENTS, `CLAUDE.local.md` (gitignored) with standards briefing, `README.md` with Status line, `.gitignore`, git init, private GitHub repo in `nexo-tools`.
- [ ] 0.7 Present plan + decisions to Alvaro; resolve gate flags; stamp sign-off.

**Gate 0 (owner sign-off required):**
- [ ] ADRs 001–007 reviewed and accepted (or amended).
- [ ] Gate flags resolved: manual check-in fallback in MVP (SCOPE), staff scanning deferred to v2 (ADR-004 §7), naming — repo slug `nexo-events` / subdomain `nexoevents.alvarocdev.com` (ADR-001 §5).
- [ ] SCOPE MVP in/out approved.
- [ ] Sign-off: **pending**.

## Phase 1 — Foundation & organizer core

**Objective:** scaffolded, CI-guarded app where an organizer can register, verify email, and manage events with public pages. No tickets yet.

- [ ] 1.1 `SPEC.md` for this phase (numbered ACs: organizer auth, event lifecycle, public page, i18n/SEO/CSP baselines); reconcile this task list against it.
- [ ] 1.2 Scaffold: Laravel + Sail per `laravel-bootstrap-docker-only` (SQLite `:memory:` tests restored), Pest + Pint + Larastan.
- [ ] 1.3 CI from nexo-agenda canonical workflow: Pint + Larastan + translations check + build + Pest + `composer audit`.
- [ ] 1.4 Canonical ecosystem pieces (CATALOG.md): translations generator (es/en/pt) + guard test, SecurityHeaders + strict CSP + `.htaccess` sync test, brand assets, `NEXO_ATTRIBUTION_*` footer.
- [ ] 1.5 Organizer auth (local): register, login, logout, email verification, password reset — rate-limited, with deliberate-violation tests (ADR-003, ADR-007).
- [ ] 1.6 Event model + migrations: schema per ADR-004 (events with slug, status lifecycle incl. `killed`, nullable capacity/short_url), factories, status transition rules.
- [ ] 1.7 Organizer dashboard: event create/edit/cancel, registration close, registered list (empty for now).
- [ ] 1.8 Public event page: slug URL, SEO base (title/description/OG/canonical/JSON-LD Event/sitemap), i18n, attribution footer, cookieless view counter (VisitorHash).
- [ ] 1.9 Gate 1 audit pass + sign-off.

**Gate 1:** all ACs green with name-traced tests (`grep` pass); deliberate violations caught (rate limit blocks, unverified email cannot publish, CSP sync test fails on drift); security audit exercised; ARCHITECTURE matches reality; owner sign-off.

## Phase 2 — Registration, tickets & email

**Objective:** attendees register with email only and receive a working QR ticket; capacity is race-proof.

Key work: phase SPEC; **opening spike = deliverability** (Brevo SMTP, SPF/DKIM on alvarocdev.com, real inbox tests, QR embedding — reconcile ADR-005); attendee registration (email-only, honeypot, per-IP/per-event rate limits, `UNIQUE(event_id, attendee_email)`); atomic capacity with concurrent-registration ACs (ADR-004 §5); opaque token generation + hashed storage; ticket screen + "view my ticket" link + re-send flow; queued "Your ticket" email (database queue + cron).

**Gate 2:** race ACs proven by concurrent tests (last spot, duplicate email); token never stored raw (test greps schema/fixtures); real ticket email lands in Gmail/Outlook inbox with scannable QR; rate-limit violations caught; owner sign-off.

## Phase 3 — Check-in at the door

**Objective:** organizer scans tickets with their phone; double scans lose; the door flow works on a real phone.

Key work: phase SPEC; spike — JS QR-decode library as static asset (CSP-compatible, no runtime third parties) on real iOS/Android browsers; scanner page (camera, organizer-only) + atomic check-in endpoint (ADR-004 §4) with race ACs; green/red result UX with reasons (used/revoked/killed/unknown); manual fallback check-in from the registered list; live registered/checked-in counts.

**Gate 3:** double-scan race AC green (concurrent test); real-device door flow exercised end to end (register → email → scan → green; rescan → red); revoked/killed tickets rejected with reason; owner sign-off.

## Phase 4 — Production, hardening & launch

**Objective:** live at the hosted instance, operable during real events, abuse-ready. Launch = MVP complete.

Key work: deploy via `deploy-laravel-hostinger` (cron for queue + scheduler); production baseline per standards — **verified backups (restore tested once, DB + uploaded images)**, uptime monitoring with alerting; **event-aware operations**: a down instance during a live event strands people at the door → documented deploy-freeze rule (no deploys while any event is in its door window; `php artisan down` counts as downtime), monitoring checked before event hours; anti-abuse live (report flow, kill-switch drill on a real test event); security/perf audit exercised; `audit-open-source` + publish decision (MIT, English README); coordination notes routed (nexotools "coming soon", Nexo Short API note).

**Gate 4:** production smoke (HTTP + CSP + full real flow with email on the live instance); backup restored for real once; uptime alert fires in a drill; kill-switch drill passed; audit findings closed; owner sign-off.

## Phase 5 — Ecosystem integrations (post-launch)

**Objective:** Nexo Events joins the ecosystem, order driven by sibling readiness (ADR-006).

Key work (each item its own SPEC when opened): Nexo Short auto short-link per event (when Nexo Short is live); "Sign in with Nexo ID" for organizers via nexoid's Phase 3 client pattern; Nexo Links featured event; attendee accounts / "my tickets" (v2, via Nexo ID). Payments (Mercado Pago, AR/LatAm) remains a separate v2 decision with its own planning round — not a phase here.

**Gate 5:** per integration — real cross-tool flow exercised, standalone mode still green (self-host story intact), owner sign-off.
