<div align="center">

<img src="resources/brand/isotype.svg" width="88" alt="Nexo Events isotype">

# Nexo Events

**Free event registration and QR tickets you host yourself — an open-source Eventbrite / Luma alternative for simple events.**
Create an event, share the page, attendees register with just an email and get a QR ticket; you scan them in at the door from your phone. Self-hosted, cookieless, no per-ticket fees.

[![CI](https://github.com/nexo-tools/nexo-events/actions/workflows/ci.yml/badge.svg)](https://github.com/nexo-tools/nexo-events/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-7C3AED.svg)](LICENSE)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg)
![Laravel 13](https://img.shields.io/badge/Laravel-13-ff2d20.svg)

[Scope](docs/SCOPE.md) ·
[Plan & gates](docs/PLAN.md)

</div>

---

Nexo Events is the **open-source alternative to Eventbrite and Luma** for free events:
an organizer creates an event, publishes a public page, and attendees register in
seconds with nothing but an email — no account, no app. Each registration gets a **QR
ticket**, and the organizer validates entry at the door by scanning from a phone. It's
part of the **Nexo** family, so it ships the shared Nexo chrome and the optional
single account ([Nexo ID](https://github.com/nexo-tools/nexo-id) SSO).

## Why Nexo Events?

- **No per-ticket fees, no lock-in** — your events, attendees and data live on *your*
  domain. Commercial platforms take a cut of every ticket; this one is free to run.
- **Register with just an email** — attendees sign up from the public event page with
  no account and no app to install, and immediately get their ticket.
- **QR tickets** — every registration produces a unique, server-generated QR ticket on
  its own shareable page, ready to show at the door.
- **Check-in at the door from a phone** — open the scanner for an event, point the
  camera at a ticket, and it's validated. Check-in is **atomic**: a ticket already used
  is flagged instead of admitted twice, so no double entry — plus a manual fallback for
  each attendee.
- **Full event lifecycle** — create, edit, **publish**, **close** registrations and
  **cancel**, with a live registrations list per event.
- **Organizer accounts, your way** — standalone local login out of the box, or turn on
  Nexo ID SSO to share one account across the whole Nexo ecosystem.
- **Private by design** — cookieless, server-rendered, **zero external requests** (no
  CDNs, no font services, no trackers); attendee data stays in your database.
- **Multilingual** — English, Spanish and Portuguese (`en`/`es`/`pt`) with a visible
  switcher, plus a translatable `/help` center.
- **Self-hostable** — a standard Laravel app; runs on your own infrastructure.

## Tech stack

PHP 8.3+ · Laravel 13 · Blade + Alpine.js + Tailwind CSS (Vite) · MySQL

QR generation with [bacon/bacon-qr-code](https://github.com/Bacon/BaconQrCode); Nexo ID
id_token verification with [firebase/php-jwt](https://github.com/firebase/php-jwt).
Quality: [Pest](https://pestphp.com) · [Pint](https://laravel.com/docs/pint) ·
[Larastan](https://github.com/larastan/larastan) · GitHub Actions CI.
Zero external runtime requests — system font stack, no CDNs.

## Quick start (local)

Requirements: Docker — everything else runs in containers via
[Laravel Sail](https://laravel.com/docs/sail).

```bash
git clone https://github.com/nexo-tools/nexo-events.git
cd nexo-events
cp .env.example .env
docker run --rm -v "$(pwd):/app" -w /app composer:latest composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

Open [http://localhost](http://localhost). Local email inbox (Mailpit):
[http://localhost:8025](http://localhost:8025).

## Self-hosting

Nexo Events is a standard Laravel app — deploy it on your own domain and
infrastructure. Key configuration (see `.env.example`):

| Env var | Purpose | Default |
| --- | --- | --- |
| `NEXO_ATTRIBUTION_LABEL` | "Powered by" label in the shared footer | unset |
| `NEXO_ATTRIBUTION_URL` | Footer link target | unset |
| `NEXO_SUPPORT_EMAIL` | Contact address on the `/help` center | `hola@alvarocdev.com` |
| `NEXO_SUPPORT_URL` | Support URL (wins over the mailto when set) | unset |
| `NEXO_SSO_ENABLED` | Sign organizers in with Nexo ID instead of local auth | `false` |

Attribution and support settings live in [`config/nexo.php`](config/nexo.php).

## Status

**MVP built (pre-launch).** Organizer accounts (local auth + optional Nexo ID SSO),
event create / edit / publish / close / cancel, email-only attendee registration, QR
tickets and phone-based door check-in are all implemented, on top of the shared Nexo
brand/chrome, i18n (`es`/`en`/`pt`) and a `/help` center. Not yet deployed —
production hardening and launch are the remaining work.

## Documentation

- [Scope](docs/SCOPE.md)
- [Plan & gates](docs/PLAN.md)
- [Decisions (ADRs)](docs/adr/)

## Nexo ecosystem

Nexo is a family of open-source, self-hostable tools that share one visual identity
([nexo-brand](https://github.com/nexo-tools)), one optional account
([Nexo ID](https://github.com/nexo-tools/nexo-id) SSO) and one set of engineering
standards. Every tool runs **fully standalone** — the ecosystem is opt-in.

| Tool | What it is | Repo |
| --- | --- | --- |
| **Nexo Tools** | Ecosystem hub — discover the tools and hop between them with one account | [nexo-tools](https://github.com/nexo-tools/nexo-tools) |
| **Nexo Links** | Link-in-bio you host yourself (Linktree alternative) | [nexo-links](https://github.com/nexo-tools/nexo-links) |
| **Nexo Agenda** | Bookings for service businesses (AgendaPro / Fresha / Booksy alternative) | [nexo-agenda](https://github.com/nexo-tools/nexo-agenda) |
| **Nexo Short** | Self-hosted URL shortener | [nexo-short](https://github.com/nexo-tools/nexo-short) |
| **Nexo Events** | Event tickets and passes | — you are here |
| **Nexo ID** | One account for every tool — OAuth 2.0 / OIDC SSO | [nexo-id](https://github.com/nexo-tools/nexo-id) |

New to Nexo? Start at **[nexotools.alvarocdev.com](https://nexotools.alvarocdev.com)**.
Built by **[alvarocdev.com](https://alvarocdev.com)** — the tech behind Nexo.

## License

[MIT](LICENSE) © [Alvaro Carrizales](https://alvarocdev.com) — the tech behind Nexo.
