# ADR-011 — Event collaborators: one role, invited by email, bound to the same verified account

- **Date:** 2026-10-28
- **Status:** Accepted (owner sign-off pending at the phase gate)
- **Supersedes:** ADR-004 §7 ("only the event's organizer can scan in MVP") and the "one
  scanner per event" limitation in `docs/specs/SPEC-scanner.md`.
- **Amends:** ADR-010 §4 (blast radius of an API token).

## Context

An event has exactly one owner (`events.organizer_id`), and every management route checks it
literally. That was deliberate for the MVP (ADR-004 §7, SCOPE "staff scanning roles: v2")
and it has a cost every organizer with a team pays: two people at a door share one login,
with every power that login has — cancelling the event and mailing everyone, issuing API
tokens, editing content.

The first integration consumer makes the case concrete. A venue creates its events from its
own site through the integration API, so the venue's account owns them. The band playing
that night sells and manages the entries, and the venue's own door phone must scan without
holding the owner's credentials. The venue asked for exactly this: create the event on its
side, let the band manage how many entries there are and see who registered, invite the
band by email from its own panel, and reuse the same band account next time.

## Decision

1. **A collaborator is a per-event invitation**, stored in `event_collaborators` (event,
   email lowercased, optional `user_id` once accepted, `invite_token_hash`, `invited_by`,
   `invited_via`, `invited_at`, `expires_at`, `accepted_at`, `revoked_at`;
   `UNIQUE(event_id, email)`). No Policy classes and no generic roles table: one helper,
   `Event::canBeManagedBy(User)`, answers the only question the routes ask.
2. **One role.** A collaborator manages the tickets — capacity, close/reopen, the
   registrations list by **name and status**, check-in by camera or by hand, releasing a
   spot — and nothing else: no editing, no publishing a draft, no cancelling, no inviting,
   no attendee emails, no tokens. A second "door only" role would differ from this one only
   by the capacity and close buttons once emails are already out of the list; it is not worth
   a second code path through the door of every organizer on the instance.
3. **Acceptance is bound to the invited email, on a verified account.** The invitation
   token is `Str::random(40)`, stored as sha256, single use. Accepting requires a signed-in
   account whose email equals the invited one and is verified. A forwarded mail, or a link
   shared by chat, is therefore worthless without the inbox — which is what makes it safe to
   show the inviter the raw link once (flash in the panel, `invite_url` in the API `POST`
   response) so it can travel by another channel when email lands in spam.
4. **Lifetimes.** The invitation expires at `max(now + 7 days, starts_at + 1 day)`, so an
   invitation sent weeks ahead is still valid the night of the show. Access expires on its
   own 7 days after `starts_at` — a band does not keep its audience's names indefinitely —
   enforced in the `canBeManagedBy` query, with no cron.
5. **Abuse brakes.** Inviting requires the owner's verified email (the ADR-007 §1 publish
   gate, reused); at most 10 active collaborators per event; invitations throttled; the
   invitation page never reveals whether an email has an account.
6. **Releasing a spot** (`tickets.status = revoked`) becomes reachable from the
   registrations list for owner and collaborators. The status already existed and was
   already honoured by capacity and by the door; nothing could assign it. A checked-in
   ticket cannot be released. The ticket page says so: a released ticket shows a notice
   instead of its QR (10.12, AC-TICKET-8); no email is sent to the person.
7. **Through the API**, collaborators are a separate opt-in scope, `collaborators:manage`,
   limited to events with an `external_ref` (the ones the integration created), and every
   invitation made by a token mails the owner and records the token's name in
   `invited_via` (SPEC-integration-api v1.1).

## Amendment to §6 (2026-11-27): giving a spot back

Owner and collaborators can undo a release: `POST /app/tickets/{ticket}/devolver` sets the
ticket back to `valid` — the same token, so the QR the person already has gets in again — only
while the event has room, counted under the event-row lock that registrations use. It is the
organizer's explicit act, so it does not contradict ADR-008 §4 (a resend never revives a revoked
ticket; that still holds). Nothing is mailed, as releasing mails nothing. The list shows a
released ticket as «Lugar liberado» with «Devolver el lugar», and the door names the reason
(«Lugar liberado: ya no sirve para entrar») instead of a bare «revoked». SPEC-event-collaborators
AC-COLLAB-19 and AC-COLLAB-20.

## Amendment to ADR-010 §4

ADR-010 §4 says the blast radius of a leaked token is "the owner's event metadata, never
registrants". With `collaborators:manage` that is no longer the whole truth, and it is
written here instead of being left standing: a leaked token **holding that scope** can
invite an address its holder controls and, once that address has a verified account and
accepts, read the **names and status** of the registrants of the events that integration
created — never emails, never tickets, never other events — until the owner revokes the
collaborator or the token, or the window closes 7 days after the event. A token without the
scope keeps exactly the v1 radius. Mitigations: the scope is separate, unticked by default
and offered with a warning; `external_ref` scoping; a mail to the owner per invitation;
`invited_via` in the list; same verified email to accept; single use; 10 active at most;
immediate revocation.

## Alternatives considered

- **Two roles (manager / door) with an `EventPolicy` of ten abilities** — rejected: more
  surface and more regression risk on the one path that cannot fail during an event, for a
  difference that disappears once the list carries no emails.
- **"Transparent delegation": accept with whatever account is signed in** — rejected: it
  turns a forwarded email into access to attendee data.
- **Let `events:write` invite** — rejected: granting a third party sight of registrants is
  not a side effect of being allowed to edit a title, and the owner would never hear of it.
- **Invite only from the Nexo Events panel, never through the API** — rejected: the
  consumer's whole point is that the band never needs somebody to hand it a login.
- **The band as the event's owner** — rejected: the venue would lose the ability to close,
  cancel and scan its own night, because the owner check is literal.
- **Attendee emails visible to collaborators** — not now: names are what a door needs; one
  line of Blade if an owner ever asks for more.

## Consequences

- The registrations list, the scanner, both check-in routes and "close registration" now
  call `managed()`; edit, update, publish, cancel and the collaborator routes keep
  `owned()`. Third parties still get 403.
- Two queued mails join the family (`CollaboratorInvited`, `CollaboratorInvitedByIntegration`)
  and `MailStandardTest` declares them.
- `event_collaborators` joins `DatabaseStandardTest`; the hash column follows the `*_hash`
  rule.
- Legal pages say that an organizer can delegate ticket management to collaborators who see
  registrants' names.
- Offline check-in (SCOPE backlog) loses one simplification it was counting on: more than
  one device can now scan the same event. Whoever designs it must handle the two-devices
  conflict this ADR makes possible.
