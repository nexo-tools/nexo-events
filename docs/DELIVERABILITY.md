# Email deliverability

The ticket **is** the email (ADR-005 §6): if it lands in spam, the product failed. This is what any instance — the hosted one or a self-host — has to get right, and what still has to be verified for the hosted instance before launch.

## How the app sends

Plain Laravel mail over **SMTP, configured entirely by env**. No provider SDK, no lock-in: point `MAIL_*` at whatever relay you have (ADR-005 §1).

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=events@yourdomain.com     # must be on a domain you control
MAIL_FROM_NAME="Nexo Events"
QUEUE_CONNECTION=database
```

Mail is **queued**, never sent in-request, so a slow relay never delays a registration. The queue is drained by the scheduler, so production needs the standard one-line cron:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Without that cron **no ticket email ever leaves**, while the app looks perfectly healthy. It is the single most common way to break this feature.

Locally, Mailpit catches everything: `MAIL_HOST=host.docker.internal`, `MAIL_PORT=1025`, inbox at <http://localhost:8025>.

## Current state of the hosted instance — a known, temporary deviation

**2026-07-26.** The production instance is configured against **Hostinger's own SMTP**
(`smtp.hostinger.com:465`, `MAIL_SCHEME=smtps`) using a mailbox on the domain, **not** a
transactional relay. This contradicts **ADR-005 §3** ("Never the Hostinger SMTP for ticket
mail") and is recorded here rather than left implicit.

Why it is acceptable *for now*: it lets the instance come up and the whole flow be exercised
end to end — register, receive a ticket, scan it at the door — without waiting on a relay
account and DNS propagation.

Why it **cannot be what is live at launch**: the entire reason ADR-005 exists is that
shared-host SMTP lands in spam, and in this product the ticket *is* the email. A ticket in
someone's spam folder is a person at a door without a ticket. DNS today confirms the gap —
`alvarocdev.com` publishes only `include:_spf.mail.hostinger.com`, with no DKIM for any
relay.

**This is a launch blocker, tracked in PLAN Gate 9.** Migrating is env-only (§ How the app
sends) plus the DNS records below; no code changes.

## Why not the hosting provider's SMTP

Shared-hosting SMTP routinely lands in spam — the problem ADR-005 exists to avoid. Use a transactional relay whose domain reputation is managed (Brevo, Resend, Postmark, SES…). Free tiers are enough for v1 volume; the binding constraint is the **daily cap**, because registrations spike on announcement day rather than spreading evenly.

## Domain authentication (the part that decides inbox vs spam)

1. **SPF** — publish/extend the TXT record so the relay is authorised to send for your domain.
2. **DKIM** — add the relay's CNAME/TXT keys and confirm the provider reports the domain as verified.
3. **DMARC** — at minimum `v=DMARC1; p=none; rua=mailto:you@yourdomain.com` to start receiving reports.
4. **Alignment** — `MAIL_FROM_ADDRESS` must be on the authenticated domain. A verified domain plus a `from` on a different one still fails alignment.

⚠️ If other systems already send from the same domain, **extend the existing SPF record, never add a second one** — two SPF records is itself an authentication failure.

## Pre-launch verification (owner-gated — hosted instance)

Needs the provider credentials, so it cannot be automated from the repo. Run once before launch (PLAN task 5.3 / Gate 5) and keep the evidence:

- [ ] Credentials in the production `.env` only — never committed.
- [ ] SPF, DKIM, DMARC published; provider reports the sending domain **verified**.
- [ ] Send a real registration to a **Gmail** address: lands in **Inbox**, not Promotions/Spam.
- [ ] Same for **Outlook/Hotmail**, and one more (Yahoo or a corporate domain).
- [ ] In Gmail, *Show original* → `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`.
- [ ] The **QR image renders** with images enabled, and **scans** with a phone camera to the same ticket.
- [ ] With images **blocked** (the default for many recipients), the **ticket link** is still visible and works.
- [ ] The email reads correctly in **es / en / pt**.
- [ ] Ticket arrives within a minute of registering (proves the scheduler cron drains the queue in production).
- [ ] Note the provider's daily cap and where to watch consumption.

## Known limitations

- **Daily free-tier cap.** Exhausting it delays ticket emails until the window resets; the queue keeps them and delivers later. The on-screen QR and the ticket link work throughout, so nobody is locked out of an event — but the organizer should know. Paying is the pressure valve.
- **No bounce handling.** The app knows the message was accepted by the relay, not that it was delivered. Bounce/complaint processing is post-v1.
