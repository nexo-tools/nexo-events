import Alpine from 'alpinejs';
import './nexo-ui.js'; // registers the shared chrome Alpine data (nexoTheme, nexoMenu)
import './nexo-beacon.js'; // no-op unless NEXO_BEACON_ENABLED renders its metas
import { initScanner } from './scanner.js'; // no-op unless the door scanner is on the page

window.Alpine = Alpine;
Alpine.start();

initScanner();

// Confirmation before a destructive submit: <form data-confirm="…">. It has to
// live here, not in an inline onsubmit="return confirm(…)": the CSP allows no
// inline script, so the browser refused those handlers and "Cancel event",
// "Release spot", "Remove access" and "Revoke" submitted without asking.
// Capture phase, so it runs before the double-submit guard below, which reads
// defaultPrevented to leave a declined form's button usable.
document.addEventListener('submit', (event) => {
    const message = event.target instanceof HTMLFormElement ? event.target.dataset.confirm : undefined;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
}, true);

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

// Copy-to-clipboard for read-only fields (the public link on "Ticket
// management", the private address on the ticket). Progressive enhancement:
// the button ships `hidden` and is only revealed where the Clipboard API
// exists; without it the text stays selectable by hand, which is what it was
// before. After a copy the label reads `data-copied` for two seconds, then
// goes back, so a second tap still says what it does.
if (navigator.clipboard) {
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.hidden = false;
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy]');
        if (!button) {
            return;
        }

        const field = document.getElementById(button.dataset.copy);
        if (!field) {
            return;
        }

        try {
            await navigator.clipboard.writeText((field.value ?? field.textContent ?? '').trim());

            button.dataset.label ??= button.textContent;
            button.textContent = button.dataset.copied ?? button.dataset.label;
            clearTimeout(Number(button.dataset.resetTimer));
            button.dataset.resetTimer = String(setTimeout(() => {
                button.textContent = button.dataset.label;
            }, 2000));
        } catch {
            field.select?.();
        }
    });
}
