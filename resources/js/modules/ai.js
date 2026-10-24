// AI assistance (CSP-safe Alpine components).
//
//   aiAssist       "Improve text with AI" next to a textarea (<x-ai.assist>): the proposal opens
//                  in a dialog with a word diff, "Keep my words" or "Accept". Accepting asks the
//                  server to copy its stored proposal into the field; the browser never sends
//                  the value it accepts.
//   aiDraftStatus  on the AI review page: reloads the page when a running draft has finished.

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
    };
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify(body || {}),
    });
    const data = await response.json().catch(() => ({}));

    return { ok: response.ok, data };
}

function firstMessage(data) {
    if (data && typeof data.message === 'string' && data.message !== '') {
        if (data.errors) {
            const first = Object.values(data.errors)[0];
            if (Array.isArray(first) && first.length) {
                return first[0];
            }
        }

        return data.message;
    }

    return '';
}

// Word diff of two texts: same / added / removed runs (longest common subsequence of words).
export function diffWords(before, after) {
    const a = String(before || '').split(/(\s+)/).filter((t) => t !== '');
    const b = String(after || '').split(/(\s+)/).filter((t) => t !== '');
    const parts = [];
    const push = (kind, text) => {
        const last = parts[parts.length - 1];
        if (last && last.kind === kind) {
            last.text += text;
        } else {
            parts.push({ kind, text, same: kind === 'same', added: kind === 'added', removed: kind === 'removed' });
        }
    };

    if (a.length * b.length > 4000000) {
        push('removed', a.join(''));
        push('added', b.join(''));

        return parts;
    }

    const rows = a.length + 1;
    const cols = b.length + 1;
    const table = new Uint32Array(rows * cols);
    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            table[i * cols + j] = a[i] === b[j]
                ? table[(i + 1) * cols + j + 1] + 1
                : Math.max(table[(i + 1) * cols + j], table[i * cols + j + 1]);
        }
    }

    let i = 0;
    let j = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            push('same', a[i]);
            i++;
            j++;
        } else if (table[(i + 1) * cols + j] >= table[i * cols + j + 1]) {
            push('removed', a[i++]);
        } else {
            push('added', b[j++]);
        }
    }
    while (i < a.length) push('removed', a[i++]);
    while (j < b.length) push('added', b[j++]);

    return parts;
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.data('aiAssist', () => ({
        busy: false,
        saving: false,
        enhanced: false,
        decided: true,
        error: '',
        dialogError: '',
        cfg: {},
        root: null,
        parts: [],
        proposal: { id: null, original: '', improved: '', changes: [], unchanged: false, accept_url: '', reject_url: '' },

        init() {
            this.root = this.$el;
            this.cfg = { ...this.$el.dataset };
        },

        get buttonLabel() {
            return this.busy ? this.cfg.labelBusy : this.cfg.labelIdle;
        },

        get showDiff() {
            return !this.proposal.unchanged && this.proposal.original.trim() !== '' && this.parts.length > 0;
        },

        get cannotAccept() {
            return this.saving || !this.proposal.id || this.proposal.unchanged;
        },

        field() {
            if (this.cfg.for) {
                return document.getElementById(this.cfg.for);
            }
            let node = this.root ? this.root.parentElement : null;
            while (node) {
                const found = node.querySelector('textarea');
                if (found) {
                    return found;
                }
                node = node.parentElement;
            }

            return null;
        },

        async improve() {
            if (this.busy) {
                return;
            }
            this.error = '';
            const field = this.field();
            const text = field ? field.value.trim() : '';
            if (text === '') {
                this.error = this.cfg.empty;

                return;
            }

            this.busy = true;
            try {
                const { ok, data } = await postJson(this.cfg.url, {
                    type: this.cfg.type,
                    key: this.cfg.key,
                    field: this.cfg.field,
                    text,
                });
                if (!ok) {
                    this.error = firstMessage(data) || this.cfg.error;

                    return;
                }
                this.proposal = {
                    id: data.id,
                    original: data.original || '',
                    improved: data.improved || '',
                    changes: Array.isArray(data.changes) ? data.changes : [],
                    unchanged: Boolean(data.unchanged),
                    accept_url: data.accept_url,
                    reject_url: data.reject_url,
                };
                this.parts = diffWords(this.proposal.original, this.proposal.improved);
                this.decided = this.proposal.unchanged;
                this.dialogError = '';
                this.$refs.dialog.showModal();
            } catch {
                this.error = this.cfg.error;
            } finally {
                this.busy = false;
            }
        },

        async accept() {
            if (this.cannotAccept) {
                return;
            }
            this.saving = true;
            this.dialogError = '';
            try {
                const { ok, data } = await postJson(this.proposal.accept_url);
                if (!ok) {
                    this.dialogError = firstMessage(data) || this.cfg.error;

                    return;
                }
                const field = this.field();
                if (field && typeof data.value === 'string') {
                    field.value = data.value;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                    field.dispatchEvent(new CustomEvent('ai-accepted', {
                        bubbles: true,
                        detail: { type: this.cfg.type, key: this.cfg.key, field: this.cfg.field, value: data.value, origin: data.origin },
                    }));
                }
                this.enhanced = true;
                this.decided = true;
                this.$refs.dialog.close();
            } catch {
                this.dialogError = this.cfg.error;
            } finally {
                this.saving = false;
            }
        },

        keep() {
            this.dismiss();
            this.$refs.dialog.close();
        },

        // Escape closes the dialog like "Keep my words".
        cancelled() {
            this.dismiss();
        },

        closed() {
            this.dismiss();
        },

        dismiss() {
            if (this.decided || !this.proposal.reject_url) {
                return;
            }
            this.decided = true;
            postJson(this.proposal.reject_url).catch(() => {});
        },
    }));

    Alpine.data('aiDraftStatus', () => ({
        polls: 0,
        timer: null,
        url: '',

        init() {
            this.url = this.$el.dataset.url;
            if (this.$el.dataset.running === '1') {
                this.schedule();
            }
        },

        destroy() {
            clearTimeout(this.timer);
        },

        schedule() {
            this.timer = setTimeout(() => this.poll(), 5000);
        },

        async poll() {
            this.polls++;
            try {
                const response = await fetch(this.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = await response.json();
                if (!data.running) {
                    window.location.reload();

                    return;
                }
            } catch {
                // try again on the next tick
            }
            if (this.polls < 180) {
                this.schedule();
            }
        },
    }));
});
