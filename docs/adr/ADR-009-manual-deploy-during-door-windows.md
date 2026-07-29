# ADR-009 — This tool deploys manually, against the ecosystem default

- **Date:** 2026-07-28
- **Status:** Accepted
- **Deviates from:** the ecosystem deploy standard (`PROMPT-NEXO.md` §2 — auto-deploy on every push to `main` since 2026-07-26)

## Context

Every sibling tool auto-deploys on push: `deploy.yml` runs `on: push` to `main` with
`concurrency: production`. It is a good default — it removes the "I forgot to deploy" class of
drift, and it forces the discipline of a green suite before pushing, because the push publishes.

Nexo Events has a failure mode the siblings do not: **door windows**. While an event is being
scanned at its entrance, the instance is on the critical path of a queue of real people standing
outside. A deploy takes the app down for a few seconds, drops in-flight requests, and re-caches
config and routes. A merge to `main` — a README typo fix, a dependency bump — could do that at
exactly the wrong moment, and the person holding the phone at the door has no way to know why the
scanner stopped working.

The reason was recorded in a comment in `deploy.yml` and in PLAN task 8.1, but never elevated to a
decision. A comment does not survive a normalization pass: an audit that checks "does deploy.yml
trigger on push?" against the standard flags this repo as non-compliant and someone eventually
"fixes" it.

## Decision

1. **`deploy.yml` stays `workflow_dispatch` only.** Deploys to production are triggered by hand
   (`gh workflow run deploy.yml --repo nexo-tools/nexo-events`), never by a merge.
2. **`scripts/deploy.sh` refuses to run inside a door window** (`events:door-guard`). Belt and
   braces: the manual trigger is the intent, the server-side guard is what actually protects the
   door if someone triggers it anyway.
3. **The rest of the standard applies unchanged**: `concurrency: production`, the same build and
   cache steps, the same post-deploy verification, and the same rule that the suite must be green
   before pushing — the push does not publish here, but the next deploy ships whatever is on
   `main`, so a red `main` is still a loaded gun.
4. **This deviation is a property of the product, not a preference.** Any other Nexo tool that
   ends up on the critical path of a live physical event inherits it; the rest keep auto-deploy.

## Alternatives considered

- **Auto-deploy plus the door guard alone** — the guard would block the deploy, so the push would
  silently not publish and `main` would drift ahead of production without anyone noticing. Worse
  than manual: it looks automatic and is not.
- **Auto-deploy with a maintenance window** (only outside typical event hours) — events happen at
  arbitrary times, including the ones that matter most (nights, weekends). No window is safe.
- **Blue-green or zero-downtime deploys** — the right answer, and unavailable on Hostinger shared
  hosting, which is the deliberate hosting choice of ADR-002. Revisit if the VPS ever happens.

## Consequences

- **The "I forgot to deploy" drift is real here** and has to be caught by the launch checklist and
  by `nexo-doctor` (which verifies live surfaces), not by the pipeline.
- **A hotfix needs a human**, including a security hotfix. Acceptable: the manual trigger is one
  command, and the door guard is exactly what you want to override consciously.
- The ecosystem standard now describes this as a recognized exception rather than a gap, so a
  future normalization does not "correct" it back into auto-deploy.
