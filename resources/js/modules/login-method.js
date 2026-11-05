// Sign in (auth/login.blade.php, FAST's /login): Passkey or E-mail code, one pane at a time.
// The e-mail is shown first; the switch itself appears only where passkeys work (passkeyLogin).
// Plain listeners, no Alpine needed.

document.querySelectorAll('[data-login-methods]').forEach((card) => {
    const buttons = card.querySelectorAll('[data-method]');
    const panes = card.querySelectorAll('[data-method-pane]');

    const show = (method) => {
        buttons.forEach((button) => {
            const on = button.dataset.method === method;
            button.classList.toggle('is-active', on);
            button.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panes.forEach((pane) => {
            pane.hidden = pane.dataset.methodPane !== method;
        });
        const focus = card.querySelector(`[data-method-pane="${method}"] input[type="email"], [data-method-pane="${method}"] button`);
        focus?.focus();
    };

    buttons.forEach((button) => button.addEventListener('click', () => show(button.dataset.method)));

    // "Keep me signed in" sits above each button: ticking one ticks the other.
    const boxes = card.querySelectorAll('[data-remember]');
    boxes.forEach((box) => box.addEventListener('change', () => {
        boxes.forEach((other) => {
            other.checked = box.checked;
        });
    }));
});
