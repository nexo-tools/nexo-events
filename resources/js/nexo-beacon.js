// Nexo beacon emitter — cookieless, opt-in pageview ping for the ecosystem.
//
// IDENTICAL IN THE 6 APPS (templates/nexo-ui/beacon/, byte for byte): copy it
// to resources/js/nexo-beacon.js and import it once from app.js. It is a no-op
// unless the page renders the beacon meta tags — which an app only outputs when
// its own beacon is enabled (partials/beacon.blade.php) — and it ALWAYS respects
// Do Not Track / Global Privacy Control. CSP-safe: no inline script, it ships
// inside the app bundle; the page's connect-src must allow the hub's origin.
//
// How it is sent (v2.1, 2026-11-26). The first version used
// navigator.sendBeacon with a JSON Blob, and every page of every
// tool failed with a CORS error in Chromium (H-nexoid-15): a JSON body is not a
// "simple" request, so the browser sent a preflight, and sendBeacon always sends
// credentials, which the hub (rightly) does not allow. Now:
//   - text/plain body: a CORS-safelisted type, so there is no preflight at all
//     (the hub still parses it as JSON);
//   - credentials: 'omit': the hub needs no cookie and must never see one;
//   - keepalive: true: the request outlives the page, as sendBeacon's did.
// Errors are swallowed: analytics must never break the page.
//
// Blade wiring (partials/beacon.blade.php):
//   <meta name="nexo:beacon-endpoint" content="https://nexotools.alvarocdev.com/beacon">
//   <meta name="nexo:beacon-origin" content="nexolinks">
//   <meta name="nexo:beacon-ref" content="nexoshort">   {{-- optional, alvarocdev attribution --}}

(function () {
    function meta(name) {
        const el = document.querySelector(`meta[name="${name}"]`);
        return el ? el.getAttribute('content') : null;
    }

    function send() {
        // Respect Do Not Track / Global Privacy Control before anything else.
        const dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;
        if (dnt === '1' || dnt === 'yes' || navigator.globalPrivacyControl === true) return;

        const endpoint = meta('nexo:beacon-endpoint');
        const origin = meta('nexo:beacon-origin');
        // No metas => this instance did not opt in; do nothing.
        if (!endpoint || !origin || typeof window.fetch !== 'function') return;

        const payload = { origin, path: location.pathname, event: 'pageview' };
        const ref = meta('nexo:beacon-ref');
        if (ref) payload.ref = ref;

        try {
            window.fetch(endpoint, {
                method: 'POST',
                mode: 'cors',
                credentials: 'omit',
                keepalive: true,
                headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                body: JSON.stringify(payload),
            }).catch(() => { /* analytics must never break the page */ });
        } catch (e) {
            /* analytics must never break the page */
        }
    }

    if (document.readyState === 'complete') {
        send();
    } else {
        window.addEventListener('load', send, { once: true });
    }
})();
