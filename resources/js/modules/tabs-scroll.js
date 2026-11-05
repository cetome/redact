// Tab bars wider than the screen (phones: account, organisation and console tabs): the selected tab
// is scrolled into view, never cut at the edge. Only the bar scrolls, never the page.
const reveal = () => {
    document.querySelectorAll('.a-tabs').forEach((bar) => {
        const current = bar.querySelector('[aria-current="page"]');
        if (!current || bar.scrollWidth <= bar.clientWidth) {
            return;
        }
        const left = current.offsetLeft - bar.offsetLeft;
        const right = left + current.offsetWidth;
        if (left < bar.scrollLeft || right > bar.scrollLeft + bar.clientWidth) {
            bar.scrollLeft = Math.max(0, left - (bar.clientWidth - current.offsetWidth) / 2);
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', reveal);
} else {
    reveal();
}
