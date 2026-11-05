// "Confirm it's you" in place (owner 2026-11-05): a form marked data-recent-auth goes to a route
// behind recent.auth (RequireRecentAuth). When the last confirmation is too old and the person
// has a passkey, the passkey prompt opens on the page and the form is sent once it is confirmed,
// with what was typed and the button that was pressed: nothing to redo.
// Without a passkey, or when the browser cannot use one, or the ceremony fails, the form is sent
// as it is and the server's own flow takes over (the Confirm it's you page, then back here).
// The state comes from <meta name="redact-recent"> (components/layouts/head.blade.php).
import { Passkeys, UserCancelledError } from '@laravel/passkeys';

const meta = document.querySelector('meta[name="redact-recent"]');

if (meta) {
    // Until when the last confirmation counts, on this page's clock.
    let until = Date.now() + Number(meta.getAttribute('content') || 0) * 1000;
    const windowMs = Number(meta.dataset.window || 0) * 1000;

    const send = (form, submitter) => {
        // form.submit() skips the submit listeners (no second "Are you sure?") but drops the
        // pressed button: carry its name and value (e.g. refund=1) in a hidden field.
        if (submitter && submitter.name) {
            const carried = document.createElement('input');
            carried.type = 'hidden';
            carried.name = submitter.name;
            carried.value = submitter.value;
            form.appendChild(carried);
        }
        form.submit();
    };

    // Bubble phase on the document: runs after the form's own listeners, so a cancelled
    // "Are you sure?" (confirmSubmit) has already stopped it.
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-recent-auth') || event.defaultPrevented) {
            return;
        }
        if (Date.now() < until - 5000 || meta.dataset.passkeys !== '1' || !Passkeys.isSupported()) {
            return; // Still recent, or the server's flow (e-mail code) is the way.
        }

        event.preventDefault();
        const submitter = event.submitter;
        if (submitter) {
            submitter.disabled = true;
        }
        try {
            await Passkeys.verify({ routes: { options: meta.dataset.options, submit: meta.dataset.submit } });
            until = Date.now() + windowMs;
            send(form, submitter);
        } catch (error) {
            if (submitter) {
                submitter.disabled = false;
            }
            if (error instanceof UserCancelledError) {
                return; // The person closed the prompt: stay on the page, nothing sent.
            }
            send(form, submitter); // Anything else: the server's Confirm it's you page.
        }
    });
}
