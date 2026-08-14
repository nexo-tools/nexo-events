<?php

return [

    // Canonical "powered by" attribution shown in the shared footer. Env-overridable
    // so self-hosters can credit themselves; both are read by x-nexo-footer, which
    // falls back to alvarocdev.com when the label is unset.
    'attribution' => [
        'label' => env('NEXO_ATTRIBUTION_LABEL', 'made with Nexo Events'),
        'url' => env('NEXO_ATTRIBUTION_URL'),
    ],

    // Help center contact target. A support URL (e.g. a form) wins; otherwise the
    // help page links a mailto: to this address.
    // Who answers for THIS instance on the legal pages. No default on purpose:
    // a third party that clones the repo must not publish the upstream author's
    // details, so the section is simply not rendered until these are set.
    'legal' => [
        'operator' => env('NEXO_LEGAL_OPERATOR'),
        'contact' => env('NEXO_LEGAL_CONTACT'),
    ],

    'support_url' => env('NEXO_SUPPORT_URL'),
    // Mail al operador cuando algo revienta (nexo-ops). Off por default: una
    // instancia recién clonada no debería empezar a mandar correo sin que su
    // operador lo decida. Dedupe de 15 min por excepción, kill-switch por env.
    'ops_mail' => env('NEXO_OPS_MAIL', false),

    'support_email' => env('NEXO_SUPPORT_EMAIL', 'hola@alvarocdev.com'),

    // Cookieless beacon emitter (ecosystem analytics, ADR-007 §7). OFF by
    // default: a self-hosted instance must never phone home unasked. When on,
    // nexo-beacon.js sends a pageview to the hub, respects Do Not Track and
    // carries no PII. Activation is owner-gated (PLAN 8.6) and also needs the
    // hub to allow-list this origin.
    'beacon' => [
        'enabled' => (bool) env('NEXO_BEACON_ENABLED', false),
        'endpoint' => (string) env('NEXO_BEACON_ENDPOINT', 'https://nexotools.alvarocdev.com/beacon'),
    ],

    // Integration API v1 (ADR-010). OFF by default: a standalone install must
    // behave exactly as it did before this existed — with the flag false the
    // /api/v1 routes are registered but answer 404, and the organizer panel
    // shows no trace of them. The routes are registered either way on purpose:
    // conditional registration is what `route:cache` bakes in, and this project
    // has already paid for that once with SSO (DEPLOYMENT.md).
    'api' => [
        'enabled' => (bool) env('NEXO_API_ENABLED', false),

        // Per token, per minute. Reads are cheap and a panel that shows a live
        // counter will poll; writes create events and queue mail. Tunable
        // because a limit nobody can raise at 3am is a limit that will be hit
        // at 3am.
        'rate_read' => (int) env('NEXO_API_RATE_READ', 60),
        'rate_write' => (int) env('NEXO_API_RATE_WRITE', 20),
    ],

    // Deploy freeze window around an event's start (see events:door-guard).
    // There is no `ends_at` column, so the window is inferred: long enough
    // before for doors opening, long enough after to cover the event itself.
    'door_guard' => [
        'minutes_before' => (int) env('NEXO_DOOR_GUARD_BEFORE', 120),
        'minutes_after' => (int) env('NEXO_DOOR_GUARD_AFTER', 360),
    ],
];
