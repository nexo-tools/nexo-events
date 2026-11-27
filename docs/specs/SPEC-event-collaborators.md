# SPEC — Event collaborators (PLAN Phase 10, tasks 10.7, 10.9–10.11)

> Written before the code. Governing decision: **ADR-011** (event collaborators), which
> supersedes ADR-004 §7 ("only the event's organizer can scan") and the "one scanner per
> event" limitation in SPEC-scanner. The API side of it is in SPEC-integration-api v1.1
> (scope `collaborators:manage`).

## Purpose

An event has exactly one owner, and until now only that account could see who registered
or scan at the door. Two people at a door meant sharing a login. The first integration
consumer makes the gap concrete: a venue owns the event (it creates it from its own site)
while the band playing that night sells and manages the entries, and the venue's door
phone must scan without holding the owner's credentials. Both are ordinary organizer needs.

This SPEC lets the owner **invite a collaborator by email** to one event. The collaborator
uses their own Nexo Events account, sees the registrations by name, changes the capacity,
closes and reopens registration, checks people in and releases a spot — and nothing else.

## Scope

**In:** an `event_collaborators` table; one collaborator role; email invitations with a
hashed single-use token; acceptance bound to an account with the same (verified) email;
`Event::canBeManagedBy()` and the move of the registrations / close / scanner / check-in
routes from "owner only" to "managed"; a new "Release a spot" action (ticket → `revoked`);
a "Collaborators" section in the owner's edit screen (on the event's summary since 10.18); a "Ticket management" screen for
owner and collaborators; "Events you manage" on the dashboard; two queued mails
(`CollaboratorInvited`, and `CollaboratorInvitedByIntegration` for invitations made through
the API); the API endpoints of SPEC-integration-api v1.1.

**Out (explicitly):** more than one role (a separate "door only" role, a "can see emails"
role); collaborators editing content, publishing a draft, cancelling, inviting others or
issuing tokens; attendee emails for collaborators; collaborators across all of an owner's
events ("trusted" accounts); transferring ownership; an organization / team model.

## Design decisions

### One role, defined by a list

A collaborator can: open "Ticket management", change the capacity (never below the tickets
already issued), close registration, reopen it (only from `closed`), see the registrations
list with **name and status** (no email), check people in by camera or by hand, and release
a spot. A collaborator cannot: edit the event, publish a draft, cancel, invite or revoke
collaborators, see attendee emails, issue tokens. Everything else stays owner-only.

### Managed vs owned

`Event::canBeManagedBy(User)` is true for the owner, or for a collaborator row with that
`user_id`, `accepted_at` set, `revoked_at` null, and `now() < starts_at + 7 days`. Routes
that a collaborator may use call `managed()`; the rest keep calling `owned()`. A request
that is neither answers 403, exactly as today for a third party.

The 7-day window after `starts_at` is what keeps a band from holding its audience's names
indefinitely, with a `where` and no cron.

### The invitation is not a key

The invitation token is `Str::random(40)`, shown once (in the email, and once to the
inviter so it can be shared by another channel — see below); only its sha256 is stored in
`invite_token_hash`. Accepting requires being signed in with an account whose email equals
the invited email, case-insensitively, **and is verified**. A forwarded or leaked link is
therefore useless without control of that inbox. Accepting clears the hash (single use).

`expires_at = max(now + 7 days, starts_at + 1 day)`: an invitation sent three weeks before
the show must still be acceptable the night before it — the venue's door account is
invited weeks ahead and must not lapse before the door opens.

### Sharing the link outside email is safe because acceptance is bound to the email

Transactional mail from a shared relay can land in spam. The inviter sees the raw link once
— a flash on the screen it was invited from, `invite_url` in the API `POST` response — and can paste it
into a chat. This does not widen anything: the link only works for a signed-in, verified
account with the invited email. The link is never shown again and never returned by a read.

### Inviting requires a verified owner

The same gate as publishing (ADR-007 §1): an owner who has not verified their email cannot
invite anyone. Otherwise a fresh account on a public instance could send "X invited you to
manage the tickets of …" emails to arbitrary addresses.

### Limits

At most **10 active** collaborators per event (active = not revoked, and accepted or still
within `expires_at`). Re-inviting a pending or expired email rotates the token and re-sends
(the old link stops working); re-inviting an accepted email is a no-op; re-inviting a revoked
email starts a fresh invitation. Inviting is throttled (`throttle:10,1` on the web form,
`nexo-api-write` through the API); opening and accepting an invitation is throttled
(`throttle:30,1`).

### Releasing a spot

`POST /app/tickets/{ticket}/liberar` sets the ticket to `revoked`. The status already
existed and was already honoured — capacity excludes revoked tickets and the door rejects
them — but nothing could assign it. A checked-in ticket cannot be released (the person is
inside). The use case is a paid event with capacity where people reserved and did not pay.
The released person gets no email; their ticket page says the spot was released and stops
showing the QR (task 10.12, SPEC-ticket-info AC-TICKET-8).

**Giving a spot back** (2026-11-27, Alvaro's call): `POST /app/tickets/{ticket}/devolver` undoes
a release — the ticket goes back to `valid` and the same QR gets in again — only while the event
has room: the count runs under the same event-row lock as a registration, so a restore and a
last-spot sign-up cannot both win; a cancelled event gives nothing back; a ticket that was not
released is left as it is. Nothing is mailed, either way. The list keeps a released ticket with
its own status, «Lugar liberado», and offers «Devolver el lugar» while there is room («Sin cupo
para devolverlo» otherwise). At the door a released QR answers «✗ Lugar liberado: ya no sirve
para entrar» plus the name, instead of a generic «revoked».

### Who sees what

| Actor | Sees | Never sees |
|---|---|---|
| Public | the event page | the list, `access_info` |
| Attendee | their own ticket | anyone else |
| Collaborator | capacity, counters, registrations by **name and status**, the scanner, `access_info`/`payment_info` | attendee emails, the edit form, cancel, invite, tokens, other events |
| Owner | everything, including attendee emails and the collaborator list | — |
| API token with `collaborators:manage` | the collaborator list of events it created | attendee names, emails, tickets |

## Routes

| Route | Who | |
|---|---|---|
| `GET /app/eventos/{event}/gestion` | owner + collaborator | Ticket management |
| `POST /app/eventos/{event}/cupo` | owner + collaborator | capacity, nullable, 1..1000000, ≥ issued |
| `POST /app/eventos/{event}/reabrir` | owner + collaborator | only from `closed`; uses the **owner's** verified email |
| `GET /app/eventos/{event}/registrados` · `/escanear` · `POST /checkin` · `POST /app/tickets/{ticket}/checkin` · `POST /app/eventos/{event}/cerrar` | owner + collaborator | existing, now `managed()` |
| `POST /app/tickets/{ticket}/liberar` | owner + collaborator | release a spot, `throttle:60,1` |
| `POST /app/tickets/{ticket}/devolver` | owner + collaborator | give a released spot back while there is room, `throttle:60,1` |
| `POST /app/eventos/{event}/colaboradores` · `POST …/colaboradores/{collaborator}/revocar` | owner | invite / resend · revoke |
| `GET /app/invitaciones/{token}` · `POST …/aceptar` | signed in | review and accept, `throttle:30,1` |

A signed-out visitor opening an invitation is sent to the login page with the invitation
as the intended URL; the login page then explains that the account must use the invited
email; registering returns to the invitation as well. The invitation page never reveals
whether the invited email has an account.

## Acceptance criteria

All eighteen are in bold — what the traceability guardian reads: AC-COLLAB-1..10, 17 and 18
landed with 10.9, AC-COLLAB-11..16 with 10.10.

- **AC-COLLAB-1** — Inviting creates a row with the email lowercased, a sha256
  `invite_token_hash` (the raw token is returned once and stored nowhere), `expires_at` =
  `max(now + 7d, starts_at + 1d)` (for an event three weeks away: `starts_at + 1d`), and
  queues `CollaboratorInvited` to the lowercased email.
- **AC-COLLAB-2** — Accepting with a verified account of the same email sets `user_id`
  and `accepted_at` and clears the hash; the same link then answers 404.
- **AC-COLLAB-3** — Accepting with an account of another email answers 403 and
  associates nothing; an expired invitation answers 410; a revoked or unknown one, 404; an
  unverified account with the right email is asked to verify first and associates nothing.
- **AC-COLLAB-4** — A signed-out visitor opening an invitation is redirected to the login
  page and, after registering (or signing in), lands back on the invitation (`intended`).
- **AC-COLLAB-5** — An accepted collaborator can open the registrations list and the
  scanner, check in by token and by hand, close registration and release a spot; and gets
  403 on edit, update, publish, cancel and inviting.
- **AC-COLLAB-6** — A revoked collaborator, one whose invitation lapsed without being
  accepted, and an accepted one 8 days after `starts_at` get 403 on every managed route;
  the owner keeps access.
- **AC-COLLAB-7** — A user who is neither owner nor collaborator still gets 403
  (`EventManagementTest` and `CheckinTest` third-party tests unchanged).
- **AC-COLLAB-8** — A check-in made by a collaborator records their id in
  `checkins.checked_by`.
- **AC-COLLAB-9** — The eleventh active invitation is refused; re-inviting a pending
  email rotates the hash and the previous link stops working.
- **AC-COLLAB-10** — `DatabaseStandardTest` is green with the new table declared; the
  rollback is run against real MySQL; `MailStandardTest`, `RateLimitTest` and the full suite
  are green with the API flag off.
- **AC-COLLAB-11** — The owner invites, resends and revokes from the event's summary
  (the edit screen until 10.18, SPEC-event-panel AC-PANEL-3); each action is reflected in
  the list with its status; right after inviting, the link is shown once with a "Share on
  WhatsApp" link, and not again on reload.
- **AC-COLLAB-12** — "Ticket management" answers 200 for the owner and an accepted
  collaborator and 403 for anyone else, shows registered, checked-in and capacity, and
  renders in the app shell.
- **AC-COLLAB-13** — A collaborator changes the capacity (below the issued tickets:
  422), closes and reopens registration; reopening fails with the unverified-email message
  when the OWNER has not verified.
- **AC-COLLAB-14** — The registrations list contains no attendee email when a
  collaborator opens it, and does when the owner does; it offers "Release a spot".
- **AC-COLLAB-15** — The collaborator's dashboard lists the event under "Events you
  manage"; the owner's dashboard does not list it twice.
- **AC-COLLAB-16** — The edit form warns that the event is managed by another system
  only when `external_ref` is set.
- **AC-COLLAB-17** — An owner without a verified email cannot invite: the service
  answers `UNVERIFIED`, and no row and no mail are created.
- **AC-COLLAB-18** — Releasing a spot sets the ticket to `revoked`, frees capacity,
  refuses a checked-in ticket, and the released QR answers `revoked` at the scanner.
- **AC-COLLAB-19** — Giving a released spot back (owner or collaborator) sets the ticket to
  `valid` and its QR gets in again, with no mail; with no room left it is refused and the list
  says «Sin cupo para devolverlo» instead of offering it; an event without capacity always has
  room; a cancelled event gives nothing back; a ticket that was not released is left alone.
- **AC-COLLAB-20** — A released ticket reads «Lugar liberado» in the registrations list, and the
  door (camera scanner and manual check-in) says «✗ Lugar liberado: ya no sirve para entrar»;
  the scanner answer keeps the attendee's name.

## Guardians

From task 10.9, `ApiSpecTraceabilityTest` reads this SPEC (prefix `AC-COLLAB-`) in both
directions. `AppShellTest` covers the new screens; `DatabaseStandardTest` declares
`event_collaborators`; `MailStandardTest` registers both new mails; `RateLimitTest` keeps
every API write throttled.

## Not proven by this suite

The screens are verified by HTTP tests and the shell guardians; this repo has no browser
harness. A pass on a real phone at 390 px (accept an invitation, Ticket management,
capacity, registrations, scanner) is owner-gated and recorded in the PLAN when it runs.
