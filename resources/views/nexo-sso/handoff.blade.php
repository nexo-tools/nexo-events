{{-- nexo-sso-client v2.3: a guest's way from GET /auth/nexo/enter to Nexo ID.
     A page of this origin instead of a 302 (NexoSsoController::handoff): WebKit
     mishandles a cross-origin redirect inside a history traversal, and «atrás»
     after «Cerrar sesión» comes through here. The meta refresh starts a new
     navigation and replaces this entry in the history. No script (nothing for
     the CSP to allow) and no stylesheet (the refresh waits for the load): the
     colors follow the shared `nexo-theme` cookie (nexo-ui; not encrypted), else
     the system. «Continue» is for a browser with automatic refresh turned off:
     it shows after 3 s (normally the page is gone by then) and goes back
     through `enter` with direct=1 — a plain redirect with a state of its own,
     so a tap while the refresh is still on its way never replays this page's
     single-use state. Identical in every tool. (AC-ENTER-7) --}}
@php
    $scheme = in_array(request()->cookie('nexo-theme'), ['light', 'dark'], true) ? request()->cookie('nexo-theme') : 'light dark';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="{{ $scheme }}">
    <meta http-equiv="refresh" content="0;url={{ $url }}">
    <title>{{ __('Signing in with Nexo ID…') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: Canvas; color: CanvasText; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        p { margin: 0; padding: 0 1rem; text-align: center; }
        p + p { margin-top: .5rem; } /* the link on its own line: while hidden it doesn't push the text off center */
        a { display: inline-block; min-height: 44px; line-height: 44px; padding: 0 4px; color: inherit; text-underline-offset: 2px; animation: nexo-reveal 0s 3s both; } /* a 44 px target, on its own line */
        @keyframes nexo-reveal { from { visibility: hidden; } }
    </style>
</head>
<body data-nexo-sso-handoff>
    <main>
        <p>{{ __('Signing in with Nexo ID…') }}</p>
        <p><a href="{{ $continue }}">{{ __('Continue') }}</a></p>
    </main>
</body>
</html>
