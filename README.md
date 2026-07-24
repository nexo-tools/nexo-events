<div align="center">

<img src="resources/brand/isotype.svg" width="88" alt="Nexo Events isotype">

# Nexo Events

**Free event registration and QR ticketing — the open-source events tool of the Nexo ecosystem.**
Create an event, share the page, attendees register with just an email and get a QR ticket; validate entry at the door from your phone. Self-hostable, cookieless, privacy by design.

</div>

---

Status: MVP built (pre-launch) — organizer accounts (local auth + optional Nexo ID SSO), event create / publish / close / cancel, email-only attendee registration, QR tickets and phone-based door check-in are implemented, on top of the shared Nexo brand/chrome, i18n (es/en/pt) and a `/help` center. Not yet deployed — production hardening and launch are the remaining work.

- Scope: [docs/SCOPE.md](docs/SCOPE.md)
- Plan & gates: [docs/PLAN.md](docs/PLAN.md)
- Decisions: [docs/adr/](docs/adr/)

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
