// Product card (x-product.card): the picture is uploaded as soon as a file is chosen on it.
// <input type="file" data-autosubmit> sends its form; [data-autosubmit-fallback] (the Save button
// for no-script) is hidden once this runs.

document.querySelectorAll('[data-autosubmit-fallback]').forEach((button) => {
    button.hidden = true;
});

document.addEventListener('change', (event) => {
    const input = event.target.closest?.('input[type="file"][data-autosubmit]');
    if (!input || !input.files || input.files.length === 0 || !input.form) {
        return;
    }
    input.closest('.a-pcard-pic-box')?.classList.add('is-busy');
    input.form.requestSubmit();
});
