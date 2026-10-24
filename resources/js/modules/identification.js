// Product identifiers: an editable list of rows (kind, value, note).
// <div x-data="identifierRows" data-rows='[{"kind":"model","value":"X1","notes":""}]' data-default-kind="brand">
// (the product card's Models use it with the "model" kind only)

document.addEventListener('alpine:init', () => {
    window.Alpine.data('identifierRows', () => ({
        rows: [],
        nextId: 0,
        defaultKind: 'model',

        init() {
            this.defaultKind = this.$el.dataset.defaultKind || 'model';
            const rows = JSON.parse(this.$el.dataset.rows || '[]');
            this.rows = rows.map((row) => ({ ...row, id: this.nextId++ }));
            if (this.rows.length === 0) {
                this.add();
            }
        },

        add() {
            this.rows.push({ id: this.nextId++, kind: this.defaultKind, value: '', notes: '' });
        },

        remove(event) {
            const id = Number(event.currentTarget.dataset.id);
            this.rows = this.rows.filter((row) => row.id !== id);
        },

        get hasRows() {
            return this.rows.length > 0;
        },
    }));

    // Conformity route: show the Notified Body fields only when a Notified Body is involved.
    window.Alpine.data('conformityRoute', () => ({
        route: '',
        nbRequired: false,

        init() {
            this.route = this.$el.dataset.route || '';
            this.nbRequired = this.$el.dataset.nbRequired === '1';
        },

        pick(event) {
            this.route = event.currentTarget.value;
        },

        get needsBody() {
            return this.route === 'module_b_c' || this.route === 'module_h';
        },

        get conflict() {
            return this.route === 'module_a' && this.nbRequired;
        },
    }));
});
