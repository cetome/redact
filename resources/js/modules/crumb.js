// The product's name in the header, on phones (owner 2026-11-06): cut with "…" when long. A first
// tap shows the whole name under the bar instead of leaving the page, a second tap (or the name
// when it is not cut) opens the product; a tap anywhere else hides it again.
const phone = window.matchMedia('(max-width: 63.99rem)');

document.querySelectorAll('[data-crumb-name]').forEach((link) => {
    link.addEventListener('click', (event) => {
        if (!phone.matches || link.classList.contains('is-open')) {
            return;
        }
        if (link.scrollWidth > link.clientWidth + 1) {
            event.preventDefault();
            link.classList.add('is-open');
        }
    });
});

document.addEventListener('click', (event) => {
    document.querySelectorAll('[data-crumb-name].is-open').forEach((link) => {
        if (!link.contains(event.target)) {
            link.classList.remove('is-open');
        }
    });
});
