// An organisation's AI model card (organisations/settings.blade.php), as CRAted's: the fields follow
// the choice (nothing for the default, key and model for an own key, endpoint and the pictures switch
// for an own endpoint), with the key label and the placeholders of that provider. Without JavaScript
// the server renders the fields of the saved choice.
//
// <form x-data="orgAiModel" data-choices='{"claude":{"model":"…","keyLabel":"…","keyPlaceholder":"…","modelHelp":"…","modelPlaceholder":"…"}, …}'>
//     <select x-ref="choice" @change="pick">…</select>
//     <… data-when="default|own|custom" hidden>
// </form>
document.addEventListener('alpine:init', () => {
    window.Alpine.data('orgAiModel', () => ({
        choices: {},

        init() {
            try {
                this.choices = JSON.parse(this.$el.dataset.choices || '{}');
            } catch {
                this.choices = {};
            }
        },

        pick() {
            const choice = this.$refs.choice.value;
            const info = this.choices[choice] || {};
            const own = choice !== 'default';
            const custom = choice === 'openai_compatible';

            this.$root.querySelectorAll('[data-when]').forEach((element) => {
                const when = element.dataset.when;
                element.hidden = !((when === 'default' && !own) || (when === 'own' && own) || (when === 'custom' && custom));
            });
            this.$root.querySelectorAll('[data-key-for]').forEach((element) => {
                element.hidden = element.dataset.keyFor !== choice;
            });
            if (own) {
                this.$refs.keyLabel.textContent = info.keyLabel || '';
                this.$refs.key.placeholder = info.keyPlaceholder || '';
                this.$refs.model.placeholder = info.modelPlaceholder || '';
                this.$refs.modelHelp.textContent = info.modelHelp || '';
            }
        },
    }));
});
