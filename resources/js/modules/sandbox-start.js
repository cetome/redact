// Opening a sandbox without a click on a captcha: the ALTCHA proof of work runs on page load in an
// invisible widget, and the form goes as soon as it is ready. Plain listeners, no Alpine needed
// (the home form's Alpine root is the scoping, scopingLive).
//
// <form data-sandbox-start [data-auto-submit]>
//   <button type="submit" data-start-button>… <span data-start-label data-busy-label="Preparing…">Start</span></button>
//   <altcha-widget auto="onload" display="invisible" …>          (guest/_altcha-auto.blade.php)
//   <p data-start-status data-verifying data-ready data-preparing data-failed><span role="status" data-start-status-text>
//
// - Start pressed before the proof is ready: the button shows "Preparing your sandbox…" and the form
//   is sent when the widget reports "verified".
// - data-auto-submit (/try): the form is sent by itself once the proof is ready.
// - A proof expires after 10 minutes: a new one is solved in the background.
// - The check fails: the button is a normal button again and says so; pressing it tries again.
// - Without script the widget never runs: the server refuses the form without a proof (and says so).
// - A start button may sit outside the form (<button form="tool" data-start-button>, the home tour).

function setup(form) {
    const widget = form.querySelector('altcha-widget');
    const buttons = [...form.querySelectorAll('[data-start-button]')];
    if (form.id) {
        buttons.push(...document.querySelectorAll(`[data-start-button][form="${form.id}"]`));
    }
    if (!widget || buttons.length === 0) {
        return;
    }
    let button = buttons[0];
    const status = form.querySelector('[data-start-status]');
    const statusText = form.querySelector('[data-start-status-text]');
    const labels = buttons.map((b) => b.querySelector('[data-start-label]'));
    const idleLabels = labels.map((l) => (l ? l.textContent : ''));

    let state = 'unverified';
    let waiting = form.hasAttribute('data-auto-submit');

    const say = (key) => {
        if (status && statusText && status.dataset[key]) {
            status.dataset.state = key;
            statusText.textContent = status.dataset[key];
        }
    };

    const busy = (on) => {
        buttons.forEach((b, i) => {
            b.classList.toggle('is-busy', on);
            if (on) {
                b.setAttribute('aria-disabled', 'true');
            } else {
                b.removeAttribute('aria-disabled');
            }
            if (labels[i]) {
                labels[i].textContent = on ? labels[i].dataset.busyLabel || idleLabels[i] : idleLabels[i];
            }
        });
    };

    // The form's own fields (name…) must be valid; the widget's inner checkbox is not one of them.
    const fieldsValid = () => {
        for (const field of form.elements) {
            if (!widget.contains(field) && typeof field.checkValidity === 'function' && !field.checkValidity()) {
                field.reportValidity();

                return false;
            }
        }

        return true;
    };

    const send = () => {
        waiting = false;
        if (!fieldsValid()) {
            busy(false);
            say('ready');

            return;
        }
        say('preparing');
        busy(true);
        form.requestSubmit(button);
    };

    const verify = () => {
        if (typeof widget.verify === 'function') {
            widget.verify();
        }
    };

    widget.addEventListener('statechange', (event) => {
        state = (event.detail && event.detail.state) || state;
        if (state === 'verified') {
            if (waiting) {
                send();
            } else {
                say('ready');
            }
        } else if (state === 'verifying') {
            say(waiting ? 'preparing' : 'verifying');
        } else if (state === 'expired') {
            verify();
        } else if (state === 'error') {
            waiting = false;
            busy(false);
            say('failed');
        }
    });

    const onClick = (event) => {
        button = event.currentTarget;
        if (button.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();

            return;
        }
        if (state === 'verified') {
            // Ready: the click submits; show that something happens (if the fields are valid).
            if (form.checkValidity()) {
                say('preparing');
                busy(true);
            }

            return;
        }
        event.preventDefault();
        if (!fieldsValid()) {
            return;
        }
        waiting = true;
        busy(true);
        say('preparing');
        if (state !== 'verifying') {
            verify();
        }
    };
    buttons.forEach((b) => b.addEventListener('click', onClick));

    // Back to this page from the history: the widget starts over, so does the button.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            waiting = false;
            state = 'unverified';
            busy(false);
            verify();
        }
    });

    if (waiting) {
        busy(true);
        say('preparing');
    } else {
        say('verifying');
    }
}

document.querySelectorAll('form[data-sandbox-start]').forEach(setup);

// "Try REDact" in the hero: a smooth scroll to the tool (#start), focus on its first card.
// Without script the link is a plain anchor.
document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-scroll-start]');
    const target = link && document.getElementById('start');
    if (!target) {
        return;
    }
    event.preventDefault();
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    target.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'start' });
    history.replaceState(null, '', '#start');
    const first = target.querySelector('.a-scope-card input[type="checkbox"]');
    first?.focus({ preventScroll: true });
});

// "Try REDact" and Dashboard for a visitor (x-try-link: a[data-sandbox-open]) open the sandbox from the
// page itself, with the hidden form#sandbox-open (guest/_open-form): no /try page in between. The proof
// is solved on demand, from the first hover or focus of such a link, so it is usually ready at the
// click; the link says "Opening…" until the dashboard loads. A failed check falls back to /try (the
// link's own href), which explains and offers the button. Modified clicks (new tab) keep the link.
const openForm = document.getElementById('sandbox-open');
if (openForm) {
    const widget = openForm.querySelector('altcha-widget');
    const submit = openForm.querySelector('[data-start-button]');
    // "See a demo" (x-demo-link) sends the same form elsewhere: its data-sandbox-action (/try/demo).
    const defaultAction = openForm.getAttribute('action');
    let pending = null;
    let warmed = false;

    const warm = () => {
        if (!warmed && widget && typeof widget.verify === 'function') {
            warmed = true;
            widget.verify();
        }
    };

    widget?.addEventListener('statechange', (event) => {
        const state = event.detail && event.detail.state;
        if (state === 'expired') {
            warmed = false;
        } else if (state === 'error' && pending) {
            window.location.href = pending.href;
        }
    });

    document.addEventListener('pointerover', (event) => {
        if (event.target.closest?.('a[data-sandbox-open]')) {
            warm();
        }
    });
    document.addEventListener('focusin', (event) => {
        if (event.target.closest?.('a[data-sandbox-open]')) {
            warm();
        }
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-sandbox-open]');
        if (!link || !submit || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        if (pending) {
            return;
        }
        pending = link;
        link.setAttribute('aria-busy', 'true');
        link.classList.add('is-opening');
        const label = openForm.dataset.opening;
        if (label && !link.querySelector('*')) {
            link.textContent = label;
        }
        openForm.setAttribute('action', link.dataset.sandboxAction || defaultAction);
        // setup() above sends the form now if the proof is ready, or as soon as it is.
        submit.click();
    });

    // Back from the dashboard with the history: the page comes back as it was left ("Opening…").
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && pending) {
            window.location.reload();
        }
    });
}
