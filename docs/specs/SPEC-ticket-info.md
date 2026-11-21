# SPEC — Ticket-holder info and payment text (PLAN Phase 10, tasks 10.7–10.8 and 10.12) · optional attendee phone (10.13)

> Written before the code. Governing decision: **ADR-012** (ticket-holder info and payment
> text), which amends ADR-001 §1 narrowly — the product still processes no payment — and
> leaves ADR-004 §1 untouched: the QR still carries nothing but an opaque token.

## Purpose

Two things an organizer could not say through Nexo Events until now, both requested by the
first integration consumer (a venue) and both useful to any organizer:

1. **Where exactly, but only to people holding a ticket.** `venue` is public by design: it
   is printed on `/e/{slug}`, in the SEO description and as `Place.address` in the JSON-LD.
   A house concert, a private room or a venue that does not publish its street address had
   no way to tell attendees how to get there except by email, outside the product. The
   ticket page (`/t/{token}`) did not even show `venue` — only the ticket email did.
2. **How much, and how it is paid — without the product taking the money.** v1 is
   free-events-only (ADR-001 §1) and stays that way: there is no gateway, no fee, no refund.
   But an event that charges at the door, or by bank transfer to the band, had to either
   hide that or put it in the description, while the JSON-LD declared `price: 0` to search
   engines — a contradiction the product itself created.

## Scope

**In:** two nullable text columns on `events` — `access_info` (private: ticket holders only)
and `payment_info` (public: price / how it is paid, as free text) — editable in the
organizer form and through the integration API; `/t/{token}` gains "Where" (`venue`), the
`access_info` block and the `payment_info` line; `/e/{slug}` gains the `payment_info` block
above the registration form; the ticket email and the 24 h reminder gain both rows; the
door scanner shows a fixed `payment_info` note; the event payload of the API gains
`access_info`, `payment_info` and `checked_in_count` (see SPEC-integration-api v1.1);
JSON-LD stops claiming `price: 0` when `payment_info` is present; legal pages and README
updated in the same task.

**Out (explicitly):** structured price, currency or amount fields; any payment state on a
ticket ("paid", "pending", proof of transfer); collecting money; putting any of this into
the QR (the QR stays an opaque pointer, ADR-004 §1); HTML or Markdown in either field —
both are plain text, escaped, line breaks preserved, because they are exactly the fields a
future wallet pass will carry (Wallet item of the SCOPE backlog).

## Design decisions

### Two fields, because one cannot be public and private at once

A single "ticket info" field would have to be either published (and leak the address) or
hidden (and hide the price from the person deciding whether to register). `access_info`
is shown **only** where a ticket is in hand: `/t/{token}`, the ticket email, the reminder.
`payment_info` is shown everywhere a person decides or pays: `/e/{slug}` before the form,
`/t/{token}`, both emails, and the scanner (the person at the door collects before
scanning).

### "Private" means "not published", not "secret"

Registration is free and takes any email. `access_info` is therefore one free registration
away from anybody who wants it. It keeps an address out of search engines, link previews
and casual browsing; it does not protect it from a determined visitor. The organizer form
says so in its help text, and organizers who need a real secret should not put it here.

### JSON-LD tells the truth or says nothing

With `payment_info` present, the `offers` block of the event JSON-LD drops `price` and
`priceCurrency` and keeps `availability` and `url`. The text is not parseable into a number
and inventing one would be worse than omitting it. Without `payment_info` the JSON-LD is
byte-identical to v1 (`price: "0"`, `ARS`).

### `access_info` never reaches a public surface

Not in `/e/{slug}`, not in the `<meta name="description">` built by `seoDescription()`, not
in the JSON-LD, not in the sitemap. Asserted by test on each one.

### A released ticket says so

A spot released by the owner or a collaborator (`tickets.status = revoked`, ADR-011 §6)
used to keep rendering its QR on `/t/{token}`: only the door knew. From task 10.12 the
ticket page replaces the QR and "Show this QR at the door" with a danger notice
(`role="alert"`) saying the organizer released the spot and to talk to them if it is a
mistake; title, date, name, "Where" and both fields stay. No email is sent to the person
(backlog; trigger: the first argument at the door).

### Validation lives in `EventRequest`, the one rule set

`access_info`: `nullable|string|max:1000`. `payment_info`: `nullable|string|max:300`. Same
rules for the web form and the API (ADR-010 §6).

## Acceptance criteria

AC-TICKET-1..7 landed with task 10.8 and AC-TICKET-8 with task 10.12 (bold is what the
traceability guardian reads). AC-TICKET-9..13 (task 10.13) are in their own section below.

- **AC-TICKET-1** — With `access_info` set, `/t/{token}` shows it under "Only for ticket
  holders", together with "Where" (`venue`, which the ticket page did not show before).
  `/e/{slug}`, its `<meta name="description">` and its JSON-LD do not contain it.
- **AC-TICKET-2** — The ticket email and the reminder email carry an `access_info` row
  and a `payment_info` row when set, rendered in es, en and pt (extends AC-EMAIL-2).
- **AC-TICKET-3** — With `payment_info` set, `/e/{slug}` shows it before the
  registration form and the JSON-LD `offers` carries no `price` and no `priceCurrency`;
  without it, the JSON-LD is byte-identical to the v1 output.
- **AC-TICKET-4** — The door scanner page shows `payment_info` as a fixed note when set,
  and nothing when it is not.
- **AC-TICKET-5** — With both fields empty, `/t/{token}`, `/e/{slug}` and both emails
  render no new block and no new row (the only addition to `/t/{token}` is "Where", which
  belongs to AC-TICKET-1).
- **AC-TICKET-6** — The migration is additive (two nullable columns, `down()` drops
  them), `DatabaseStandardTest` stays green with `EXPECTED_TABLES` unchanged, the migration
  is run and rolled back against real MySQL (DATABASE-STANDARD §11), and `EventRequest`
  enforces the two length limits on the web form and the API alike.
- **AC-TICKET-7** — Every new string is translated (es/pt maps, generator `--check`
  clean), and `DarkModeCoverageTest`, `AccessibilityTest` and `MailStandardTest` stay
  green with the new markup.
- **AC-TICKET-8** — On `/t/{token}` of a `revoked` ticket the released notice is present
  and the QR (and "Show this QR at the door") is absent, while title, date, name, "Where"
  and the ticket-holder info stay; on a `valid` and on a `checked_in` ticket the notice is
  absent and the QR present, and the `checked_in` one keeps its "already checked in" flash.
  The notice is translated in es and pt.

## Optional attendee phone (task 10.13, 2026-11-21)

Venue organizers asked for a way to reach registrants by WhatsApp: a changed time, a door
that opens late. Registration asked only for name and email (ADR-003 §2), so the only
channel was email. This section adds a phone **behind an instance switch**, as amended in
ADR-003 on 2026-11-21.

- **Switch.** `NEXO_ATTENDEE_PHONE` → `config('nexo.attendee_phone')`, `off` (default) or
  `optional`; any other value reads as `off`. Self-hosters who never set it see no change.
- **Form.** With `optional`, `/e/{slug}` shows a field after the email: label "WhatsApp or
  phone (optional)", hint "Only the organizer sees it, in case they need to reach you."
  (es/en/pt), `type="tel"`, `autocomplete="tel"`, `inputmode="tel"`, `name="phone"`, never
  required, wired through `x-field` (label `for`, hint in `aria-describedby`).
- **Validation** (`RegisterAttendeeRequest`). `off`: no rule at all and nothing stored even
  if posted. `optional`: nullable, string, max 30, only an optional leading `+`, digits,
  spaces, `()`, `-` and `.`, and at least 6 digits. Trimmed; empty is `null`.
- **Storage.** `tickets.attendee_phone`, nullable `varchar(30)`, next to `attendee_email`.
  A duplicate registration (same email) does not overwrite it.
- **Visibility: the event owner only**, in the registrations list next to the email — the
  same `showEmails` condition (ADR-011 §2). A number starting with `+` links to
  `https://wa.me/<digits>` ("WhatsApp", new tab); any other to `tel:<digits>`.
  Collaborators never see it; it is in no email, on no public page (`/e/{slug}`, `/t/{token}`,
  sitemap) and in no API answer (AC-API-11).
- **Retention.** There is no purge or anonymization job: tickets live and die with their
  event (cascade). The phone is a column of the ticket, so it follows the name and email.
- **Privacy page.** With `optional`, a section right after "what we store about attendees"
  says what is kept, why and who sees it (es/en/pt). With `off` the page is unchanged.

- **AC-TICKET-9** — With the switch `off` (the default, and for any value other than
  `optional`) `/e/{slug}` has no phone field, and a `phone` posted anyway is neither
  validated nor stored.
- **AC-TICKET-10** — With `optional` the field follows the email with the label, hint and
  attributes above, is not required, and registering without it still works; the stored
  value is trimmed and an empty one is `null`.
- **AC-TICKET-11** — Letters, a `+` anywhere but the start, fewer than 6 digits and more
  than 30 characters are refused; the usual ways of writing a number are accepted.
- **AC-TICKET-12** — The owner's registrations list shows the phone next to the email,
  linked to `wa.me` when it starts with `+` and to `tel:` otherwise; an accepted
  collaborator's list does not contain it; neither do `/e/{slug}`, `/t/{token}`, the
  sitemap, the ticket email or the reminder.
- **AC-TICKET-13** — The privacy page carries the phone section, in es, en and pt, only
  while the switch is `optional`, placed right after the attendee section.

## Guardians

`ApiSpecTraceabilityTest` reads this SPEC from task 10.9 on (prefix `AC-TICKET-`), in both
directions. `SeoBaseTest` and the JSON-LD tests keep the public page honest; `MailStandardTest`
keeps the two emails in the family layout.
