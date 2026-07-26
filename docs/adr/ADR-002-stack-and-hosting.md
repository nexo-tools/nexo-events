# ADR-002 — Stack & hosting: Laravel + MySQL on Hostinger shared

- **Date:** 2026-07-19
- **Status:** Accepted (2026-07-26; stack chosen by Alvaro, 2026-07-19)

## Context

The standards system offers two ecosystem paths: the strategic TypeScript direction (Next/NestJS + Postgres; Vercel + Neon as proven today, ref. la-herreria) or the pragmatic Laravel + MySQL on the already-paid Hostinger shared hosting where every existing Nexo tool lives. Nexo ID — the future SSO provider for this tool — chose Laravel + Hostinger too (nexoid ADR-002).

## Decision

1. **Laravel (latest) + MySQL, deployed on Hostinger shared hosting** alongside the sibling tools. Chosen by Alvaro over the TS path.
2. **Development without local PHP**: Sail-based bootstrap per the `laravel-bootstrap-docker-only` skill (verified creating nexo-agenda). Tests on SQLite `:memory:` (ecosystem standard).
3. **Quality toolchain as the siblings**: Pest, Pint, Larastan; CI from the nexo-agenda canonical workflow (lint + static analysis + translations check + build + tests) plus `composer audit`.
4. **Canonical ecosystem pieces** are copied from their references (CATALOG.md): SecurityHeaders + strict CSP + `.htaccess` sync test, translations generator (es/en/pt) + guard test, brand-assets generator, attribution env footer.
5. **QR scanning is a web page, not a native app**: `getUserMedia` camera access + a JS QR-decode library on the organizer's phone browser. Requires HTTPS (already the case). Library choice is a spike task in the check-in phase — it must work as a static asset (no runtime third-party requests, CSP-compatible).
6. Deploy follows the `deploy-laravel-hostinger` playbook; production baseline (backups, uptime, event-aware deploy freeze) is defined in PLAN Phase 4.

## Alternatives considered

- **TypeScript end-to-end (Next.js on Vercel + Neon)** — the strategic direction, but rejected here by Alvaro: the Nexo family lives on Laravel + Hostinger today; shared conventions, canonical pieces (CSP, i18n, CI) and the nexoid integration path all transfer directly, and deploy reality is proven.
- **Native/PWA scanner app** — rejected by the brief: web app with camera is enough for MVP and keeps one deliverable.

## Consequences

- Hostinger shared constraints apply (known from siblings): no Node on the server (assets built in CI and uploaded), `exec`/`proc_open` disabled, LiteSpeed overriding CSP (mitigated by the sync test), SSH on port 65002.
- No long-running workers: queued work (ticket emails) runs via database queue + cron `schedule:run` (see ADR-005).
- Transactional email cannot use the shared host's SMTP for deliverability reasons — external provider (ADR-005).
- Check-in is **online-only in v1**: the organizer's phone needs connectivity at the door. Known limitation (brief §4); offline-with-sync is backlog.
- Capacity/check-in atomicity is implemented on MySQL semantics (row locks, unique constraints) — see ADR-004.
