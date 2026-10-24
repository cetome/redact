// App UI kit behaviour (see resources/css/APP-UI.md). Plain delegated listeners, no Alpine needed,
// so every page gets them and nothing is written inline (strict CSP).
//
//   Drawers and modals   <dialog class="a-drawer|a-modal" id="x" data-close-url="…">. A server-rendered
//                        <dialog open> (the no-script fallback) becomes modal on load. Triggers:
//                        <a href="fallback" data-dialog-open="x">, <button data-dialog-close>.
//                        Esc and a click on the backdrop close it; focus returns to the trigger.
//   Clickable rows       <tr data-href="…"> or a row holding [data-row-link]: a click anywhere on the
//                        row follows (clicks) its link, unless the click was on a control.
//   Filter as you type   <input data-filter="#scope"> hides [data-filter-item] in #scope whose
//                        data-search (or text) does not contain every word typed. Optional
//                        [data-filter-count] in the same toolbar shows "n of m", [data-filter-empty]
//                        is shown when nothing matches. Groups ([data-filter-group]) hide when empty.
//   Disclosure           <button data-disclosure="panel-id" aria-expanded="false"> toggles .is-open.
//   Fetched drawer       <a href="…/items/5" data-dialog-fetch="item-drawer">: loads that URL, takes the
//                        <dialog id="item-drawer"> it renders, swaps it in and opens it, so the
//                        table keeps its scroll. Any failure falls back to following the link.
//   Prefill a form       <button data-fill='{"name":"x","attributes[type]":"a"}' data-fill-target="#f">
//                        sets those fields of form #f (checkbox arrays, radios, selects, text).
//   Picker summary       <div data-picker> with checkboxes and a [data-picker-summary] box: the
//                        checked options appear there as chips that uncheck on click.
//   Keyboard shortcuts   data-shortcut="j" | "mod+s" | "g o" (+ data-shortcut-label, data-shortcut-doc):
//                        see the section at the end of this file and APP-UI.md; ? lists them.

const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="button"], [data-no-row-click]';

document.documentElement.classList.add('js');

function upgradeDialogs(root = document) {
    root.querySelectorAll('dialog.a-drawer[open], dialog.a-modal[open]').forEach((dialog) => {
        dialog.classList.remove('is-static');
        dialog.close();
        dialog.showModal();
        dialog.querySelector('[autofocus], [data-autofocus]')?.focus();
        dialog.dispatchEvent(new CustomEvent('dialog:open'));
    });
    root.querySelectorAll('dialog.a-drawer.is-static, dialog.a-modal.is-static').forEach((d) => d.classList.remove('is-static'));
}

export function openDialog(dialog, opener = null) {
    if (!dialog || dialog.open) {
        return;
    }
    dialog._opener = opener || document.activeElement;
    if (dialog.dataset.openUrl) {
        history.replaceState(null, '', dialog.dataset.openUrl);
    }
    dialog.showModal();
    const focus = dialog.querySelector('[autofocus], [data-autofocus]');
    if (focus) {
        focus.focus();
    }
    // For what lives in the dialog (tree-walk.js brings the current question into view).
    dialog.dispatchEvent(new CustomEvent('dialog:open'));
}

async function fetchDialog(link) {
    const id = link.dataset.dialogFetch;
    try {
        link.setAttribute('aria-busy', 'true');
        const response = await fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(String(response.status));
        }
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = doc.getElementById(id);
        const current = document.getElementById(id);
        if (!fresh || !current) {
            throw new Error('missing dialog');
        }
        fresh.removeAttribute('open');
        fresh.classList.remove('is-static');
        const node = document.importNode(fresh, true);
        current.replaceWith(node);
        node.dataset.openUrl = link.href;
        openDialog(node, link);
        node.querySelectorAll('[data-picker]').forEach(refreshPicker);
    } catch {
        window.location.assign(link.href);
    } finally {
        link.removeAttribute('aria-busy');
    }
}

// Set the fields of a form from {name: value}. Checkbox groups take arrays, single checkboxes and
// radios take the value to match (true checks a box). Hidden inputs are left alone.
export function fillForm(form, data) {
    for (const el of form.elements) {
        if (!el.name || el.type === 'hidden' || !(el.name in data)) {
            continue;
        }
        const value = data[el.name];
        if (el.type === 'checkbox') {
            el.checked = Array.isArray(value) ? value.map(String).includes(el.value) : value === true || String(value) === el.value;
        } else if (el.type === 'radio') {
            el.checked = String(value) === el.value;
        } else {
            el.value = value ?? '';
        }
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const first = form.querySelector('input:not([type="hidden"]), select, textarea');
    if (first) {
        first.focus();
    }
}

function onDialogClosed(event) {
    const dialog = event.target;
    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }
    if (dialog.dataset.closeUrl) {
        history.replaceState(null, '', dialog.dataset.closeUrl);
    }
    const opener = dialog._opener;
    dialog._opener = null;
    if (opener && document.contains(opener) && typeof opener.focus === 'function') {
        opener.focus();
    }
}

document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
        return;
    }

    // Disclosure: <button data-disclosure="id" aria-expanded="false"> toggles .is-open on #id.
    const toggle = target.closest('[data-disclosure]');
    if (toggle) {
        const panel = document.getElementById(toggle.dataset.disclosure);
        if (panel) {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.classList.toggle('is-open', open);
        }
        return;
    }

    const fetcher = target.closest('a[data-dialog-fetch]');
    if (fetcher && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
        event.preventDefault();
        fetchDialog(fetcher);
        return;
    }

    const filler = target.closest('[data-fill]');
    if (filler) {
        const form = document.querySelector(filler.dataset.fillTarget);
        if (form) {
            event.preventDefault();
            fillForm(form, JSON.parse(filler.dataset.fill || '{}'));
        }
        return;
    }

    const opener = target.closest('[data-dialog-open]');
    if (opener) {
        const dialog = document.getElementById(opener.dataset.dialogOpen);
        if (dialog) {
            event.preventDefault();
            openDialog(dialog, opener);
        }
        return;
    }

    const closer = target.closest('[data-dialog-close]');
    if (closer) {
        const dialog = closer.closest('dialog');
        if (dialog) {
            event.preventDefault();
            dialog.close();
        }
        return;
    }

    // A click on the backdrop of a modal dialog lands on the dialog itself, outside its box.
    if (target instanceof HTMLDialogElement && target.open && (target.classList.contains('a-drawer') || target.classList.contains('a-modal'))) {
        const box = target.getBoundingClientRect();
        const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
        if (!inside) {
            target.close();
        }
        return;
    }

    // Clickable rows
    const row = target.closest('tr[data-href], tr:has([data-row-link])');
    if (row && !target.closest(INTERACTIVE) && !window.getSelection()?.toString()) {
        const link = row.querySelector('[data-row-link]');
        if (link) {
            link.click();
        } else if (row.dataset.href) {
            window.location.assign(row.dataset.href);
        }
    }
});

document.addEventListener('close', onDialogClosed, true);

// Filter as you type
function normalise(text) {
    return (text || '').toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

// Filters a scope by the words of its search box (input[data-filter="#scope"]) and by its facet
// (a [data-facet] button pressed in a group with data-facet-target="#scope": items whose
// data-facet-value differs are hidden; an empty data-facet shows everything).
function filterScope(scope) {
    const selector = '#' + scope.id;
    const input = document.querySelector(`input[data-filter="${selector}"]`);
    const words = normalise(input ? input.value : '').split(/\s+/).filter(Boolean);
    const facet = scope.dataset.facet || '';
    const items = scope.querySelectorAll('[data-filter-item]');
    let shown = 0;
    items.forEach((item) => {
        const haystack = normalise(item.dataset.search || item.textContent);
        const facets = (item.dataset.facetValue || '').split(' ');
        const match = words.every((w) => haystack.includes(w)) && (facet === '' || facets.includes(facet));
        item.hidden = !match;
        if (match) {
            shown++;
        }
    });
    scope.querySelectorAll('[data-filter-group]').forEach((group) => {
        group.hidden = group.querySelectorAll('[data-filter-item]:not([hidden])').length === 0;
    });
    const container = (input || scope).closest('[data-filter-scope]') || document;
    const count = container.querySelector('[data-filter-count]');
    if (count) {
        count.textContent = words.length || facet ? (count.dataset.template || ':shown of :total').replace(':shown', shown).replace(':total', items.length) : '';
    }
    const empty = container.querySelector('[data-filter-empty]') || scope.querySelector('[data-filter-empty]');
    if (empty) {
        empty.hidden = shown !== 0;
    }
}

function applyFilter(input) {
    const scope = document.querySelector(input.dataset.filter);
    if (scope && scope.id) {
        filterScope(scope);
    }
}

document.addEventListener('input', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.dataset.filter) {
        applyFilter(input);
    }
});

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-facet]') : null;
    const group = button ? button.closest('[data-facet-target]') : null;
    const scope = group ? document.querySelector(group.dataset.facetTarget) : null;
    if (!button || !scope) {
        return;
    }
    group.querySelectorAll('[data-facet]').forEach((b) => b.setAttribute('aria-pressed', b === button ? 'true' : 'false'));
    scope.dataset.facet = button.dataset.facet;
    filterScope(scope);
});

// Counters of ticked boxes: <span data-checked-count="#scope">; buttons with
// data-needs-checked="#scope" are disabled while nothing is ticked there.
function refreshCounters() {
    document.querySelectorAll('[data-checked-count]').forEach((el) => {
        const scope = document.querySelector(el.dataset.checkedCount);
        el.textContent = scope ? scope.querySelectorAll('input[type="checkbox"]:checked').length : 0;
    });
    document.querySelectorAll('[data-needs-checked]').forEach((el) => {
        const scope = document.querySelector(el.dataset.needsChecked);
        el.disabled = !scope || scope.querySelectorAll('input[type="checkbox"]:checked').length === 0;
    });
}

// Autosave: <form data-autosave> sends itself (fetch, JSON expected) a moment after each change.
// [data-autosave-status] inside it shows Saving / Saved / the error; its data-saving, data-saved
// and data-failed attributes hold the words. The form still has a normal Save button for no-script.
const timers = new WeakMap();
function autosave(form) {
    clearTimeout(timers.get(form));
    const status = form.querySelector('[data-autosave-status]');
    const say = (key, cls) => {
        if (status) {
            status.textContent = status.dataset[key] || '';
            status.dataset.state = cls;
        }
    };
    say('saving', 'saving');
    timers.set(form, setTimeout(async () => {
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            say('saved', 'saved');
        } catch {
            say('failed', 'failed');
        }
    }, 450));
}

// Check or clear a whole row or column of a tick grid: <button data-check-row> inside the row,
// <button data-check-col="3"> in the header (the cell index), or every box still shown by the
// filters of a scope: <button data-check-visible="#scope">. All ticked: clears, else ticks all.
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-check-row], [data-check-col], [data-check-visible]') : null;
    if (!button) {
        return;
    }
    let boxes = [];
    if (button.hasAttribute('data-check-visible')) {
        const scope = document.querySelector(button.dataset.checkVisible);
        boxes = scope ? [...scope.querySelectorAll('[data-filter-item]:not([hidden]) input[type="checkbox"]:not(:disabled)')] : [];
    } else if (button.hasAttribute('data-check-row')) {
        boxes = [...button.closest('tr').querySelectorAll('input[type="checkbox"]:not(:disabled)')];
    } else {
        const index = Number(button.dataset.checkCol);
        boxes = [...button.closest('table').querySelectorAll('tbody tr:not([hidden])')]
            .map((tr) => tr.children[index]?.querySelector('input[type="checkbox"]:not(:disabled)'))
            .filter(Boolean);
    }
    const tick = boxes.some((b) => !b.checked);
    boxes.forEach((b) => {
        b.checked = tick;
    });
    if (boxes[0]) {
        boxes[0].dispatchEvent(new Event('change', { bubbles: true }));
    }
});

// Esc in a filter box clears it first (and does not close the dialog around it).
document.addEventListener('keydown', (event) => {
    const input = event.target;
    if (event.key === 'Escape' && input instanceof HTMLInputElement && input.dataset.filter && input.value) {
        event.preventDefault();
        input.value = '';
        applyFilter(input);
    }
});

// Picker summary
export function refreshPicker(picker) {
    const summary = picker.querySelector('[data-picker-summary]');
    if (!summary) {
        return;
    }
    summary.replaceChildren();
    picker.querySelectorAll('input[type="checkbox"]:checked').forEach((box) => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'a-chip a-chip-sm a-chip-code';
        chip.textContent = box.value + ' ×';
        chip.title = picker.dataset.removeLabel || '';
        chip.addEventListener('click', () => {
            box.checked = false;
            box.dispatchEvent(new Event('change', { bubbles: true }));
        });
        summary.append(chip);
    });
}

// Tick grid totals: <table data-tick-grid> with [data-row-count] in each row and
// [data-col-count="index"] cells (header or footer) counting the ticks of that column.
function refreshGrid(table) {
    table.querySelectorAll('tbody tr').forEach((tr) => {
        const out = tr.querySelector('[data-row-count]');
        if (out) {
            out.textContent = tr.querySelectorAll('input[type="checkbox"]:checked').length;
        }
    });
    table.querySelectorAll('[data-col-count]').forEach((out) => {
        const index = Number(out.dataset.colCount);
        out.textContent = [...table.querySelectorAll('tbody tr')]
            .filter((tr) => tr.children[index]?.querySelector('input[type="checkbox"]:checked')).length;
    });
}

document.addEventListener('change', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) {
        return;
    }
    const picker = target.closest('[data-picker]');
    if (picker) {
        refreshPicker(picker);
    }
    const grid = target.closest('table[data-tick-grid]');
    if (grid) {
        refreshGrid(grid);
    }
    const form = target.closest('form[data-autosave]') || (target.form && target.form.matches('[data-autosave]') ? target.form : null);
    if (form) {
        autosave(form);
    }
    refreshCounters();
});

function boot() {
    upgradeDialogs();
    document.querySelectorAll('[data-picker]').forEach(refreshPicker);
    document.querySelectorAll('input[data-filter]').forEach((input) => input.value && applyFilter(input));
    document.querySelectorAll('table[data-tick-grid]').forEach(refreshGrid);
    refreshCounters();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

// Keyboard shortcuts (APP-UI.md "Keyboard shortcuts"). Elements carry data-shortcut="j", "/", "?",
// "Backspace", "mod+s", "mod+enter" or a sequence "g o" (second key within 1.2 s); mod is Ctrl,
// or Cmd on macOS. A match is clicked, or focused (text selected) when it is a text field, a
// textarea or a select. Ignored: elements hidden, not rendered, disabled or inert, and everything
// outside the open dialog when there is one; the first usable match in DOM order wins (one in the
// form that has the focus first). A link only
// folded away (sidebar on a narrow screen, closed menu) still works when nothing visible matches.
// data-shortcut-doc: listed in the help, never triggered here (the page handles that key itself).
// data-shortcut-label: its line in the help. <body data-shortcuts="off">: only mod+ keys and ? work.
const IS_MAC = /Mac|iPhone|iPad|iPod/.test(navigator.userAgentData?.platform || navigator.platform || '');
const SEQUENCE_MS = 1200;
const TEXT_INPUT = /^(?:text|search|email|url|tel|password|number|date|datetime-local|month|week|time)$/;
const MODIFIER_KEYS = ['Shift', 'Control', 'Alt', 'AltGraph', 'Meta', 'CapsLock', 'OS'];
let pending = null;

function isField(el) {
    return (el instanceof HTMLInputElement && TEXT_INPUT.test(el.type || 'text'))
        || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement;
}

function isTyping(el) {
    return el instanceof HTMLElement && (isField(el) || el.isContentEditable);
}

function shortcutsOff() {
    return document.body?.dataset.shortcuts === 'off' || document.documentElement.dataset.shortcuts === 'off';
}

// "mod+S" → "mod+s", "G  O" stays "G O" (single keys keep their case), "Backspace" → "backspace".
function normaliseShortcut(value) {
    return (value || '').trim().split(/\s+/)
        .map((part) => (part.length > 1 ? part.toLowerCase() : part))
        .join(' ');
}

function eventKey(event, mod) {
    const key = event.key || '';
    if (mod) {
        return 'mod+' + key.toLowerCase();
    }
    return key.length > 1 ? key.toLowerCase() : key;
}

function scopeRoot() {
    const dialogs = document.querySelectorAll('dialog[open]');
    return dialogs.length ? dialogs[dialogs.length - 1] : document;
}

function isRendered(el) {
    if (typeof el.checkVisibility === 'function') {
        return el.checkVisibility({ visibilityProperty: true });
    }
    return el.getClientRects().length > 0;
}

// 'visible' (counts), 'folded' (a link only folded away by CSS or a closed menu) or false.
function usable(el) {
    if (el.closest('[hidden], [inert]') || el.matches(':disabled') || el.getAttribute('aria-disabled') === 'true') {
        return false;
    }
    const dialog = el.closest('dialog');
    if (dialog && !dialog.open) {
        return false;
    }
    if (isRendered(el)) {
        return 'visible';
    }
    return el.matches('a[href]') ? 'folded' : false;
}

function triggers() {
    return scopeRoot().querySelectorAll('[data-shortcut]:not([data-shortcut-doc])');
}

// The first usable match in DOM order, except that a match in the form holding the focus wins
// (two forms with a mod+s Save each: the one being typed in is saved).
function findTarget(combo) {
    let folded = null;
    let visible = null;
    const form = document.activeElement?.closest?.('form');
    for (const el of triggers()) {
        if (normaliseShortcut(el.dataset.shortcut) !== combo) {
            continue;
        }
        const state = usable(el);
        if (state === 'visible') {
            if (form && (el.form === form || form.contains(el))) {
                return el;
            }
            visible = visible || el;
        } else if (state === 'folded') {
            folded = folded || el;
        }
    }
    return visible || folded;
}

function startsSequence(key) {
    return [...triggers()].some((el) => normaliseShortcut(el.dataset.shortcut).startsWith(key + ' ') && usable(el));
}

function activate(el) {
    if (isField(el)) {
        el.focus();
        if (typeof el.select === 'function') {
            el.select();
        }
    } else if (usable(el) === 'folded') {
        window.location.assign(el.href);
    } else {
        el.click();
    }
}

// "/" without a page shortcut: the first search box of the page (or of the open dialog).
function searchBox() {
    return [...scopeRoot().querySelectorAll('.a-search input, input[data-filter], [data-filter]')]
        .find((el) => isField(el) && usable(el) === 'visible');
}

function focusSearch() {
    const input = searchBox();
    if (input) {
        input.focus();
        input.select();
    }
    return Boolean(input);
}

// A small "g …" chip while a sequence waits for its second key.
function showPending(key) {
    let chip = document.getElementById('a-kbd-pending');
    if (!key) {
        if (chip) {
            chip.hidden = true;
        }
        return;
    }
    if (!chip) {
        chip = document.createElement('div');
        chip.id = 'a-kbd-pending';
        chip.className = 'a-kbd-pending';
        chip.setAttribute('aria-hidden', 'true');
        document.body.append(chip);
    }
    chip.replaceChildren(renderShortcut(key), document.createTextNode('…'));
    chip.hidden = false;
}

function clearPending() {
    if (pending) {
        clearTimeout(pending.timer);
        pending = null;
        showPending(null);
    }
}

document.addEventListener('keydown', (event) => {
    if (event.isComposing || MODIFIER_KEYS.includes(event.key) || event.key === 'Dead' || event.key === 'Unidentified') {
        return;
    }
    const altGraph = typeof event.getModifierState === 'function' && event.getModifierState('AltGraph');
    const mod = !altGraph && (IS_MAC ? event.metaKey : event.ctrlKey);
    const other = !altGraph && (event.altKey || (IS_MAC ? event.ctrlKey : event.metaKey));

    // mod+key: everywhere, also while typing in a field.
    if (mod) {
        clearPending();
        const target = !other && !event.defaultPrevented ? findTarget(eventKey(event, true)) : null;
        if (target) {
            event.preventDefault();
            activate(target);
        }
        return;
    }
    if (other || event.ctrlKey || event.metaKey) {
        return;
    }

    if (isTyping(event.target)) {
        // Esc leaves the field (a dialog closes itself on Esc; a filter box clears itself first).
        if (event.key === 'Escape' && !event.defaultPrevented && !event.target.closest('dialog[open]')) {
            event.target.blur();
        }
        return;
    }
    if (event.defaultPrevented || event.repeat) {
        return;
    }

    const key = eventKey(event, false);

    if (key === '?') {
        clearPending();
        event.preventDefault();
        const help = document.getElementById('shortcuts-help');
        const target = findTarget('?');
        if (help?.open) {
            help.close();
        } else if (target) {
            activate(target);
        } else {
            openShortcutsHelp();
        }
        return;
    }
    if (shortcutsOff()) {
        return;
    }

    if (pending) {
        const combo = pending.key + ' ' + key;
        clearPending();
        const target = findTarget(combo);
        if (target) {
            event.preventDefault();
            activate(target);
        }
        return;
    }

    if (startsSequence(key)) {
        event.preventDefault();
        pending = { key, timer: setTimeout(clearPending, SEQUENCE_MS) };
        showPending(key);
        return;
    }
    const target = findTarget(key);
    if (target) {
        event.preventDefault();
        activate(target);
    } else if (key === '/' && focusSearch()) {
        event.preventDefault();
    }
});

// Keys as <kbd class="a-kbd">: ⌘ or Ctrl for mod, a letter after mod in capitals, a sequence
// "g o" as g [then] o. Built with DOM nodes (strict CSP, no innerHTML).
const KEY_NAMES = { mod: IS_MAC ? '⌘' : 'Ctrl', enter: 'Enter', escape: 'Esc', backspace: '⌫', delete: 'Del', arrowup: '↑', arrowdown: '↓', arrowleft: '←', arrowright: '→', space: 'Space', tab: 'Tab' };

export function renderShortcut(value, thenWord = '') {
    const fragment = document.createDocumentFragment();
    normaliseShortcut(value).split(' ').forEach((step, i) => {
        if (i > 0) {
            const then = document.createElement('span');
            then.className = 'a-kbd-then';
            then.textContent = thenWord;
            fragment.append(then);
        }
        const keys = step.length > 1 && step.includes('+') ? step.split('+') : [step];
        keys.forEach((k, j) => {
            const kbd = document.createElement('kbd');
            kbd.className = 'a-kbd';
            kbd.textContent = KEY_NAMES[k] || (j > 0 ? k.toUpperCase() : k);
            fragment.append(kbd);
        });
    });
    return fragment;
}

// The help dialog (#shortcuts-help, in the layout), built when it opens from the page's
// [data-shortcut][data-shortcut-label] elements, one line per key: [data-shortcuts-section="global"]
// takes those marked data-shortcut-group="global", "nav" the sequences starting with g, "page" the
// rest that are usable now (doc entries always).
function collectShortcuts(help) {
    const seen = new Set();
    const groups = { global: [], nav: [], page: [] };
    const all = [...document.querySelectorAll('[data-shortcut][data-shortcut-label]')];
    const globalFirst = [...all.filter((el) => el.dataset.shortcutGroup === 'global'), ...all.filter((el) => el.dataset.shortcutGroup !== 'global')];
    globalFirst.forEach((el) => {
        const combo = normaliseShortcut(el.dataset.shortcut);
        const dialog = el.closest('dialog');
        if (help.contains(el) || seen.has(combo) || (dialog && !dialog.open)) {
            return;
        }
        if (!el.hasAttribute('data-shortcut-doc') && !usable(el)) {
            return;
        }
        if (combo === '/' && el.hasAttribute('data-shortcut-doc') && !searchBox()) {
            return;
        }
        seen.add(combo);
        const group = el.dataset.shortcutGroup === 'global' ? 'global' : combo.startsWith('g ') ? 'nav' : 'page';
        groups[group].push({ combo, label: el.dataset.shortcutLabel });
    });
    return groups;
}

function openShortcutsHelp(opener = null) {
    const help = document.getElementById('shortcuts-help');
    if (!help) {
        return;
    }
    const groups = collectShortcuts(help);
    const then = help.dataset.then || '';
    help.querySelectorAll('[data-shortcuts-section]').forEach((section) => {
        const items = groups[section.dataset.shortcutsSection] || [];
        section.querySelector('[data-shortcuts-list]')?.replaceChildren(...items.map(({ combo, label }) => {
            const row = document.createElement('li');
            row.className = 'a-keys-row';
            const text = document.createElement('span');
            text.className = 'a-keys-label';
            text.textContent = label;
            const keys = document.createElement('span');
            keys.className = 'a-keys-combo';
            keys.append(renderShortcut(combo, then));
            row.append(text, keys);
            return row;
        }));
        const empty = section.querySelector('[data-shortcuts-empty]');
        if (empty) {
            empty.hidden = items.length > 0;
        } else {
            section.hidden = items.length === 0;
        }
    });
    const offNote = help.querySelector('[data-shortcuts-off]');
    if (offNote) {
        offNote.hidden = !shortcutsOff();
    }
    openDialog(help, opener || document.activeElement);
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-shortcuts-help]') : null;
    if (button) {
        event.preventDefault();
        openShortcutsHelp(button);
    }
});

// <kbd data-kbd="mod+s" data-then="then"></kbd> anywhere: rendered for this platform (⌘ / Ctrl).
function renderKbdHints(root = document) {
    root.querySelectorAll('[data-kbd]').forEach((el) => {
        el.replaceChildren(renderShortcut(el.dataset.kbd, el.dataset.then || ''));
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => renderKbdHints());
} else {
    renderKbdHints();
}
