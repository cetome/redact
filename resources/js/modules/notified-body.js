// Notified Body autocomplete (conformity/edit): the NANDO list for RED, kept in
// resources/data/notified-bodies-red.json, filtered as the person types a number or a name.
// Choosing "0123 · TÜV SÜD Product Service GmbH (Germany)" fills both fields.
// Plain delegated listeners (no Alpine, CSP safe), ARIA combobox with a listbox:
// ArrowDown / ArrowUp move, Enter picks, Escape closes.
//
// <div data-nb-picker data-bodies='[{"number":"0123","name":"…","country":"…"}]'>
//   <input data-nb-input="name" role="combobox" aria-controls="nb-options"> <input data-nb-input="number" …>
//   <ul id="nb-options" role="listbox" data-nb-list hidden></ul>
//   <p data-nb-unknown hidden>Not in the NANDO list…</p>
// </div>

const MAX = 8;

function fold(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function bodiesOf(picker) {
    if (!picker._bodies) {
        try {
            picker._bodies = JSON.parse(picker.dataset.bodies || '[]');
        } catch {
            picker._bodies = [];
        }
    }
    return picker._bodies;
}

function matches(picker, query) {
    const q = fold(query).trim();
    if (q === '') {
        return [];
    }
    const digits = /^\d+$/.test(q);
    return bodiesOf(picker)
        .filter((body) => (digits ? body.number.startsWith(q) : fold(`${body.number} ${body.name} ${body.country}`).includes(q)))
        .slice(0, MAX);
}

function label(body) {
    return `${body.number} · ${body.name}${body.country ? ` (${body.country})` : ''}`;
}

function listOf(picker) {
    return picker.querySelector('[data-nb-list]');
}

function close(picker) {
    const list = listOf(picker);
    if (!list) {
        return;
    }
    list.hidden = true;
    list.replaceChildren();
    picker.querySelectorAll('[data-nb-input]').forEach((input) => {
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    });
}

function refreshUnknown(picker) {
    const note = picker.querySelector('[data-nb-unknown]');
    const number = picker.querySelector('[data-nb-input="number"]');
    if (!note || !number) {
        return;
    }
    const value = number.value.trim();
    note.hidden = !(/^\d{4}$/.test(value) && !bodiesOf(picker).some((body) => body.number === value));
}

function open(picker, input) {
    const list = listOf(picker);
    if (!list) {
        return;
    }
    const found = matches(picker, input.value);
    list.replaceChildren();
    if (found.length === 0) {
        close(picker);
        return;
    }
    found.forEach((body, index) => {
        const option = document.createElement('li');
        option.id = `${list.id}-${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.className = 'a-combo-option';
        option.dataset.number = body.number;
        option.dataset.name = body.name;
        const number = document.createElement('b');
        number.textContent = body.number;
        const name = document.createElement('span');
        name.textContent = body.name;
        const country = document.createElement('span');
        country.className = 'a-combo-meta';
        country.textContent = body.country;
        option.append(number, name, country);
        option.title = label(body);
        list.append(option);
    });
    list.hidden = false;
    list.dataset.owner = input.dataset.nbInput;
    input.setAttribute('aria-expanded', 'true');
}

function options(picker) {
    return Array.from(listOf(picker)?.querySelectorAll('[role="option"]') || []);
}

function move(picker, input, step) {
    const all = options(picker);
    if (all.length === 0) {
        open(picker, input);
        return;
    }
    const current = all.findIndex((option) => option.getAttribute('aria-selected') === 'true');
    const next = (current + step + all.length) % all.length;
    all.forEach((option, index) => option.setAttribute('aria-selected', index === next ? 'true' : 'false'));
    input.setAttribute('aria-activedescendant', all[next].id);
    all[next].scrollIntoView({ block: 'nearest' });
}

function choose(picker, option) {
    const name = picker.querySelector('[data-nb-input="name"]');
    const number = picker.querySelector('[data-nb-input="number"]');
    if (name) {
        name.value = option.dataset.name;
    }
    if (number) {
        number.value = option.dataset.number;
    }
    close(picker);
    refreshUnknown(picker);
    const owner = picker.querySelector(`[data-nb-input="${listOf(picker)?.dataset.owner || 'name'}"]`) || name;
    owner?.focus();
}

document.addEventListener('input', (event) => {
    const input = event.target instanceof HTMLInputElement && event.target.matches('[data-nb-input]') ? event.target : null;
    const picker = input?.closest('[data-nb-picker]');
    if (picker) {
        open(picker, input);
        refreshUnknown(picker);
    }
});

document.addEventListener('keydown', (event) => {
    const input = event.target instanceof HTMLInputElement && event.target.matches('[data-nb-input]') ? event.target : null;
    const picker = input?.closest('[data-nb-picker]');
    if (!picker) {
        return;
    }
    const list = listOf(picker);
    const shown = list && !list.hidden;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        move(picker, input, event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Enter' && shown) {
        const selected = options(picker).find((option) => option.getAttribute('aria-selected') === 'true');
        if (selected) {
            event.preventDefault();
            choose(picker, selected);
        }
    } else if (event.key === 'Escape' && shown) {
        event.preventDefault();
        event.stopPropagation();
        close(picker);
    }
}, true);

// mousedown, not click: the input keeps the focus, the list does not close on blur first.
document.addEventListener('mousedown', (event) => {
    const option = event.target instanceof Element ? event.target.closest('[data-nb-list] [role="option"]') : null;
    const picker = option?.closest('[data-nb-picker]');
    if (option && picker) {
        event.preventDefault();
        choose(picker, option);
        return;
    }
    document.querySelectorAll('[data-nb-picker]').forEach((other) => {
        if (!(event.target instanceof Node) || !other.contains(event.target)) {
            close(other);
        }
    });
});

document.addEventListener('focusout', (event) => {
    const picker = event.target instanceof Element ? event.target.closest('[data-nb-picker]') : null;
    if (picker && !(event.relatedTarget instanceof Node && picker.contains(event.relatedTarget))) {
        close(picker);
    }
});

document.querySelectorAll('[data-nb-picker]').forEach(refreshUnknown);
