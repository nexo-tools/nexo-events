# SPEC — Report & kill-switch (PLAN Phase 7, tasks 7.1–7.3)

> Written before the code. Governing decision: **ADR-007** (anti-abuse is day-1 scope, not hardening-later). ADR-007 §"Consequences" left one thing open — *"SPEC decides: CLI command vs minimal admin page"* — and this settles it.

## Purpose

`EventStatus::Killed` exists, is honoured everywhere (the public page hides the event, the door rejects its tickets), and **nothing can set it**. There is no report flow either. A public multi-organizer tool where anyone can publish under the instance's domain, with no way to report and no way to take something down, is not launchable.

## Scope

**In:** a public "report this event" form; storage of reports; a notification to the instance operator; `events:kill` / `events:restore` artisan commands with an audit trail.

**Out:** an admin web UI (see below), automated moderation, reputation scoring, appeals workflow, reporting *organizers* rather than events.

## Design decisions

### The operator surface is the CLI, not a web panel

ADR-007 left this open. Resolved: **artisan commands**.

A web admin panel means a new privilege tier (an `is_admin` column, a gate, an authenticated area, its own tests, its own attack surface) to serve exactly one person on an instance that has never had an abuse report. The CLI needs none of that: whoever can reach the server is already the operator, and self-hosters get the same tool without a half-built moderation product they did not ask for. If report volume ever justifies a panel, it is additive — the commands stay the mechanism underneath.

### Killing is reversible, and says so in the record

A kill is a moderation action taken under time pressure, sometimes on a bad report. It records **when, why, and what the status was before**, so `events:restore` puts the event back exactly where it was instead of guessing "published". Without the previous status stored, restoring a killed *draft* would silently publish it.

### Reporting must not become a nuisance vector

Reports are unauthenticated by design (ADR-007 §3) — requiring an account to report an obvious phishing event defeats the purpose. So the form gets the same protections as attendee registration: honeypot plus a tight rate limit, and the response is identical whatever happens, so nobody can probe which events already have reports.

## Acceptance criteria

### Report

- **AC-ABUSE-1** — Any visitor can report a published event without logging in; the report is stored with the event, the reason, and an optional reporter email.
- **AC-ABUSE-2** — Reporting is rate-limited; over the limit the request is rejected with 429 and **no report row is written**.
- **AC-ABUSE-3** — A report notifies the instance operator (queued, to the configured support address), including the event and the reason.
- **AC-ABUSE-4** — The visible response is the same whether the event has zero prior reports or many, and reporting never discloses organizer or attendee data.
- **AC-ABUSE-5** — A bot that fills the honeypot is refused and stores nothing.

### Kill-switch

- **AC-KILL-1** — `events:kill <slug> --reason="…"` sets the event to `killed` and records the reason, the timestamp and the status it had before.
- **AC-KILL-2** — A killed event's public page is gone (404), registration is refused, and its already-issued tickets are rejected at the door with a reason rather than checked in.
- **AC-KILL-3** — `events:restore <slug>` returns the event to **the status it had before the kill** (not a hardcoded one) and its door works again.
- **AC-KILL-4** — Both commands fail loudly on an unknown slug: a non-zero exit code and no change.
- **AC-KILL-5** — Killing is idempotent: killing an already-killed event does not overwrite the original reason or the recorded previous status.

## Definition of done

All ACs green with name-traced tests · Pint + Larastan + Pest + i18n `--check` clean · a kill/restore round trip exercised against a real event · `AGENTS.md` updated · the drill repeated on production at Gate 8 (PLAN 8.5).
