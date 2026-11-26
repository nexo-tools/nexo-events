<?php

// NEXO_SSO_* env contract (nexo-sso-client template v2.1). Identical across every
// Nexo tool EXCEPT the keys marked ADAPTATION POINT (home and the silent
// exclusions), which each tool fills with its own routes.
return [

    // Master switch. Off (default) = standalone mode: no SSO routes, no network,
    // no session guard, and the auth mode behaves as `local`. (AC-CFG-1)
    'enabled' => (bool) env('NEXO_SSO_ENABLED', false),

    // Base URL of the Nexo ID instance, e.g. https://nexoid.alvarocdev.com
    'issuer' => rtrim((string) env('NEXO_SSO_ISSUER', ''), '/'),

    // Public client id (uuid) issued by `php artisan nexo:sso-client` on the provider.
    'client_id' => (string) env('NEXO_SSO_CLIENT_ID', ''),

    // Requested scopes. openid is required for the id_token.
    'scopes' => 'openid profile email',

    // ADAPTATION POINT — path (same origin) of this tool's panel: where a login
    // lands when there is no saved destination, and where /auth/nexo/enter goes
    // when its `to` is missing or unsafe. Nexo Short: '/panel'.
    // Nexo Events: the organizer's panel, «Tus eventos» (route `dashboard`).
    'home' => '/app',

    // Where Nexo ID returns the browser after a central (RP-initiated) logout.
    // Empty (default) = <APP_URL>/auth/nexo/signed-out, the "you signed out of
    // all of Nexo" screen. It MUST be registered as a redirect URI of this
    // client on the provider (`nexo:sso-client:update --add-redirect=`), or the
    // provider refuses it and shows its own page instead (anti open-redirect).
    // A «Cerrar sesión» whose page lost its session (419, or a guest) goes to
    // end_session without a hint, with client_id: Nexo ID asks for confirmation
    // and then uses this same URI.
    'post_logout_redirect_uri' => (string) env('NEXO_SSO_POST_LOGOUT_REDIRECT_URI', ''),

    /*
    | Auth mode of this tool (local | transition | sso). See the README.
    |   local       self-host default: local password login and registration, plus
    |               "Continue with Nexo ID" when SSO is on (and POST /auth/nexo/link
    |               for a password session that wants to join a Nexo ID).
    |   transition  Nexo ID first; email+password only for accounts not yet linked
    |               to Nexo ID, no local registration, no local "remember me"; a
    |               password session of an account already linked is closed.
    |               Joining an account is explicit: «Link my account» (POST
    |               /auth/nexo/link, any email), and a Nexo ID that matches no
    |               account asks «First time in <App>?» (/auth/nexo/new-account)
    |               before creating one. From the day after
    |               NEXO_AUTH_TRANSITION_UNTIL it behaves as sso.
    |   sso         only Nexo ID: local login, registration and reset answer 404,
    |               and a session Nexo ID does not govern is closed.
    | With SSO disabled the effective mode is always `local` (an instance can
    | never lock itself out by turning SSO off).
    */
    'auth_mode' => (string) env('NEXO_AUTH_MODE', 'local'),

    // Last day (YYYY-MM-DD, app timezone) of the `transition` mode, inclusive.
    'transition_until' => (string) env('NEXO_AUTH_TRANSITION_UNTIL', ''),

    // Silent SSO (OIDC prompt=none): on guest PUBLIC pages, try once per session
    // to pick up an existing Nexo ID session with no UI and no clicks. Rides the
    // master switch; this is the per-tool kill-switch. (AC-SILENT-1)
    'silent' => (bool) env('NEXO_SSO_SILENT', true),

    // ADAPTATION POINT — surfaces where neither the silent attempt nor the
    // periodic re-validation may bounce the browser (public storefronts,
    // host-isolated pages, machine endpoints): path patterns (Request::is) in
    // `silent_excluded`, route names — Str::is wildcards allowed — in
    // `silent_excluded_routes` (the only way to express slug catch-alls like
    // /{username}). The /auth/nexo/* routes and the auth screens (login,
    // register, password.*, verification.*) are always excluded by the code.
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
    // The same list keeps the re-validation off them: an organizer who opens
    // their own event page is never bounced there either.
    // `fallback` is the 404 of an unmatched URL (the last route of web.php,
    // chrome v2); the template already skips fallback routes, it is listed so
    // HeaderEverywhereTest reads the intent here too.
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
        'fallback',
    ],

    // Back-channel logout (OIDC Back-Channel Logout 1.0): Nexo ID tells this tool,
    // server to server, which session ended. Kill-switch: false = the endpoint
    // answers 501 and the session guard stops checking revocations.
    'backchannel' => (bool) env('NEXO_SSO_BACKCHANNEL', true),

    // Periodic re-validation: an SSO session whose last check is older than this
    // many minutes bounces once through prompt=none on its next GET page
    // navigation (never POST, never JSON). 0 = off. Fail-open: if Nexo ID does
    // not answer, nobody is signed out and the check is retried later.
    'recheck_minutes' => (int) env('NEXO_SSO_RECHECK_MINUTES', 15),

    // Reachability probe before a re-validation bounce: a good answer is cached
    // for probe_ttl seconds, a failure skips bounces for probe_retry seconds.
    'probe_timeout' => 2,
    'probe_ttl' => 60,
    'probe_retry' => 300,

    // How long a back-channel revocation is kept (hours). Longer than any SSO
    // session can live without a re-validation; `nexo-sso:prune` deletes the
    // expired ones (daily, scheduler).
    'revocation_ttl_hours' => 24,

    // HTTP timeout (seconds) for every provider call — keeps degradation snappy. (AC-DEGRADE-2)
    'timeout' => (int) env('NEXO_SSO_TIMEOUT', 5),

    // Cache TTLs (seconds) for the discovery document and JWKS. (AC-CFG-2) A token
    // whose signature or kid doesn't match the cached JWKS re-fetches it once
    // (key rotation), at most once a minute. (AC-JWKS)
    'discovery_ttl' => 3600,
    'jwks_ttl' => 3600,
];
