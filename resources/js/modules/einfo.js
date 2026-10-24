// E.Info page (resources/views/einfo/index.blade.php): one object at a time. The page works
// without this script (links, a POST form for the object select); the script adds:
//
//   [data-einfo-search]   live search across every object (shown by this script): the list keeps the
//                         objects with matching fields ([data-einfo-item] data-fields) and shows their
//                         count ([data-einfo-hits]); the card hides its fields that do not match. The
//                         search is kept for the page (version and part, [data-einfo] data-scope) in
//                         sessionStorage, never in the address, and posted with the form ([data-einfo-q]).
//                         Previous / next ([data-einfo-prev], [data-einfo-next]) follow the list.
//   [data-einfo-form]     "n of N documented" and the Missing / Documented badge follow the typing;
//                         leaving the page with unsaved text asks first.
//   [data-einfo-reveal]   in "Missing only", shows or hides the documented fields of the card.
//   [data-einfo-pick]     the object select (phones) opens the object (its option's data-href) as soon
//                         as it changes.

function normalise(text) {
    return (text || '').toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function words(value) {
    return normalise(value).split(/\s+/).filter(Boolean);
}

function matches(haystack, list) {
    return list.every((w) => haystack.includes(w));
}

// The search of this page, for the next object (a per-viewer convenience: may be unavailable).
function stored(scope, value) {
    const key = 'einfo.q.' + scope;
    try {
        if (value === undefined) {
            return window.sessionStorage.getItem(key) || '';
        }
        if (value) {
            window.sessionStorage.setItem(key, value);
        } else {
            window.sessionStorage.removeItem(key);
        }
    } catch {
        // Storage blocked: the search simply starts empty on the next object.
    }

    return '';
}

function initEinfo(root) {
    const page = root.closest('main') || document;
    const search = page.querySelector('[data-einfo-search]');
    const nav = root.querySelector('[data-einfo-objects]');
    const card = root.querySelector('[data-einfo-card]');
    const form = root.querySelector('[data-einfo-form]');
    const missingOnly = root.dataset.show === 'missing';
    const scope = root.dataset.scope || '';
    const items = nav ? [...nav.querySelectorAll('[data-einfo-item]')] : [];
    const fieldIndex = new Map(items.map((item) => [item, JSON.parse(item.dataset.fields || '[]')]));
    let dirty = false;

    function refreshPrevNext() {
        const at = items.findIndex((item) => item.querySelector('[aria-current]'));
        if (at < 0) {
            return;
        }
        const shown = (item) => !item.hidden;
        const before = items.slice(0, at).reverse().find(shown);
        const after = items.slice(at + 1).find(shown);
        [[page.querySelector('[data-einfo-prev]'), before], [page.querySelector('[data-einfo-next]'), after]].forEach(([button, item]) => {
            if (!button) {
                return;
            }
            const link = item ? item.querySelector('a') : null;
            if (link) {
                button.setAttribute('href', link.getAttribute('href'));
                button.removeAttribute('aria-disabled');
            } else {
                button.removeAttribute('href');
                button.setAttribute('aria-disabled', 'true');
            }
        });
    }

    function applySearch() {
        const value = search ? search.value.trim() : '';
        const list = words(value);
        let fields = 0;
        let objects = 0;

        items.forEach((item) => {
            const hits = list.length ? fieldIndex.get(item).filter((text) => matches(text, list)).length : 0;
            const current = !!item.querySelector('[aria-current]');
            const complete = item.dataset.complete === '1';
            const visible = (!missingOnly || !complete) && (!list.length || hits > 0);
            item.hidden = !visible && !current;
            if (visible) {
                objects++;
                fields += hits;
            }
            const badge = item.querySelector('[data-einfo-hits]');
            if (badge) {
                badge.textContent = String(hits);
                badge.hidden = !list.length;
            }
        });
        nav?.querySelectorAll('[data-einfo-group]').forEach((group) => {
            group.hidden = group.querySelectorAll('[data-einfo-item]:not([hidden])').length === 0;
        });
        const empty = nav?.querySelector('[data-einfo-empty]');
        if (empty) {
            empty.hidden = objects > 0;
            if (list.length) {
                empty.textContent = empty.dataset.searchText;
            }
        }

        // The fields of the card (their current text counts too).
        let here = 0;
        card?.querySelectorAll('[data-einfo-field]').forEach((field) => {
            const textarea = field.querySelector('textarea');
            const hit = !list.length || matches(field.dataset.search + ' ' + normalise(textarea ? textarea.value : ''), list);
            field.hidden = !hit;
            here += hit ? 1 : 0;
        });
        const nomatch = card?.querySelector('[data-einfo-nomatch]');
        if (nomatch) {
            nomatch.hidden = !list.length || here > 0;
        }

        const count = page.querySelector('[data-einfo-count]');
        if (count) {
            count.textContent = list.length ? count.dataset.template.replace(':fields', fields).replace(':objects', objects) : '';
        }
        const clear = page.querySelector('.a-toolbar [data-einfo-clear]');
        if (clear) {
            clear.hidden = !list.length;
        }

        // Keep the search for the next object of this page (and post it with the form).
        stored(scope, value);
        page.querySelectorAll('[data-einfo-q]').forEach((input) => { input.value = value; });
        refreshPrevNext();

        // Nothing here: offer the first object that matches.
        const first = card?.querySelector('[data-einfo-first]');
        if (first) {
            const item = items.find((candidate) => !candidate.hidden && !candidate.querySelector('[aria-current]'));
            const link = item ? item.querySelector('a') : null;
            first.hidden = !link || here > 0;
            if (link) {
                first.setAttribute('href', link.getAttribute('href'));
                first.textContent = first.dataset.template.replace(':object', link.title);
            }
        }
    }

    let timer = null;
    search?.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(applySearch, 120);
    });
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && search.value !== '') {
            event.preventDefault();
            search.value = '';
            applySearch();
        }
    });
    // Enter: open the first matching object when the one shown has no match, else stay.
    search?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        applySearch();
        if (card?.querySelector('[data-einfo-field]:not([hidden])')) {
            card.querySelector('[data-einfo-field]:not([hidden]) textarea')?.focus();
            return;
        }
        const first = items.find((item) => !item.hidden && !item.querySelector('[aria-current]'));
        if (first) {
            window.location.href = first.querySelector('a').getAttribute('href');
        }
    });
    // "Clear search" (toolbar and card).
    page.querySelectorAll('[data-einfo-clear]').forEach((button) => button.addEventListener('click', () => {
        if (search) {
            search.value = '';
            applySearch();
            search.focus();
        }
    }));

    // Live progress of the object and unsaved-changes guard.
    if (form) {
        const progress = form.querySelector('[data-einfo-progress]');
        // Conditional items ([data-optional]) are not counted, as on the server.
        const total = form.querySelectorAll('[data-einfo-field]:not([data-optional])').length;
        form.addEventListener('input', (event) => {
            const field = event.target.closest('[data-einfo-field]');
            if (!field) {
                return;
            }
            dirty = true;
            field.classList.toggle('is-filled', event.target.value.trim() !== '');
            if (progress) {
                const done = form.querySelectorAll('[data-einfo-field].is-filled:not([data-optional])').length;
                progress.textContent = progress.dataset.template.replace(':done', done).replace(':total', total);
            }
        });
        form.addEventListener('submit', () => { dirty = false; });
        // An accepted AI proposal is saved by the server already.
        form.addEventListener('ai-accepted', (event) => {
            event.target.closest('[data-einfo-field]')?.classList.toggle('is-filled', true);
        });
        window.addEventListener('beforeunload', (event) => {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    // "Missing only": show or hide the documented fields of this object.
    const reveal = card?.querySelector('[data-einfo-reveal]');
    reveal?.addEventListener('click', (event) => {
        event.preventDefault();
        const open = card.classList.toggle('is-revealed');
        reveal.textContent = open ? reveal.dataset.hideLabel : reveal.dataset.showLabel;
    });

    // Phones: the select opens the object.
    const pick = root.querySelector('[data-einfo-pick]');
    pick?.querySelector('select')?.addEventListener('change', (event) => {
        const href = event.target.selectedOptions[0]?.dataset.href;
        if (href) {
            window.location.href = href;
        } else {
            pick.requestSubmit();
        }
    });

    // The search box needs this script; it starts with the search kept for this page.
    const box = page.querySelector('[data-einfo-search-box]');
    if (box) {
        box.hidden = false;
    }
    if (search && search.value.trim() === '') {
        search.value = stored(scope);
    }
    if (search && search.value.trim() !== '') {
        applySearch();
    }

    // The list scrolls on its own on wide screens: bring the object shown into view.
    const current = nav?.querySelector('[aria-current]');
    if (current && nav.scrollHeight > nav.clientHeight) {
        const box = nav.getBoundingClientRect();
        const seen = Math.min(nav.clientHeight, window.innerHeight - box.top);
        const top = current.getBoundingClientRect().top - box.top;
        if (top < 0 || top > seen - current.offsetHeight) {
            nav.scrollTop += top - seen / 3;
        }
        // Still below the fold (the list is taller than what is left of the window): scroll the page.
        const bottom = current.getBoundingClientRect().bottom;
        if (bottom > window.innerHeight - 24) {
            window.scrollBy({ top: bottom - window.innerHeight + 120 });
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-einfo]').forEach(initEinfo);
});
