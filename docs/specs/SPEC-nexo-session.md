# SPEC — Nexo ID controls the session, the shared chrome v2.1 and the public event page (PLAN Phase 10, tasks 10.25–10.27)

> Written before the code. Source: the ecosystem contract `CONTRATO-F3` of the unified Nexo
> experience (owner-approved 2026-11-26: decisions D1–D10), the Nexo Events findings of the
> usability review of 2026-11-24 (`H-nexoevents-01…05`, `08…13`, `15…18`) and the templates
> `nexo-sso-client` v2.1 and `nexo-ui` v2.1 of the standards repo. The mechanism is the
> templates' and is proven by their own suites (`tests/Feature/NexoSso/`,
> `tests/Feature/Nexo/`); this SPEC is what it has to mean on **this** tool's routes.

## Purpose

Signing out of Nexo Events did nothing on screen and left Nexo ID open (the CSP
`form-action 'self'` dropped the hop to Nexo ID's logout); signing out of Nexo ID left this
tool open; a protected link opened signed out lost its destination at the login; the header
changed from screen to screen and, at 320 px, pushed the account off the screen; and the
public event page carried the tool's header, which an attendee has no use for. Production
also had 133 local accounts and only **one** with a verified email (2026-11-26, read-only
count), so the owner decided this tool runs **only with Nexo ID** (`NEXO_AUTH_MODE=sso`, D2).

## Scope

**In:**

- **The session v2.1 of `nexo-sso-client`**, copied byte for byte (adaptation points:
  `config('nexo-sso.home') = '/app'`, the attendee exclusions `e/*`, `t/*`, `public.*`,
  `ticket.*`, `fallback`; the resolver's `newUser()` and `linked()`; the layout of the
  signed-out, new-account and link-confirm screens). Back-channel logout, the 15-minute
  re-validation (GET page navigations only, fail-open), `/auth/nexo/enter`, the signed-out
  screen, the three auth modes, «Link my account», JWKS rotation, the 419 on «Cerrar sesión».
- **Auth mode on this tool's routes.** `sso`: `POST /login`, registration and the password
  reset answer 404; `/login` shows only «Continuar con Nexo ID»; a guest deep link goes to
  `/auth/nexo/enter?to=<itself>`. `transition`: Nexo ID first, passwords only for accounts not
  linked yet, never a local "remember me", no reset link for a linked account. `local` and
  standalone (`NEXO_SSO_ENABLED=false`): as before.
- **The existing account joins on its own.** Entering through Nexo ID with a verified email
  that a local account already has joins that very account (`linked()`, the template's
  `find()`): same row, its events, the "Nexo ID was linked" mail; an unconfirmed local email
  counts as verified from then on (Nexo ID vouched for it), so the publish gate and a
  collaborator invitation open.
- **Collaborator invitations through Nexo ID.** The link of the invitation mail is a
  protected deep link: signed out, it goes through Nexo ID (where a band without an account
  creates one) and comes back to the invitation, which is accepted as before.
- **The door is never bounced.** The scanner's JSON check-in is a POST: the re-validation
  never touches it. A session Nexo ID ended (or, in `sso`, one it never governed) answers
  `401 {"reason":"signed_out"}`, which the scanner already reads as «Tu sesión se cerró»
  (AC-PANEL-9). The Integration API (`/api/v1`, token, no session) is outside all of it.
- **Chrome v2.1 of `nexo-ui`**: one header on every layout, the account menu with «Tus
  eventos», the phone sheet, the switcher with `entry`, the cookieless beacon emitter,
  `no-store`, CSP `form-action` with the issuer (middleware and `public/.htaccess`),
  `connect-src` from the configured beacon endpoint, the `data-confirm` handler and
  `color-scheme` from the shared files (this app's own copies go), the 404 inside `web`.
- **Tab titles** «<Page> — Nexo Events», and «<Page> · <event> — Nexo Events» on the screens
  of one event (H-nexoevents-16); the landing «Nexo Events — <tagline>».
- **The landing's doors for a visitor** follow the mode (H-nexoevents-12): in `sso`
  «Create an event» enters through Nexo ID to the new-event form and «Sign in» to the panel.
- **D8 — the public event page** `/e/{slug}` has no tool header: the page and the public
  footer (language, theme, «Hecho con Nexo Events») with an «Editar» chip only for its owner
  (→ the editor) or a collaborator who can manage it right now (→ the summary). Its
  registration, resend and report forms do not change.

**Out:** the ticket page `/t/{token}` keeps its layout (it is opened at the door; nothing
asked for it to change); deleting the unverified local accounts left in production; the
waiting list.

## Acceptance criteria

A criterion is declared in bold when its tests land (`ApiSpecTraceabilityTest` reads this
SPEC with the prefix `AC-SESSION-`).

- **AC-SESSION-1** (10.25) — In `sso` mode the local login POST, registration and password
  reset answer 404 and `/login` offers only «Continuar con Nexo ID» (no password field, no
  "remember me", «Crear cuenta» to Nexo ID's sign-up through `/auth/nexo/enter?to=/app&signup=1`,
  never the issuer's `/register`, 10.29); a guest deep link into `/app` is sent
  to `/auth/nexo/enter?to=<that path>`. In `local` the password form, its "remember me" and
  `/login` stay; in `transition` a password sign-in is never remembered and a linked account
  is refused with «Esta cuenta ya entra con Nexo ID» and gets no reset link, and the password
  fold starts open only for whoever chose «Ya tengo una cuenta con otro email» (10.29). With
  SSO off nothing of Nexo ID shows and the local login works.
- **AC-SESSION-2** (10.25) — A local account entering through Nexo ID with the same verified
  email is joined in place (same row, its events, `nexo_id_sub` set, the linked mail once),
  and an unconfirmed email counts as verified; coming back is by `sub`, even with another
  email; an email Nexo ID did not verify joins nothing.
- **AC-SESSION-3** (10.25) — Signed out, the invitation link goes to
  `/auth/nexo/enter?to=/app/invitaciones/<token>`, which goes to Nexo ID remembering it; back
  with a new Nexo ID account (created verified) it lands on the invitation, which is
  accepted; an existing local account with the invited email is joined and accepts; another
  email still gets "this invitation is for another address" (403).
- **AC-SESSION-4** (10.25) — An SSO session past its re-validation window checks people in
  from the scanner (JSON POST, 200 with the result) and from the list (form POST), never
  bounced; only a page navigation of the door re-validates and comes back to it. A session
  Nexo ID ended, and in `sso` a password session from before the deploy, get
  `401 {"reason":"signed_out"}` on the scanner's POST and the ticket stays valid.
- **AC-SESSION-5** (10.25) — With `sso` mode the Integration API answers its bearer token
  with no session and no cookie, and a request without a token is 401, never a redirect.
- **AC-SESSION-6** (10.27) — `/e/{slug}` renders no tool header, switcher or account menu
  and renders the public footer with language, theme and «Hecho con Nexo Events»; the
  «Editar» chip shows for the owner (→ `events.edit`) and an accepted collaborator inside
  their access window (→ `events.manage`), never for anybody else or a guest; the
  registration, resend and report forms are the same and registering works; the footer
  still links the privacy policy and the terms (the page takes the attendee's data); a
  signed-in organizer whose re-validation is due is never bounced to Nexo ID from the page
  nor from a ticket `/t/{token}`, while the panel is.
- **AC-SESSION-7** (10.26) — The dashboard, new event, summary, registrations, door and edit
  screens are titled «<Page> — Nexo Events» / «<Page> · <event> — Nexo Events»; login and
  sign-up «<Page> — Nexo Events»; the landing «Nexo Events — <tagline>».
- **AC-SESSION-8** (10.26) — In `sso` the landing's visitor CTAs point to
  `/auth/nexo/enter` (create → `?to=/app/eventos/nuevo`) and never to the closed `/register`
  or `/login`; in `local` and standalone to `/register` and `/login`; signed in, to the new
  event form and the panel.
- **AC-SESSION-9** (10.25) — The `nexo_sso_revocations` rows are pruned daily by the
  scheduler, inline (`nexo-sso-prune`, `Artisan::call('nexo-sso:prune')`), like every other
  task of this app (production disables `proc_open`).

## Not proven by this suite

The browser half — the Nexo ID round trips, «Cerrar sesión» reaching Nexo ID through the
CSP, the back-channel closing a tab, the back button after signing out, the header and the
sheet at 320/390/1280 px in light and dark, and the scanner with a real ticket — runs with
Playwright (Chromium and WebKit) against an isolated local instance and a mock provider that
follows the contract; captures and logs are kept with the owner's planning notes.
