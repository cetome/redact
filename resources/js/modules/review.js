// Review page keys (the elements carry data-shortcut + data-shortcut-doc, so the ? help lists them
// and the global handler in ui.js leaves them to this file):
//   j / k   next / previous evaluation row still shown by the filters (the "current row")
//   o       open the read-only evidence panel of the current row (#evidence, fetched by ui.js)
//   a d q   agree / disagree / question (reviewers), in the open evidence panel or on the current
//           row (its panel opens first): ticks the choice, then focuses Save (agree) or the comment
//           (disagree, question: a reason is required)
//   c       show only the evaluations changed since reviewed, or all again
// Ignored while typing in a field, with a modifier, or when shortcuts are off.

const TEXT_INPUT = /^(?:text|search|email|url|tel|password|number|date|datetime-local|month|week|time)$/;
const VERDICTS = { a: 'agree', d: 'disagree', q: 'question' };

function typing(el) {
    return (el instanceof HTMLInputElement && TEXT_INPUT.test(el.type || 'text'))
        || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement
        || (el instanceof HTMLElement && el.isContentEditable);
}

function shortcutsOff() {
    return document.body?.dataset.shortcuts === 'off' || document.documentElement.dataset.shortcuts === 'off';
}

function rows(scope) {
    return [...scope.querySelectorAll('tr[data-review-row]')].filter((row) => !row.hidden && !row.closest('[hidden]'));
}

function current(scope) {
    return scope.querySelector('tr[data-review-row].is-current');
}

function setCurrent(scope, row) {
    scope.querySelectorAll('tr[data-review-row].is-current').forEach((r) => r.classList.remove('is-current'));
    if (!row) {
        return;
    }
    row.classList.add('is-current');
    row.scrollIntoView({ block: 'nearest' });
    row.querySelector('.a-row-link')?.focus({ preventScroll: true });
}

function move(scope, step) {
    const list = rows(scope);
    if (!list.length) {
        return;
    }
    const index = list.indexOf(current(scope));
    const next = index === -1 ? (step > 0 ? 0 : list.length - 1) : Math.min(list.length - 1, Math.max(0, index + step));
    setCurrent(scope, list[next]);
}

// The verdict asked for before the panel was loaded: ticked once it opens.
let pending = null;

function tick(form, verdict) {
    const radio = form?.querySelector(`input[type="radio"][value="${verdict}"]`);
    if (!radio) {
        return false;
    }
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    const target = verdict === 'agree' ? form.querySelector('[data-review-save]') : form.querySelector('[data-review-comment]');
    target?.focus();
    return true;
}

function open(scope) {
    const row = current(scope);
    const link = row && !row.hidden ? row.querySelector('a[data-dialog-fetch]') : null;
    if (!link) {
        return false;
    }
    link.click();
    return true;
}

function review(scope, verdict) {
    const row = current(scope);
    if (!row || row.hidden || !row.querySelector('a[data-dialog-fetch]')) {
        return false;
    }
    pending = verdict;
    return open(scope);
}

function toggleChanged(scope) {
    const changed = scope.querySelector('[data-facet="changed"]');
    const all = scope.querySelector('[data-facet=""]');
    if (!changed || !all) {
        return false;
    }
    (changed.getAttribute('aria-pressed') === 'true' ? all : changed).click();
    const row = current(scope);
    if (row && row.hidden) {
        setCurrent(scope, null);
    }
    return true;
}

document.addEventListener('keydown', (event) => {
    const scope = document.querySelector('[data-review]');
    if (!scope || event.defaultPrevented || event.repeat || event.ctrlKey || event.metaKey || event.altKey
        || typing(event.target) || shortcutsOff()) {
        return;
    }
    const dialog = document.querySelector('dialog[open]');
    if (dialog) {
        // In the evidence panel: a / d / q tick the review form there, nothing else.
        if (dialog.id === 'evidence' && event.key in VERDICTS && tick(dialog.querySelector('form[data-review-form]'), VERDICTS[event.key])) {
            event.preventDefault();
        }
        return;
    }
    let handled = false;
    if (event.key === 'o') {
        handled = open(scope);
    } else if (event.key === 'j') {
        move(scope, 1);
        handled = true;
    } else if (event.key === 'k') {
        move(scope, -1);
        handled = true;
    } else if (event.key in VERDICTS) {
        handled = review(scope, VERDICTS[event.key]);
    } else if (event.key === 'c') {
        handled = toggleChanged(scope);
    }
    if (handled) {
        event.preventDefault();
    }
});

// A click in a row makes it the current one; back from saving (#evaluation-…) starts on that row.
document.addEventListener('click', (event) => {
    const row = event.target instanceof Element ? event.target.closest('tr[data-review-row]') : null;
    const scope = row?.closest('[data-review]');
    if (row && scope) {
        scope.querySelectorAll('tr[data-review-row].is-current').forEach((r) => r.classList.remove('is-current'));
        row.classList.add('is-current');
    }
});

// The evidence panel opened (fetched by ui.js): tick the verdict asked for with a / d / q.
document.addEventListener('dialog:open', (event) => {
    const dialog = event.target;
    if (dialog instanceof HTMLDialogElement && dialog.id === 'evidence' && pending) {
        tick(dialog.querySelector('form[data-review-form]'), pending);
    }
    pending = null;
}, true);

function boot() {
    const scope = document.querySelector('[data-review]');
    const hash = window.location.hash.slice(1);
    const row = scope && hash ? document.getElementById(hash) : null;
    if (row && row.matches('tr[data-review-row]')) {
        row.classList.add('is-current');
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
