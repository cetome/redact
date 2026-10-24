// Shared UI helpers: theme switch, confirm-before-submit, dismissible flashes.

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // Light/dark switch. The initial class is set before paint by /js/theme-init.js.
    Alpine.data('themeToggle', () => ({
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try {
                localStorage.setItem('theme', this.dark ? 'dark' : 'light');
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
