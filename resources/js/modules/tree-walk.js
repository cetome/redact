// Guided walk through an EN 18031 decision tree, with the tree diagram kept in step.
//
// <div class="a-eval" x-data="treeWalk" data-tree='{"start":"DN-1","nodes":[...]}' data-answers='["No","Yes"]'
//      data-requirement="ACM-1" data-expert="0" data-interactive="1" data-answer-label="Answer :answer">
//   … the walk (Yes / No buttons, Back, Restart) …
//   <aside class="a-eval-tree"> @include('trees._diagram', ['interactive' => true, …]) </aside>
// </div>
//
// The tree comes from the server (one tree, for this page only). The diagram is rendered by the
// server from the saved answers; here it is repainted after every step (.is-on-path, .is-current,
// .is-taken, .is-reached, aria-pressed, aria-current). When the person can edit, a click on a
// question card makes it the current question and a click on an answer answers that question:
// the walk keeps the steps that lead there, else takes the shortest way from the start.
// Keys: y / n (or 1, 2, 3 for other labels), b or Backspace, r. The server recomputes the outcome
// from the submitted answers: this is only the UI.
//
// Several trees on one page (the E.Info page: one per requirement, each in its own modal <dialog>):
// keys go to the tree of the open dialog only, never to a tree in a closed one. Opening the dialog
// brings the current question into view; closing it without saving puts the saved answers back.

const TEXT_INPUT = /^(?:text|search|email|url|tel|password|number|date|datetime-local|month|week|time)$/;

function typing(el) {
    return (el instanceof HTMLInputElement && TEXT_INPUT.test(el.type || 'text'))
        || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement
        || (el instanceof HTMLElement && el.isContentEditable);
}

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('treeWalk', () => ({
        nodes: {},
        start: null,
        current: null,
        steps: [],
        expert: false,
        interactive: false,
        requirement: '',
        answerLabel: ':answer',

        init() {
            const tree = JSON.parse(this.$el.dataset.tree || '{}');
            this.start = tree.start;
            for (const node of tree.nodes || []) {
                this.nodes[node.id] = node;
            }
            this.current = this.start;
            const answers = JSON.parse(this.$el.dataset.answers || '[]');
            for (const answer of answers) {
                if (!this.follow(answer)) {
                    break;
                }
            }
            this.expert = this.$el.dataset.expert === '1';
            // Read once here: in getters $el is the element being rendered, not the component root.
            this.requirement = this.$el.dataset.requirement || '';
            this.interactive = this.$el.dataset.interactive === '1';
            this.answerLabel = this.$el.dataset.answerLabel || ':answer';
            this.diagram = this.$el.querySelector('[data-tree-diagram]');
            this.column = this.$el.querySelector('.a-eval-tree');
            this.expertSelect = this.$el.querySelector('select[name="decision_node"]');
            this.dialog = this.$el.closest('dialog');
            this.paint(false);
            // A walk already under way: its current question (or outcome) in view of the column.
            if (this.steps.length) {
                requestAnimationFrame(() => this.paint(true, true));
            }

            if (this.interactive) {
                this.onKey = (event) => this.key(event);
                document.addEventListener('keydown', this.onKey);
            }
            if (this.dialog) {
                this.onOpen = () => requestAnimationFrame(() => this.paint(true, true));
                this.onClose = () => this.reset();
                this.dialog.addEventListener('dialog:open', this.onOpen);
                this.dialog.addEventListener('close', this.onClose);
            }
        },

        // Back to the saved answers (a dialog closed without saving).
        reset() {
            this.steps = [];
            this.current = this.start;
            for (const answer of JSON.parse(this.$el.dataset.answers || '[]')) {
                if (!this.follow(answer)) {
                    break;
                }
            }
            this.expert = this.$el.dataset.expert === '1';
            if (this.$el instanceof HTMLFormElement) {
                this.$el.reset();
            }
            this.paint(false);
        },

        destroy() {
            if (this.onKey) {
                document.removeEventListener('keydown', this.onKey);
            }
            this.dialog?.removeEventListener('dialog:open', this.onOpen);
            this.dialog?.removeEventListener('close', this.onClose);
        },

        branchOf(node, label) {
            return node.branches.find((b) => b.label.toLowerCase() === String(label).toLowerCase()) || null;
        },

        follow(label) {
            const node = this.nodes[this.current];
            if (!node || node.kind !== 'decision') {
                return false;
            }
            const branch = this.branchOf(node, label);
            if (!branch) {
                return false;
            }
            this.steps.push({ node: node.id, text: node.text, answer: branch.label });
            this.current = branch.to;
            return true;
        },

        // A walk step changed: repaint the diagram and bring the current question into view.
        changed() {
            this.paint(true);
        },

        choose(event) {
            if (this.follow(event.currentTarget.dataset.label)) {
                this.changed();
            }
        },

        back() {
            const last = this.steps.pop();
            if (last) {
                this.current = last.node;
                this.changed();
            }
        },

        restart() {
            if (this.steps.length) {
                this.steps = [];
                this.current = this.start;
                this.changed();
            }
        },

        toggleExpert() {
            this.expert = !this.expert;
            this.paint(true);
        },

        // Shortest list of {node, answer} from `from` to `to` over decision nodes, or null.
        route(from, to) {
            if (from === to) {
                return [];
            }
            const seen = new Set([from]);
            const queue = [[from, []]];
            while (queue.length) {
                const [id, trail] = queue.shift();
                const node = this.nodes[id];
                for (const branch of node?.kind === 'decision' ? node.branches : []) {
                    const next = this.nodes[branch.to];
                    if (seen.has(branch.to) || !next || next.kind !== 'decision') {
                        continue;
                    }
                    const step = [...trail, { node: id, text: node.text, answer: branch.label }];
                    if (branch.to === to) {
                        return step;
                    }
                    seen.add(branch.to);
                    queue.push([branch.to, step]);
                }
            }
            return null;
        },

        // Make `id` the current question: keep the walked steps that lead to it (the longest
        // prefix it can be reached from), else the shortest way from the start.
        jumpTo(id) {
            const at = this.steps.findIndex((s) => s.node === id);
            if (at !== -1) {
                this.steps = this.steps.slice(0, at);
                this.current = id;
                return true;
            }
            for (let k = this.steps.length; k >= 0; k--) {
                const from = k === this.steps.length ? this.current : this.steps[k].node;
                const way = this.route(from, id);
                if (way) {
                    this.steps = [...this.steps.slice(0, k), ...way];
                    this.current = id;
                    return true;
                }
            }
            return false;
        },

        pickNode(event) {
            const id = event.currentTarget.dataset.node;
            this.expert = false;
            if (this.current !== id) {
                this.jumpTo(id);
            }
            this.changed();
        },

        pickBranch(event) {
            const { node, label } = event.currentTarget.dataset;
            this.expert = false;
            if (this.current === node || this.jumpTo(node)) {
                this.follow(label);
            }
            this.changed();
        },

        // y / n answer Yes / No, 1-9 the n-th answer, b or Backspace go back, r restarts.
        key(event) {
            if (this.expert || event.defaultPrevented || event.repeat || event.ctrlKey || event.metaKey || event.altKey
                || typing(event.target) || !this.listening()) {
                return;
            }
            const key = event.key.length === 1 ? event.key.toLowerCase() : event.key;
            const branches = this.branches;
            const branch = branches.find((b) => b.key === key) || (/^[1-9]$/.test(key) ? branches[Number(key) - 1] : null);
            if (branch) {
                this.follow(branch.label);
                this.changed();
            } else if ((key === 'b' || key === 'Backspace') && this.steps.length) {
                this.back();
            } else if (key === 'r' && this.steps.length) {
                this.restart();
            } else {
                return;
            }
            event.preventDefault();
        },

        // Keys reach this tree when the page shows it: no dialog open and the tree is not in one, or
        // the tree is in the dialog on top.
        listening() {
            const open = document.querySelectorAll('dialog[open]');
            const top = open.length ? open[open.length - 1] : null;
            return top ? top === this.dialog : !this.dialog;
        },

        // The diagram follows the walk (or, in expert mode, the decision node picked in the list).
        paint(scroll, instant = false) {
            if (!this.diagram) {
                return;
            }
            const expertNode = this.expert ? this.expertSelect?.value || null : null;
            const steps = this.expert ? [] : this.steps;
            const taken = new Map(steps.map((s) => [s.node, s.answer.toLowerCase()]));
            const focus = !this.expert && this.interactive && this.asking ? this.current : null;
            const end = !this.expert && this.finished && steps.length ? steps[steps.length - 1].node : null;
            let focusCard = null;

            for (const card of this.diagram.querySelectorAll('[data-tree-node]')) {
                const id = card.dataset.treeNode;
                const isCurrent = id === focus;
                card.classList.toggle('is-current', isCurrent);
                card.classList.toggle('is-on-path', !isCurrent && (taken.has(id) || id === expertNode));
                const head = card.querySelector('.a-tree-pick');
                if (head) {
                    if (isCurrent) {
                        head.setAttribute('aria-current', 'step');
                    } else {
                        head.removeAttribute('aria-current');
                    }
                }
                if (isCurrent) {
                    focusCard = card;
                }
            }
            for (const branch of this.diagram.querySelectorAll('[data-tree-branch]')) {
                const node = branch.dataset.node;
                const isTaken = taken.get(node) === branch.dataset.label.toLowerCase();
                branch.classList.toggle('is-taken', isTaken);
                branch.classList.toggle('is-reached', isTaken && node === end);
                if (branch.tagName === 'BUTTON') {
                    branch.setAttribute('aria-pressed', isTaken ? 'true' : 'false');
                }
            }
            const target = focusCard || (end ? this.diagram.querySelector(`[data-tree-node="${end}"]`) : null);
            if (scroll && target) {
                this.reveal(target, instant);
            }
        },

        // Bring the card into view: the side column when the tree has one (it scrolls on its own),
        // else the page, only as far as needed.
        reveal(card, instant = false) {
            const column = this.column;
            if (!column || column.scrollHeight <= column.clientHeight + 1) {
                card.scrollIntoView({ block: 'nearest', behavior: instant || reduceMotion() ? 'auto' : 'smooth' });

                return;
            }
            // The part of the column on screen: it can start below the page header or end below the fold.
            const box = column.getBoundingClientRect();
            const head = column.querySelector('.a-eval-tree-head');
            const top = Math.max(box.top, 0) + (head && getComputedStyle(head).position === 'sticky' ? head.offsetHeight : 0);
            const bottom = Math.min(box.bottom, window.innerHeight);
            const rect = card.getBoundingClientRect();
            const margin = 12;
            if (bottom - top < 80 || (rect.top >= top + margin && rect.bottom <= bottom - margin)) {
                return;
            }
            const room = bottom - top;
            const shift = rect.height > room - 2 * margin
                ? rect.top - top - margin
                : rect.top - top - (room - rect.height) / 3;
            column.scrollTo({ top: Math.max(0, column.scrollTop + shift), behavior: instant || reduceMotion() ? 'auto' : 'smooth' });
        },

        get node() {
            return this.nodes[this.current] || null;
        },

        get asking() {
            return !this.expert && this.node !== null && this.node.kind === 'decision';
        },

        get finished() {
            return this.node !== null && this.node.kind === 'result';
        },

        get question() {
            return this.asking ? this.node.text : '';
        },

        get questionId() {
            return this.asking ? 'DT.' + this.requirement + '.' + this.node.id : '';
        },

        // The answers of the current question, with their key: y / n for Yes / No, else 1, 2, 3.
        get branches() {
            if (!this.asking) {
                return [];
            }
            const list = this.node.branches;
            const lower = list.map((b) => b.label.toLowerCase());
            const letters = lower.every((l) => l === 'yes' || l === 'no') && new Set(lower).size === lower.length;
            return list.map((b, i) => {
                const label = b.label.charAt(0).toUpperCase() + b.label.slice(1).toLowerCase();
                return {
                    label,
                    key: letters ? lower[i].charAt(0) : String(i + 1),
                    hint: this.answerLabel.replace(':answer', label),
                };
            });
        },

        get hasSteps() {
            return this.steps.length > 0;
        },

        get lastNode() {
            return this.steps.length ? 'DT.' + this.requirement + '.' + this.steps[this.steps.length - 1].node : '';
        },

        get result() {
            return this.finished ? this.node.result : '';
        },

        get resultClass() {
            return {
                PASS: 'a-verdict-pass',
                FAIL: 'a-verdict-fail',
                'NOT APPLICABLE': 'a-verdict-na',
            }[this.result] || '';
        },

        // Submit answers only for a finished walk in guided mode.
        get submitAnswers() {
            return !this.expert && this.finished;
        },

        // The answers so far (guided mode), kept when the evaluation is marked INCONCLUSIVE.
        get submitPartial() {
            return !this.expert && this.hasSteps;
        },
    }));
});
