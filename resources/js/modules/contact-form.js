// Contact form (/contact, public/contact.blade.php), as on cetome.com: the ALTCHA proof of work runs on
// page load in an invisible widget, so sending needs no click on a captcha.
//
// - Send pressed before the proof is ready: the button says "Sending…" and the form goes as soon as
//   the widget reports "verified".
// - The check fails: the widget is shown as a checkbox (the visible fallback); tick it, then send.
// - A proof expires after 10 minutes: a new one is solved in the background.
// - Without script the widget never runs: the server refuses the form and shows the widget.
// Plain listeners, no Alpine needed. The click is caught (not the submit), because the browser checks
// the fields, the widget's own included, before a submit event.

const form = document.querySelector('form[data-contact-form]');
const widget = form?.querySelector('altcha-widget');
const button = form?.querySelector('[data-contact-submit]');

if (form && widget && button) {
    const label = button.querySelector('[data-contact-label]');
    const idle = label ? label.textContent : '';
    let state = 'unverified';
    let waiting = false;
    let sending = false;

    const busy = (on) => {
        button.classList.toggle('is-busy', on);
        if (on) {
            button.setAttribute('aria-disabled', 'true');
        } else {
            button.removeAttribute('aria-disabled');
        }
        if (label) {
            label.textContent = on ? label.dataset.busy || idle : idle;
        }
    };

    // The person's fields must be valid; the widget's inner checkbox is not one of them.
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
        sending = true;
        busy(true);
        form.requestSubmit(button);
    };

    const reveal = () => {
        widget.removeAttribute('display');
        if (typeof widget.configure === 'function') {
            widget.configure({ display: 'standard' });
        }
    };

    widget.addEventListener('statechange', (event) => {
        state = (event.detail && event.detail.state) || state;
        if (state === 'verified' && waiting) {
            send();
        } else if (state === 'expired' && typeof widget.verify === 'function') {
            widget.verify();
        } else if (state === 'error') {
            waiting = false;
            busy(false);
            reveal();
        }
    });

    button.addEventListener('click', (event) => {
        if (sending || waiting) {
            event.preventDefault();

            return;
        }
        if (state === 'verified') {
            // Ready: the browser checks the fields, then the form goes.
            if (form.checkValidity()) {
                sending = true;
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
        if (state !== 'verifying' && typeof widget.verify === 'function') {
            widget.verify();
        }
    });

    // Back to this page from the history: start over.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            waiting = false;
            sending = false;
            state = 'unverified';
            busy(false);
            if (typeof widget.verify === 'function') {
                widget.verify();
            }
        }
    });
}
