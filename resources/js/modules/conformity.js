// Self-assessment check (conformity/edit): a follow-up question appears once its parent
// question is answered Yes. <div data-show-if="r_passwords"> follows answers[r_passwords].

function refreshFollowUps(scope) {
    scope.querySelectorAll('[data-show-if]').forEach((block) => {
        const parent = scope.querySelector(`input[type="checkbox"][name="answers[${block.dataset.showIf}]"]`);
        block.hidden = !(parent && parent.checked);
    });
}

document.addEventListener('change', (event) => {
    const scope = event.target instanceof HTMLElement ? event.target.closest('[data-conditional-scope]') : null;
    if (scope) {
        refreshFollowUps(scope);
    }
    // A suggested answer the person changes is theirs: its "From your scoping…" mark goes.
    const derived = event.target instanceof HTMLElement ? event.target.closest('[data-derived]') : null;
    if (derived) {
        derived.querySelectorAll('[data-derived-mark]').forEach((mark) => { mark.hidden = true; });
        derived.querySelector('.a-switch.is-prefilled')?.classList.remove('is-prefilled');
    }
});

document.querySelectorAll('[data-conditional-scope]').forEach(refreshFollowUps);
