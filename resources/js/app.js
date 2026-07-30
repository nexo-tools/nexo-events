import Alpine from 'alpinejs';
import './nexo-ui.js'; // registers the shared chrome Alpine data (nexoTheme, nexoMenu)
import './nexo-beacon.js'; // no-op unless NEXO_BEACON_ENABLED renders its metas
import { initScanner } from './scanner.js'; // no-op unless the door scanner is on the page

window.Alpine = Alpine;
Alpine.start();

initScanner();

// Double-submit guard. Every form here writes a row — a registration, an
// event, a check-in — and on a slow connection nothing on screen said the
// first click had landed, so people clicked again and got two. Delegated from
// the document rather than wired per form, so a form added later is covered.
document.addEventListener('submit', (event) => {
    // A confirm() that was declined, or any handler that cancelled the submit:
    // the form is not going anywhere, so its button must stay usable.
    if (event.defaultPrevented) {
        return;
    }

    const submitter = event.submitter ?? event.target.querySelector('[type="submit"]');
    if (!submitter) {
        return;
    }

    // Deferred by a tick: the browser has already serialised the form and the
    // submitter's own name/value by the time this runs, so blocking the
    // control here stops the SECOND submission without cancelling the first.
    setTimeout(() => {
        submitter.setAttribute('aria-busy', 'true');
        submitter.disabled = true;
    }, 0);
});

// Back/forward cache serves the page in the state it was left in, busy button
// included — which would strand somebody on a form they can no longer submit.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }

    document.querySelectorAll('[aria-busy="true"]').forEach((element) => {
        element.removeAttribute('aria-busy');
        element.disabled = false;
    });
});
