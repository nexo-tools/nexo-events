# SPEC — The event's own screens: one place to land, drafts that can go, one word per thing (PLAN Phase 10, tasks 10.18–10.23)

> Written before the code. Source: the Nexo Events findings of the ecosystem usability
> review of 2026-11-24 (`H-nexoevents-06`, `-07`, `-14`), approved by the owner on
> 2026-11-26 together with three loose ends (the edit form's capacity floor, the dangling
> "· Valid" and the scanner's answer to a closed session). The header, the account menu,
> the login and the tab titles are **not** here: they arrive with the shared chrome v2 of
> the Nexo templates, and these screens keep using the app shell untouched.

## Purpose

An organizer who tapped one of their events landed on the **edit form**; registrations, the
door and the capacity were two phone screens below "Save changes", while a collaborator
landed on "Ticket management" — two roles, two starting points. A draft could not be
deleted, and the one destructive button it offered, "Cancel event", would have made it
**public** (a cancelled event shows its page). The capacity had two names ("Cupo" in the
form, "Aforo" in management) and only one of its two forms refused a number below the
tickets already out. The registrations list could leave "· Valid" alone at the start of a
line on a phone. And at the door, a session that ended mid-event made the scanner say
"we could not validate the ticket", which reads as a bad ticket.

## Scope

**In:**

- **The event opens in its summary.** Every event link of the dashboard, for the owner and
  for a collaborator, goes to `events.manage` (`/app/eventos/{event}/gestion`), now called
  **Summary**. The four screens of an event — Summary, Registrations, Door, Edit — share one
  header: a way back to the event list, the event's title, date, venue and status, and the
  event navigation **Summary · Registrations · Door · Edit** (Edit only for the owner).
  The routes do not change.
- **Collaborators live in the summary** (owner only), next to what they manage — capacity,
  registrations, the door. The edit screen keeps the content, publishing and cancelling.
- **Deleting a draft.** `DELETE /app/eventos/{event}` (`events.destroy`), owner only, for a
  draft with no tickets, behind a confirmation; everything hanging from it cascades
  (collaborator invitations, views, reports). There is no API endpoint for it.
- **Cancelling never publishes.** `EventLifecycle::cancel()` only acts on a published or
  closed event; on a draft or a blocked event it changes nothing, on both surfaces.
- **One capacity rule.** `EventRequest` (web form and API alike) refuses a capacity lower
  than the tickets already issued, with the summary's message. A value that did not change
  is accepted, so an event whose older data sits below that floor can still be edited.
- **One word:** "Cupo" in Spanish (never "Aforo"), "Vagas" in Portuguese; the door is "Door"
  («Puerta»).
- **Lines that wrap whole.** In the registrations list (and in the dashboard and summary
  meta lines) the pieces wrap as units with a gap between them and no printed separator —
  the fix the phone line got in 10.16.
- **The scanner and a closed session.** A 401 or a 419 from the check-in endpoint says
  "Your session ended." with a "Sign in again" link back to the scanner page, and turns the
  camera off. Every other answer keeps its current message.
- **Confirmations that run.** The CSP has no `'unsafe-inline'` for scripts, so an inline
  `onsubmit="return confirm(…)"` never ran — "Cancel event", "Release spot", "Remove
  access" and "Revoke" submitted without asking. Destructive forms declare `data-confirm`
  and the bundle asks before the double-submit guard runs.

**Out:** the header/account/mobile chrome, login and tab titles (chrome v2); unpublishing a
published event (it is cancelled, which tells ticket holders); an API delete; a waiting list.

## Acceptance criteria

A criterion is declared in bold when its tests land (`ApiSpecTraceabilityTest` reads this
SPEC with the prefix `AC-PANEL-`).

- **AC-PANEL-1** (10.18) — Every event link of the dashboard, owned or managed, points to
  `events.manage`; none points to `events.edit`.
- **AC-PANEL-2** (10.18) — Summary, Registrations, Door and Edit render the event navigation
  with the four links (three for a collaborator: no Edit), the current one marked
  `aria-current="page"`, in the three languages, and a link back to the dashboard.
- **AC-PANEL-3** (10.18) — The owner invites, resends and revokes collaborators from the
  summary and is sent back to it; the edit screen has no collaborator section; a
  collaborator's summary has none either. A draft's summary tells the owner it is not
  public yet, links to Edit and hands out no public link.
- **AC-PANEL-4** (10.19) — The owner deletes a draft without tickets: the row and what hangs
  from it are gone and the dashboard says so; a published, closed, cancelled or blocked
  event is refused and stays as it was; a collaborator and a stranger get 403.
- **AC-PANEL-5** (10.19) — Cancelling a draft or a blocked event, from the panel or the API,
  leaves its status as it was, its public page answering 404 and no mail queued; the edit
  screen of a draft offers "Delete draft" and no "Cancel event", and a cancelled or blocked
  event offers neither.
- AC-PANEL-6 (10.21) — The edit form and `PATCH /api/v1/events/{id}` refuse a capacity
  below the tickets already issued (revoked ones do not count) with the summary's message
  and change nothing; the same value as stored is accepted; a body without `capacity`
  leaves it alone; a new event has no floor.
- **AC-PANEL-7** (10.20) — No "Aforo" in `lang/` or `resources/views`; the Spanish capacity
  strings all say "cupo", the Portuguese ones "vagas".
- AC-PANEL-8 (10.22) — In the registrations list the email and the status are two pieces of
  a wrapping row with no separator character between them, for the owner and for a
  collaborator; the dashboard and summary meta lines follow the same rule.
- AC-PANEL-9 (10.23) — The scanner classifies a 401 or a 419 as a closed session and shows
  "Your session ended." with a "Sign in again" link to the scanner page; 429 keeps
  "Too many attempts", any other failure keeps "We could not validate the ticket", and a
  200 keeps its result (node test of `scanner.js`, plus a browser pass).
- **AC-PANEL-10** (10.19) — No view carries an inline event handler (`on…=`); every form that
  deletes, cancels, releases or revokes declares `data-confirm`.

## Not proven by this suite

The scanner's camera path needs a browser; it is exercised with Playwright against the local
app (a canvas as the camera, the native detector stubbed), with the captures kept with the
owner's planning notes. A pass on a real phone at the door stays owner-gated.
