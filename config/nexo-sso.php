<?php

// NEXO_SSO_* env contract — identical across every Nexo tool (SPEC-client, nexo-id repo).
return [

    // Master switch. Off (default) = standalone mode: no SSO routes, no network. (AC-CFG-1)
    'enabled' => (bool) env('NEXO_SSO_ENABLED', false),

    // Base URL of the Nexo ID instance, e.g. https://nexoid.alvarocdev.com
    'issuer' => rtrim((string) env('NEXO_SSO_ISSUER', ''), '/'),

    // Public client id (uuid) issued by `php artisan nexo:sso-client` on the provider.
    'client_id' => (string) env('NEXO_SSO_CLIENT_ID', ''),

    // Requested scopes. openid is required for the id_token.
    'scopes' => 'openid profile email',

    // Where Nexo ID returns the browser after a central (RP-initiated) logout.
    // MUST be registered as a redirect URI of this client on the provider, or the
    // provider refuses it and shows its own "signed out" page (anti open-redirect).
    // Empty = no post-logout redirect (the provider's signed-out page is the end).
    'post_logout_redirect_uri' => (string) env('NEXO_SSO_POST_LOGOUT_REDIRECT_URI', ''),

    // Silent SSO (OIDC prompt=none): on guest pages, try once per session to
    // pick up an existing Nexo ID session with no UI and no clicks. Rides the
    // master switch; this is the per-tool kill-switch. (AC-SILENT-1)
    'silent' => (bool) env('NEXO_SSO_SILENT', true),

    // Surfaces where the silent attempt must NOT fire (public storefronts,
    // host-isolated pages, machine endpoints): path patterns (Request::is) in
    // `silent_excluded`, route names — Str::is wildcards allowed — in
    // `silent_excluded_routes`. The /auth/nexo/* routes are always excluded by
    // the middleware itself.
    //
    // Nexo Events adaptation — the attendee surfaces. Silent SSO exists for
    // ORGANIZERS (they have accounts); attendees never do (ADR-003), so a
    // bounce through Nexo ID buys them nothing and can only hurt:
    //   - t/{token}   the ticket itself, opened at the door, often on a phone
    //                 with bad signal. A redirect here is a person stuck
    //                 outside the venue. This is the one that must never break.
    //   - e/{slug}    the public event page — the link attendees receive and
    //                 where they register. First impression of the product.
    //   - up          health endpoint (uptime monitors, deploy smoke checks).
    //   - robots/sitemap  crawler surfaces (arrive cookieless anyway, but
    //                 excluded explicitly so a redirect can never reach them).
    // Both a path pattern and a route name are listed for the two attendee
    // surfaces: the paths keep protecting them even if a route is later renamed.
    'silent_excluded' => [
        'up',
        'sitemap.xml',
        'robots.txt',
        'e/*',
        't/*',
    ],
    'silent_excluded_routes' => [
        'public.*',
        'ticket.*',
    ],

    // HTTP timeout (seconds) for every provider call — keeps degradation snappy. (AC-DEGRADE-2)
    'timeout' => (int) env('NEXO_SSO_TIMEOUT', 5),

    // Cache TTLs (seconds) for the discovery document and JWKS. (AC-CFG-2)
    'discovery_ttl' => 3600,
    'jwks_ttl' => 3600,
];
