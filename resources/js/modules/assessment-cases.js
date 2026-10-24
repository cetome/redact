// Assessment units (units/_cases.blade.php): the "Assessment units" step and the Review step's view.
// The page lists the keys with data-shortcut + data-shortcut-doc (so the ? help shows them and ui.js
// leaves them here):
//   j / k     next / previous unit still shown by the filters (the "current unit")
//   1 2 3 4   PASS / FAIL / NOT APPLICABLE / INCONCLUSIVE on the current unit, then focus its evidence
//   mod+s     record the current unit (or the unit whose form has the focus), also while typing
//   a d q     Review step: agree / disagree / question on the current unit, then focus Save or the comment
// Plain keys are ignored while typing in a field, with a modifier, or when shortcuts are off.

const TEXT_INPUT = /^(?:text|search|email|url|tel|password|number|date|datetime-local|month|week|time)$/;

function typing(el) {
    return (el instanceof HTMLInputElement && TEXT_INPUT.test(el.type || 'text'))
        || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement
        || (el instanceof HTMLElement && el.isContentEditable);
}

function shortcutsOff() {
    return document.body?.dataset.shortcuts === 'off' || document.documentElement.dataset.shortcuts === 'off';
}

function cases(scope) {
    return [...scope.querySelectorAll('[data-case-row]')].filter((row) => !row.hidden && !row.closest('[hidden]'));
}

function current(scope) {
    return scope.querySelector('[data-case-row].is-current');
}

function setCurrent(scope, row, focus = true) {
    scope.querySelectorAll('[data-case-row].is-current').forEach((r) => r.classList.remove('is-current'));
    if (!row) {
        return;
    }
    row.classList.add('is-current');
    row.scrollIntoView({ block: 'nearest' });
    if (focus) {
        const target = row.querySelector('input[data-case-verdict]:checked') || row.querySelector('input[data-case-verdict]') || row.querySelector('summary');
        target?.focus({ preventScroll: true });
    }
}

function move(scope, step) {
    const list = cases(scope);
    if (!list.length) {
        return;
    }
    const index = list.indexOf(current(scope));
    const next = index === -1 ? (step > 0 ? 0 : list.length - 1) : Math.min(list.length - 1, Math.max(0, index + step));
    setCurrent(scope, list[next]);
}

function pick(scope, key) {
    const row = current(scope);
    const radio = row && !row.hidden ? row.querySelector(`input[data-case-verdict="${key}"]`) : null;
    if (!radio) {
        return false;
    }
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    row.querySelector('[data-case-evidence]')?.focus();
    return true;
}

const OPINIONS = { a: 'agree', d: 'disagree', q: 'question' };

function opinion(scope, key) {
    const row = current(scope);
    const form = row && !row.hidden ? row.querySelector('form[data-review-form]') : null;
    const radio = form?.querySelector(`input[type="radio"][value="${OPINIONS[key]}"]`);
    if (!radio) {
        return false;
    }
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    (key === 'a' ? form.querySelector('[data-review-save]') : form.querySelector('[data-review-comment]'))?.focus();
    return true;
}

function save(scope, from) {
    const form = (from instanceof Element ? from.closest('form[data-case-form]') : null) || current(scope)?.querySelector('form[data-case-form]');
    if (!form) {
        return false;
    }
    form.requestSubmit(form.querySelector('[data-case-save]') || undefined);
    return true;
}

document.addEventListener('keydown', (event) => {
    const scope = document.querySelector('[data-cases]');
    if (!scope || event.defaultPrevented || event.repeat || document.querySelector('dialog[open]')) {
        return;
    }
    // mod+s: works while typing (like every mod+ key), even with shortcuts off.
    if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 's') {
        if (save(scope, event.target)) {
            event.preventDefault();
            event.stopPropagation();
        }
        return;
    }
    if (event.ctrlKey || event.metaKey || event.altKey || typing(event.target) || shortcutsOff()) {
        return;
    }
    let handled = false;
    if (event.key === 'j') {
        move(scope, 1);
        handled = true;
    } else if (event.key === 'k') {
        move(scope, -1);
        handled = true;
    } else if (['1', '2', '3', '4'].includes(event.key)) {
        handled = pick(scope, event.key);
    } else if (event.key in OPINIONS) {
        handled = opinion(scope, event.key);
    }
    if (handled) {
        event.preventDefault();
    }
}, true);

// A click or focus in a case makes it the current one.
document.addEventListener('focusin', (event) => {
    const row = event.target instanceof Element ? event.target.closest('[data-case-row]') : null;
    const scope = row?.closest('[data-cases]');
    if (row && scope && !row.classList.contains('is-current')) {
        scope.querySelectorAll('[data-case-row].is-current').forEach((r) => r.classList.remove('is-current'));
        row.classList.add('is-current');
    }
});
document.addEventListener('click', (event) => {
    const row = event.target instanceof Element ? event.target.closest('[data-case-row]') : null;
    const scope = row?.closest('[data-cases]');
    if (row && scope) {
        scope.querySelectorAll('[data-case-row].is-current').forEach((r) => r.classList.remove('is-current'));
        row.classList.add('is-current');
    }
});

// Back from recording (#case-…) or a refused form: start on that case.
function boot() {
    const scope = document.querySelector('[data-cases]');
    if (!scope) {
        return;
    }
    const failed = scope.querySelector('[data-case-row].has-errors');
    const hash = window.location.hash.slice(1);
    const row = failed || (hash ? document.getElementById(hash) : null);
    if (row && row.matches('[data-case-row]')) {
        setCurrent(scope, row, false);
        if (failed) {
            failed.querySelector('[aria-invalid="true"]')?.focus();
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
