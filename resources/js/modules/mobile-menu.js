// The phone menu (components/ui/mobile-menu): a details element, so it opens without script. Here:
// Escape closes it, the page behind stops scrolling while it is open, and it closes
// when the screen grows past the phone width.
document.querySelectorAll('[data-mobile-menu]').forEach((menu) => {
    const sync = () => document.documentElement.classList.toggle('a-mobile-open', menu.open);
    menu.addEventListener('toggle', sync);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
        }
    });
    window.matchMedia('(min-width: 64rem)').addEventListener('change', (event) => {
        if (event.matches) {
            menu.open = false;
        }
    });
    sync();
});
