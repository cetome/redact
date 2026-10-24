// Sign-in, passkeys and the sign-up proof of work.
//
// Passkey ceremonies use @laravel/passkeys against the laravel/passkeys routes.
// Labels and messages come from data-* attributes on the component root, so
// all copy stays in lang/*/auth.php. Alpine CSP build: logic lives in methods.
import { Passkeys, PasskeyExistsError, UserCancelledError } from '@laravel/passkeys';

function go(response, fallback) {
    window.location.assign((response && response.redirect) || fallback || '/');
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // Login page: passkey autofill on the e-mail field (conditional UI,
    // autocomplete="username webauthn") plus an explicit button.
    Alpine.data('passkeyLogin', () => ({
        supported: false,
        busy: false,
        error: '',

        async init() {
            this.supported = Passkeys.isSupported();
            if (!this.supported) {
                return;
            }
            try {
                if (await Passkeys.isAutofillSupported()) {
                    const response = await Passkeys.autofill({ remember: () => this.remember() });
                    if (response) {
                        go(response);
                    }
                }
            } catch {
                // Autofill is opportunistic and starts without any action from the person:
                // never show an error for it (unsupported authenticator, wrong origin in
                // development, aborted when the page changes). The button still works and
                // reports its own errors.
            }
        },

        async signIn() {
            this.error = '';
            this.busy = true;
            try {
                Passkeys.cancel();
                go(await Passkeys.verify({ remember: () => this.remember() }));
            } catch (error) {
                if (!(error instanceof UserCancelledError)) {
                    this.error = this.$root.dataset.error;
                }
            } finally {
                this.busy = false;
            }
        },

        // "Keep me signed in", shared with the e-mail form (read when the passkey is used).
        remember() {
            const box = this.$root.querySelector('[data-remember]');
            return Boolean(box && box.checked);
        },

        get unsupported() {
            return !this.supported;
        },
    }));

    // "Confirm it's you" with a passkey (laravel/passkeys confirm endpoints).
    Alpine.data('passkeyConfirm', () => ({
        busy: false,
        error: '',

        async confirm() {
            this.error = '';
            this.busy = true;
            try {
                const response = await Passkeys.verify({
                    routes: {
                        options: this.$root.dataset.optionsUrl,
                        submit: this.$root.dataset.submitUrl,
                    },
                });
                go(response, this.$root.dataset.fallback);
            } catch (error) {
                if (!(error instanceof UserCancelledError)) {
                    this.error = this.$root.dataset.error;
                }
            } finally {
                this.busy = false;
            }
        },
    }));

    // Add a passkey (account page, passkey suggestion after sign-in).
    Alpine.data('passkeyRegister', () => ({
        name: '',
        busy: false,
        error: '',
        supported: true,

        init() {
            this.supported = Passkeys.isSupported();
            this.name = this.$root.dataset.defaultName || '';
        },

        async add() {
            this.error = '';
            this.busy = true;
            try {
                await Passkeys.register({ name: (this.name || '').trim() || this.$root.dataset.defaultName });
                window.location.assign(this.$root.dataset.redirect || window.location.href);
            } catch (error) {
                if (error instanceof PasskeyExistsError) {
                    this.error = this.$root.dataset.exists;
                } else if (!(error instanceof UserCancelledError)) {
                    this.error = this.$root.dataset.error;
                }
            } finally {
                this.busy = false;
            }
        },

        get unsupported() {
            return !this.supported;
        },
    }));
});

// ALTCHA widget (self-hosted, strict-CSP build): loaded only on pages that use it.
if (document.querySelector('altcha-widget')) {
    Promise.all([
        import('altcha/external'),
        import('altcha/altcha.css'),
        import('altcha/workers/pbkdf2?worker'),
        document.documentElement.lang.startsWith('fr') ? import('altcha/i18n/fr-fr') : Promise.resolve(),
    ]).then(([, , worker]) => {
        const Pbkdf2Worker = worker.default;
        ['PBKDF2/SHA-256', 'PBKDF2/SHA-384', 'PBKDF2/SHA-512'].forEach((name) => {
            globalThis.$altcha.algorithms.set(name, () => new Pbkdf2Worker());
        });
    });
}
