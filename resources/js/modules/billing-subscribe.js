// Price lines that follow the choices: the subscribe page (billing/subscribe.blade.php, FAST's
// /subscribe) and the billing page's products (billing/_products.blade.php, CRAted's add-on card).
// The server writes every line for each choice into data-table (prices, plurals and wording
// included); this only shows the ones chosen, and drives the - / + counters. Without script the
// page shows the first choice, which the server rendered. Plain listeners, no Alpine needed.
//
// - form[data-subscribe]: table[payment period][additional products], the period from the radio
//   input[name="offer"], the number from the counter [data-key].
// - form[data-lines]: table[value of [data-key]].

const show = (form, lines) => {
    if (!lines) {
        return;
    }
    form.querySelectorAll('[data-line]').forEach((el) => {
        const text = lines[el.dataset.line];
        if (typeof text === 'string') {
            el.textContent = text;
        }
    });
};

// The - / + buttons around a number field [data-key], within its min and max.
const steppers = (form, key, update) => {
    form.querySelectorAll('[data-step]').forEach((button) => {
        button.addEventListener('click', () => {
            const min = Number(key.min || 1);
            const max = Number(key.max || 99);
            const next = Math.min(max, Math.max(min, (Number(key.value) || min) + Number(button.dataset.step)));
            key.value = String(next);
            update();
        });
    });
    key.addEventListener('input', update);
};

document.querySelectorAll('form[data-subscribe]').forEach((form) => {
    const table = JSON.parse(form.dataset.table || '{}');
    const key = form.querySelector('[data-key]');
    const update = () => {
        const checked = form.querySelector('input[name="offer"]:checked');
        const interval = checked ? checked.dataset.interval : Object.keys(table)[0];
        show(form, (table[interval] || {})[key ? key.value : '0']);
    };
    if (key) {
        steppers(form, key, update);
    }
    form.addEventListener('change', update);
    update();
});

document.querySelectorAll('form[data-lines]').forEach((form) => {
    const table = JSON.parse(form.dataset.table || '{}');
    const key = form.querySelector('[data-key]');
    if (!key) {
        return;
    }
    const update = () => show(form, table[key.value]);
    steppers(form, key, update);
    key.addEventListener('change', update);
    update();
});
