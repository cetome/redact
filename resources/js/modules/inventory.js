// Inventory (resources/views/inventory): plain delegated listeners, no Alpine.
//
//   [data-group-bar="#scope"]  the picker's save bar. Its [data-group-submit] button ("Add as one
//                              group") is enabled from two ticked boxes in #scope; its
//                              input[data-group-name] proposes a name until the person types one:
//                              the boxes' data-group-label when they all share one data-group-key
//                              (the pack's named group, e.g. "Security functions"), else it shows
//                              data-template with :count as placeholder. Enter in the name adds the group.
//   [data-select-bar="#scope"] the list's selection bar (Group): shows how many rows of #scope
//                              are selected ([data-select-count]); [data-select-needs="2"] buttons
//                              are enabled from that many. [data-select-all] ticks every shown row.
//   [data-continue-bar="#scope"] the picker's "Add and continue" button: .is-empty while nothing is
//                              ticked in #scope, so CSS shows "Skip to <next list>" instead.
//   [data-prefill]             "Prefill everything": per list ([data-prefill-list]) "n of N"
//                              ([data-prefill-count] data-template) and a Select all / None toggle
//                              ([data-prefill-toggle], shown by this script); the submit button
//                              ([data-prefill-submit] data-one / data-many) counts the chosen items
//                              and is disabled at 0.

function checked(scope) {
    return scope ? [...scope.querySelectorAll('input[type="checkbox"]:checked')] : [];
}

function refreshGroupBar(bar) {
    const boxes = checked(document.querySelector(bar.dataset.groupBar));
    const button = bar.querySelector('[data-group-submit]');
    if (button) {
        button.disabled = boxes.length < 2;
    }
    const input = bar.querySelector('input[data-group-name]');
    if (!input || input.dataset.typed === '1') {
        return;
    }
    input.dataset.idle ??= input.placeholder;
    const keys = new Set(boxes.map((b) => b.dataset.groupKey || ''));
    const shared = boxes.length >= 2 && keys.size === 1 && !keys.has('') ? boxes[0].dataset.groupLabel || '' : '';
    // A shared named group is proposed as the value; otherwise the server's fallback name shows as
    // the placeholder (sent empty, the server gives that name).
    input.value = shared;
    input.placeholder = boxes.length < 2 ? input.dataset.idle : (input.dataset.template || '').replace(':count', String(boxes.length));
}

function refreshSelectBar(bar) {
    const scope = document.querySelector(bar.dataset.selectBar);
    const n = checked(scope).length;
    bar.querySelectorAll('[data-select-count]').forEach((el) => {
        el.textContent = String(n);
    });
    bar.querySelectorAll('[data-select-needs]').forEach((el) => {
        el.disabled = n < Number(el.dataset.selectNeeds || 1);
    });
    bar.classList.toggle('is-active', n > 0);
}

function refreshContinueBar(bar) {
    const boxes = checked(document.querySelector(bar.dataset.continueBar)).filter((b) => !b.disabled);
    bar.classList.toggle('is-empty', boxes.length === 0);
}

function refreshPrefill(form) {
    let total = 0;
    form.querySelectorAll('[data-prefill-list]').forEach((list) => {
        const boxes = [...list.querySelectorAll('input[type="checkbox"]')];
        const n = boxes.filter((b) => b.checked).length;
        total += n;
        const count = list.querySelector('[data-prefill-count]');
        if (count) {
            count.textContent = count.dataset.template.replace(':n', String(n)).replace(':total', String(boxes.length));
        }
        const toggle = list.querySelector('[data-prefill-toggle]');
        if (toggle) {
            toggle.hidden = false;
            toggle.textContent = n === boxes.length ? toggle.dataset.none : toggle.dataset.all;
        }
    });
    const button = document.querySelector(`[data-prefill-submit][form="${form.id}"]`);
    if (button) {
        button.disabled = total === 0;
        const label = button.querySelector('span');
        if (label) {
            label.textContent = total === 1 ? button.dataset.one : button.dataset.many.replace(':count', String(total));
        }
    }
}

function refreshAll() {
    document.querySelectorAll('[data-prefill]').forEach(refreshPrefill);
    document.querySelectorAll('[data-group-bar]').forEach(refreshGroupBar);
    document.querySelectorAll('[data-continue-bar]').forEach(refreshContinueBar);
    document.querySelectorAll('[data-select-bar]').forEach(refreshSelectBar);
}

document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
        refreshAll();
    }
});

document.addEventListener('input', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.matches('[data-group-name]')) {
        input.dataset.typed = input.value.trim() === '' ? '0' : '1';
    }
});

document.addEventListener('keydown', (event) => {
    const input = event.target;
    if (event.key !== 'Enter' || !(input instanceof HTMLInputElement)) {
        return;
    }
    // Enter in the group name adds the group; Enter in a filter box never sends the picker.
    if (input.matches('[data-group-name]')) {
        event.preventDefault();
        const button = input.closest('[data-group-bar]')?.querySelector('[data-group-submit]');
        if (button && !button.disabled) {
            button.click();
        }
    } else if (input.dataset.filter && input.form) {
        event.preventDefault();
    }
});

// Select all / None of one list of "Prefill everything": the button sits in the list's summary, so
// the click must not open or close the list.
document.addEventListener('click', (event) => {
    const toggle = event.target instanceof Element ? event.target.closest('[data-prefill-toggle]') : null;
    const list = toggle?.closest('[data-prefill-list]');
    if (!list) {
        return;
    }
    event.preventDefault();
    const boxes = [...list.querySelectorAll('input[type="checkbox"]')];
    const tick = boxes.some((b) => !b.checked);
    boxes.forEach((b) => {
        b.checked = tick;
    });
    refreshAll();
});

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-select-all]') : null;
    const scope = button ? document.querySelector(button.dataset.selectAll) : null;
    if (!scope) {
        return;
    }
    const boxes = [...scope.querySelectorAll('[data-filter-item]:not([hidden]) input[type="checkbox"][data-select-item]')];
    const tick = boxes.some((b) => !b.checked);
    boxes.forEach((b) => {
        b.checked = tick;
    });
    refreshAll();
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshAll);
} else {
    refreshAll();
}
