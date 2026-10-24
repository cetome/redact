// Bills of materials and known vulnerabilities (CSP-safe Alpine components).
//
//   vulnCheckStatus  on the Known vulnerabilities page: while a check runs, polls the status
//                    endpoint, moves the progress bar and the "12 of 40 components checked" line,
//                    and reloads the page when the check is over.
//   Folded rows      (no Alpine) each component shows its known exploited and CVSS ≥ 7 findings;
//                    the rest (tr.is-folded) sits behind a "Show N more" <details data-vuln-fold>.
//                    While the search box or a facet filters the table, folded rows that match are
//                    shown (the group opens) and the toggle row hides; without a filter the
//                    folding comes back. Folded rows are really `hidden`, so "tick all shown"
//                    never ticks rows nobody can see. Without script, CSS folds them (APP-UI.md).

const VULN_TABLE = '#vuln-rows';

function vulnFiltering(table) {
    const input = document.querySelector(`input[data-filter="${VULN_TABLE}"]`);

    return Boolean((input && input.value.trim() !== '') || table.dataset.facet);
}

function syncVulnFolds() {
    const table = document.querySelector(VULN_TABLE);
    if (!table) {
        return;
    }
    const filtering = vulnFiltering(table);
    table.classList.toggle('is-filtering', filtering);
    table.querySelectorAll('tbody').forEach((group) => {
        const fold = group.querySelector('details[data-vuln-fold]');
        if (!fold) {
            return;
        }
        const toggle = fold.closest('tr');
        toggle.hidden = filtering;
        if (!filtering) {
            // ui.js un-hides every item when a filter is cleared: fold them back.
            group.querySelectorAll('tr.is-folded').forEach((row) => {
                row.hidden = !fold.open;
            });
        }
    });
}

// After ui.js has filtered (its listeners run in the same event, so wait a tick).
document.addEventListener('input', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.dataset.filter === VULN_TABLE) {
        setTimeout(syncVulnFolds, 0);
    }
});
document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest(`[data-facet-target="${VULN_TABLE}"] [data-facet]`)) {
        setTimeout(syncVulnFolds, 0);
    }
});
document.addEventListener('toggle', (event) => {
    if (event.target instanceof HTMLDetailsElement && event.target.hasAttribute('data-vuln-fold')) {
        syncVulnFolds();
    }
}, true);
document.addEventListener('DOMContentLoaded', syncVulnFolds);
if (document.readyState !== 'loading') {
    syncVulnFolds();
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('vulnCheckStatus', () => ({
        polls: 0,
        timer: null,
        url: '',
        template: '',
        queued: '',
        finished: '',
        label: '',
        done: 0,
        max: 1,

        init() {
            const data = this.$el.dataset;
            this.url = data.url;
            this.template = data.template || '';
            this.queued = data.queued || '';
            this.finished = data.finished || '';
            const bar = this.$el.querySelector('progress');
            if (bar) {
                this.done = Number(bar.getAttribute('value')) || 0;
                this.max = Number(bar.getAttribute('max')) || 1;
            }
            const line = this.$el.querySelector('[x-text="label"]');
            this.label = line ? line.textContent.trim() : '';
            if (data.running === '1') {
                this.schedule();
            }
        },

        destroy() {
            clearTimeout(this.timer);
        },

        schedule() {
            // Quick at first (sync or a fast worker), then every 4 s.
            this.timer = setTimeout(() => this.poll(), this.polls < 3 ? 1500 : 4000);
        },

        async poll() {
            this.polls++;
            try {
                const response = await fetch(this.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = await response.json();
                this.max = Math.max(1, Number(data.total) || 1);
                this.done = Number(data.done) || 0;
                if (!data.running) {
                    this.label = this.finished;
                    window.location.reload();

                    return;
                }
                this.label = data.state === 'queued'
                    ? this.queued
                    : this.template.replace(':done', String(this.done)).replace(':total', String(data.total));
            } catch {
                // try again on the next tick
            }
            if (this.polls < 600) {
                this.schedule();
            }
        },
    }));
});
