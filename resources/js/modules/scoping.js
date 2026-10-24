// Live result of the RED scoping toggle cards (same logic as the cetome.com RED scoping):
// a part of EN 18031 is in scope as soon as one question of its article is ticked.
//
// <form x-data="scopingLive" data-scoping='{"questions":{"net_internet":["3.3d"]},"standards":[{"code":"en18031-1","article":"3.3d"}]}' @change="refresh">
//   <p x-data="scopeChip" data-standard="en18031-1" :class="rowClass"> — see scoping/_result.blade.php
//
// "Suggest with AI" (scoping/_ai.blade.php, [data-scope-ai] inside the form): the server proposes
// an answer per question with a reason; the cards show it (scopeAi), "Apply the AI suggestions"
// ticks them, and the encrypted proposal goes with the form (hidden ai_scoping) only once applied.
// The form may carry data-ai-recorded='{"question": true}': the AI value of saved answers that
// carry an AI mark, so a card shows "AI Generated" while it equals it, "Human Edited" otherwise.

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
    };
}

function parse(json, fallback) {
    try {
        const value = JSON.parse(json || '');

        return value && typeof value === 'object' ? value : fallback;
    } catch {
        return fallback;
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('scopingLive', () => ({
        questions: {},
        standards: [],
        inScope: {},
        values: {},
        // A card changed since the page loaded (the server-side comparison shows the saved answers).
        dirty: false,
        loaded: false,

        // "Suggest with AI"
        ai: {},
        aiToken: '',
        aiApplied: false,
        aiBusy: false,
        aiError: '',
        aiStatus: '',
        aiMessage: '',
        aiArticles: [],
        recorded: {},
        aiCfg: {},
        form: null,

        init() {
            this.form = this.$el;
            const data = parse(this.$el.dataset.scoping, {});
            this.questions = data.questions || {};
            this.standards = data.standards || [];
            this.recorded = parse(this.$el.dataset.aiRecorded, {});
            const bar = this.$el.querySelector('[data-scope-ai]');
            this.aiCfg = bar ? { ...bar.dataset } : {};
            this.refresh();
            this.loaded = true;
        },

        box(key) {
            return this.form.querySelector('input[name="answers[' + key + ']"][type="checkbox"]');
        },

        refresh() {
            const articles = {};
            const values = {};
            for (const [key, list] of Object.entries(this.questions)) {
                const box = this.box(key);
                values[key] = Boolean(box && box.checked);
                if (values[key]) {
                    for (const article of list) {
                        articles[article] = true;
                    }
                }
            }
            const inScope = {};
            for (const standard of this.standards) {
                inScope[standard.code] = articles[standard.article] === true;
            }
            this.values = values;
            this.inScope = inScope;
            if (this.loaded) {
                this.dirty = true;
            }
        },

        get anyInScope() {
            return Object.values(this.inScope).some(Boolean);
        },

        get noneInScope() {
            return !this.anyInScope;
        },

        get aiHasResult() {
            return Object.keys(this.ai).length > 0;
        },

        get aiHasArticles() {
            return this.aiArticles.length > 0;
        },

        get aiCanApply() {
            return this.aiHasResult && !this.aiApplied && !this.aiBusy;
        },

        get aiButtonLabel() {
            if (this.aiBusy) {
                return this.aiCfg.labelBusy;
            }

            return this.aiHasResult ? this.aiCfg.labelAgain : this.aiCfg.labelIdle;
        },

        // The proposal travels with the form only once applied.
        get aiAppliedToken() {
            return this.aiApplied ? this.aiToken : '';
        },

        async suggestAi() {
            if (this.aiBusy || !this.aiCfg.url) {
                return;
            }
            const body = {};
            if (this.aiCfg.mode === 'new') {
                const description = this.form.querySelector('[name="description"]');
                const text = description ? description.value.trim() : '';
                if (text === '') {
                    this.aiError = this.aiCfg.empty;
                    description?.focus();

                    return;
                }
                body.description = text;
                body.name = this.form.querySelector('[name="name"]')?.value || '';
                body.organisation_id = this.form.querySelector('[name="organisation_id"]')?.value || '';
            }

            this.aiBusy = true;
            this.aiError = '';
            this.aiStatus = this.aiCfg.reading;
            try {
                const response = await fetch(this.aiCfg.url, {
                    method: 'POST',
                    headers: csrfHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.suggestions) {
                    this.aiStatus = '';
                    this.aiError = (typeof data.message === 'string' && data.message !== '') ? data.message : this.aiCfg.failed;

                    return;
                }
                this.ai = data.suggestions;
                this.aiToken = data.token || '';
                this.aiApplied = false;
                this.aiArticles = Array.isArray(data.articles) ? data.articles : [];
                this.aiMessage = data.message || '';
                this.aiStatus = this.aiMessage;
            } catch {
                this.aiStatus = '';
                this.aiError = this.aiCfg.failed;
            } finally {
                this.aiBusy = false;
            }
        },

        applyAi() {
            for (const [key, suggestion] of Object.entries(this.ai)) {
                const box = this.box(key);
                if (box && !box.disabled) {
                    box.checked = suggestion.applies === true;
                }
            }
            this.aiApplied = true;
            this.refresh();
            this.aiStatus = this.aiCfg.applied;
        },
    }));

    // One result row; reads its parent scopingLive state.
    window.Alpine.data('scopeChip', () => ({
        standard: '',

        init() {
            // Read once: in getters, $el is the element being rendered (an icon), not the chip.
            this.standard = this.$el.dataset.standard || '';
        },

        get isIn() {
            return this.inScope[this.standard] === true;
        },
        get isOut() {
            return !this.isIn;
        },
        get rowClass() {
            return this.isIn ? 'a-scope-std is-in' : 'a-scope-std';
        },
    }));

    // The AI proposal and origin mark on one scoping card; reads its parent scopingLive state.
    window.Alpine.data('scopeAi', () => ({
        key: '',

        init() {
            this.key = this.$el.dataset.key || '';
        },

        get suggestion() {
            return this.ai[this.key] || null;
        },
        get hasSuggestion() {
            return this.suggestion !== null;
        },
        get suggestsYes() {
            return this.hasSuggestion && this.suggestion.applies === true;
        },
        get suggestsNo() {
            return this.hasSuggestion && this.suggestion.applies !== true;
        },
        get confidence() {
            return this.hasSuggestion && this.suggestion.confidence_label ? '· ' + this.suggestion.confidence_label : '';
        },
        get reason() {
            return this.hasSuggestion ? (this.suggestion.reason || '') : '';
        },
        get hasReason() {
            return this.reason !== '';
        },

        // The AI value the card's answer is compared with: the applied suggestion, else the saved mark.
        get aiValue() {
            if (this.aiApplied && this.hasSuggestion) {
                return this.suggestion.applies === true;
            }
            if (Object.prototype.hasOwnProperty.call(this.recorded, this.key)) {
                return this.recorded[this.key] === true;
            }

            return null;
        },
        get isAiGenerated() {
            return this.aiValue !== null && this.values[this.key] === this.aiValue;
        },
        get isHumanEdited() {
            return this.aiValue !== null && this.values[this.key] !== this.aiValue;
        },
        get isShown() {
            return this.hasSuggestion || this.aiValue !== null;
        },
    }));
});
