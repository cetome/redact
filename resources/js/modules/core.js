// Shared UI helpers: theme switch, confirm-before-submit, dismissible flashes.

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // Light/dark switch. The initial class is set before paint by /js/theme-init.js. Signed in, the
    // choice is the account's (Account > Accessibility, PUT /account/theme), else this browser's.
    Alpine.data('themeToggle', () => ({
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            const theme = this.dark ? 'dark' : 'light';
            document.documentElement.classList.toggle('dark', this.dark);
            const account = document.querySelector('meta[name="redact-theme"]');
            if (account) {
                account.setAttribute('content', theme);
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
                fetch(account.dataset.url, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ theme }),
                    credentials: 'same-origin',
                }).catch(() => {
                    // offline: the choice lasts for this page only
                });
                return;
            }
            try {
                localStorage.setItem('theme', theme);
            } catch {
                // storage blocked: the choice lasts for this page only
            }
        },
        get label() {
            return this.dark ? this.$el.dataset.lightLabel : this.$el.dataset.darkLabel;
        },
    }));

    // <form x-data="confirmSubmit" data-confirm="Are you sure?" @submit="check">
    Alpine.data('confirmSubmit', () => ({
        check(event) {
            if (!window.confirm(this.$el.dataset.confirm)) {
                event.preventDefault();
            }
        },
    }));

    Alpine.data('dismissible', () => ({
        open: true,
        close() {
            this.open = false;
        },
    }));
});
