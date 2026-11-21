# ADR-012 — Ticket-holder info and payment text: two plain-text fields, no payment processing

- **Date:** 2026-10-28
- **Status:** Accepted (owner sign-off pending at the phase gate)
- **Amends:** ADR-001 §1, narrowly (text about a price, not a payment feature).
- **Notes on:** ADR-004 §1 (the QR is unchanged).

## Context

Two things organizers could not express: *where exactly*, said only to people holding a
ticket, and *how much and how it is paid*, for events that charge outside the platform.

`venue` is public by design — on `/e/{slug}`, in the SEO description, and as `Place.address`
in the JSON-LD — so a street address put there is published. The ticket page did not even
show `venue`; only the ticket email did. And every event's JSON-LD declared `price: "0"`,
which is false for an event that charges at the door.

ADR-001 §1 keeps v1 free-events-only: no gateway, no fees, no refunds. Nothing here changes
that. What changes is that an organizer may *say* what an entry costs.

## Decision

1. **`events.access_info`** (text, nullable, ≤1000): shown only where a ticket is in hand —
   `/t/{token}`, the ticket email, the 24 h reminder. Never on `/e/{slug}`, never in
   `seoDescription()`, never in the JSON-LD or the sitemap.
2. **`events.payment_info`** (string(300), nullable): free text such as "Entry $8,000 · pay
   at the door". Shown on `/e/{slug}` above the registration form, on `/t/{token}`, in both
   emails and as a fixed note on the door scanner, where whoever is at the door collects
   before scanning.
3. **JSON-LD:** with `payment_info` present, `offers` drops `price` and `priceCurrency`
   (keeps `availability` and `url`); without it, the output is byte-identical to v1.
4. **`/t/{token}` gains "Where" (`venue`)**, which the email already showed and the page did
   not.
5. Both fields are **plain text** — escaped, line breaks preserved, no HTML or Markdown —
   because they are exactly what a future wallet pass will carry (SCOPE backlog).

### Amendment to ADR-001 §1

v1 remains free-events-only as far as **the platform** is concerned: it never collects,
holds or refunds money, and a ticket's validity never depends on payment. An organizer may
describe a price and a way to pay in `payment_info`; the registration is a nominal
reservation, and paying (or showing a transfer receipt) happens between attendee and
organizer, outside the product. The terms of use keep saying "we do not process payments".

### Note on ADR-004 §1

The QR still encodes only the opaque token. Neither field enters the QR or any token: the
address is on the ticket page and in the email, not in the code.

## Alternatives considered

- **One `ticket_info` field** — rejected: it would have to be public (leaking the address)
  or private (hiding the price from the person deciding whether to register).
- **Structured price + currency + `NEXO_CURRENCY`** — rejected for now: more than was asked,
  and it starts the payments project ADR-001 deliberately keeps out. Postponed with a
  trigger: the first time a gateway is planned.
- **Putting the address in `venue` and hiding `venue`** — rejected: `venue` is the public
  "where" of every other organizer's events and of the JSON-LD.
- **Leaving `price: "0"`** — rejected: a false claim made by the product's own markup.

## Consequences

- One additive migration (two nullable columns); `EXPECTED_TABLES` unchanged.
- The event payload of the API grows by these two fields plus `checked_in_count`
  (SPEC-integration-api v1.1, AC-API-6c rewritten on purpose).
- "Private" means "not published", not "secret": registration is free and takes any email,
  so `access_info` is one registration away from anyone. The form's help text says so.

## Amendment — 2026-11-21: the address on its own (PLAN 10.14)

The exact address used to travel inside `access_info`, as free text nobody can tap. A third
field, `events.access_address` (nullable, ≤255), carries it on its own so the ticket page
can offer "copy" and "open in Google Maps / Apple Maps", and the emails one "Open in Google
Maps" link. **It is private on exactly the same terms as `access_info`**: the ticket, its
two emails and the organizer's screens; never `/e/{slug}`, its meta description, its JSON-LD
or the sitemap; and "not published" still is not "secret". `access_info` stays for how to
get in and what to bring. The map links are plain search URLs opened by the person's own
browser — the server makes no request (zero external requests at runtime). Contract:
SPEC-ticket-info AC-TICKET-14..18, SPEC-integration-api v1.2 AC-API-24.
