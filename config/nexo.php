<?php

return [

    // Canonical "powered by" attribution shown in the shared footer. Env-overridable
    // so self-hosters can credit themselves; both are read by x-nexo-footer, which
    // falls back to alvarocdev.com when the label is unset.
    'attribution' => [
        'label' => env('NEXO_ATTRIBUTION_LABEL'),
        'url' => env('NEXO_ATTRIBUTION_URL'),
    ],

    // Help center contact target. A support URL (e.g. a form) wins; otherwise the
    // help page links a mailto: to this address.
    'support_url' => env('NEXO_SUPPORT_URL'),
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
];
