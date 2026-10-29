# SPEC — Ticket-holder info and payment text (PLAN Phase 10, tasks 10.7–10.8)

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

### Validation lives in `EventRequest`, the one rule set

`access_info`: `nullable|string|max:1000`. `payment_info`: `nullable|string|max:300`. Same
rules for the web form and the API (ADR-010 §6).

## Acceptance criteria

The criteria below are listed without bold until the task that implements them lands
(task 10.8); bold is what the traceability guardian reads.

- AC-TICKET-1 (10.8) — With `access_info` set, `/t/{token}` shows it under "Only for ticket
  holders", together with "Where" (`venue`, which the ticket page did not show before).
  `/e/{slug}`, its `<meta name="description">` and its JSON-LD do not contain it.
- AC-TICKET-2 (10.8) — The ticket email and the reminder email carry an `access_info` row
  and a `payment_info` row when set, rendered in es, en and pt (extends AC-EMAIL-2).
- AC-TICKET-3 (10.8) — With `payment_info` set, `/e/{slug}` shows it before the
  registration form and the JSON-LD `offers` carries no `price` and no `priceCurrency`;
  without it, the JSON-LD is byte-identical to the v1 output.
- AC-TICKET-4 (10.8) — The door scanner page shows `payment_info` as a fixed note when set,
  and nothing when it is not.
- AC-TICKET-5 (10.8) — With both fields empty, `/t/{token}`, `/e/{slug}` and both emails
  render no new block and no new row (the only addition to `/t/{token}` is "Where", which
  belongs to AC-TICKET-1).
- AC-TICKET-6 (10.8) — The migration is additive (two nullable columns, `down()` drops
  them), `DatabaseStandardTest` stays green with `EXPECTED_TABLES` unchanged, the migration
  is run and rolled back against real MySQL (DATABASE-STANDARD §11), and `EventRequest`
  enforces the two length limits on the web form and the API alike.
- AC-TICKET-7 (10.8) — Every new string is translated (es/pt maps, generator `--check`
  clean), and `DarkModeCoverageTest`, `AccessibilityTest` and `MailStandardTest` stay
  green with the new markup.

## Guardians

`ApiSpecTraceabilityTest` reads this SPEC from task 10.9 on (prefix `AC-TICKET-`), in both
directions. `SeoBaseTest` and the JSON-LD tests keep the public page honest; `MailStandardTest`
keeps the two emails in the family layout.
