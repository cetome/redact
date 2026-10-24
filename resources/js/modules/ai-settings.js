// Console, AI settings: the suggested model ids follow the chosen provider (datalist, placeholder,
// help line). Without JavaScript the page works the same, with the suggestions of the saved provider.
//
// <form x-data="aiSettings" data-suggestions='{"anthropic":["claude-sonnet-5-5", ...], ...}'>
//     <select x-ref="provider" @change="pick">…</select>
//     <input x-ref="model" list="ai-models"> <datalist x-ref="list"></datalist>
//     <p data-help-for="anthropic">…</p>
// </form>
document.addEventListener('alpine:init', () => {
    window.Alpine.data('aiSettings', () => ({
        suggestions: {},

        init() {
            try {
                this.suggestions = JSON.parse(this.$el.dataset.suggestions || '{}');
            } catch {
                this.suggestions = {};
            }
        },

        pick() {
            const provider = this.$refs.provider.value;
            const models = this.suggestions[provider] || [];

            this.$refs.list.replaceChildren(...models.map((model) => {
                const option = document.createElement('option');
                option.value = model;
                return option;
            }));
            this.$refs.model.placeholder = models[0] || '';

            this.$root.querySelectorAll('[data-help-for]').forEach((help) => {
                const current = help.dataset.helpFor === provider;
                help.hidden = !current;
                if (current) {
                    this.$refs.model.setAttribute('aria-describedby', help.id);
                }
            });
        },
    }));
});
