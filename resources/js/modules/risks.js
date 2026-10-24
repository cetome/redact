// Risk assessment page (resources/views/risks): live feedback in the risk form and the matrix
// editor. Plain delegated listeners: they also work in a side panel loaded later (ui.js).
//
//   form[data-risk-form]   picking a matrix cell (risks/_rating) shows the level it gives ([data-heat-output])
//                          and marks it on the small map ([data-minimap-cell]);
//                          a residual level worse than that level shows [data-residual-warning].
//   form[data-risk-matrix] each cell select takes the colour of the level chosen.

function heatLevel(form) {
    const cell = form.querySelector('input[name="cell"]:checked');
    const label = cell && cell.value ? cell.closest('[data-rank]') : null;
    return label ? { rank: Number(label.dataset.rank), name: label.dataset.name, value: cell.value } : null;
}

function refreshRiskForm(form) {
    const level = heatLevel(form);
    const output = form.querySelector('[data-heat-output]');
    if (output) {
        output.replaceChildren();
        const badge = document.createElement('span');
        if (level) {
            const [impact, likelihood] = level.value.split('-');
            badge.className = `a-level risk-level-${level.rank}`;
            badge.textContent = level.name;
            const line = document.createElement('span');
            line.className = 'a-help';
            line.textContent = (output.dataset.template || '').replace(':impact', impact).replace(':likelihood', likelihood);
            output.append(badge, line);
        } else {
            badge.className = 'a-level a-level-empty';
            badge.textContent = output.dataset.none || '';
            output.append(badge);
        }
    }
    // The small map next to the summary shows where the rating sits.
    form.querySelectorAll('[data-minimap-cell]').forEach((square) => {
        square.classList.toggle('is-current', Boolean(level) && square.dataset.minimapCell === level.value);
    });
    const warning = form.querySelector('[data-residual-warning]');
    if (warning) {
        const residual = form.querySelector('input[name="residual_rank"]:checked');
        const rank = residual && residual.value ? Number(residual.value) : null;
        // Rank 1 is the highest level: a smaller residual rank means a worse residual risk.
        warning.hidden = !(level && rank && rank < level.rank);
    }
}

document.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
        return;
    }
    const form = target.closest('form[data-risk-form]');
    if (form && (target.name === 'cell' || target.name === 'residual_rank')) {
        refreshRiskForm(form);
    }
    if (target instanceof HTMLSelectElement && target.closest('form[data-risk-matrix]')) {
        target.className = target.className.replace(/\brisk-level-\d+\b/g, '').trim() + ` risk-level-${target.value}`;
    }
});
