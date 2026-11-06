// File fields (components/ui/file-input): the chosen file's name, or how many, next to the button.
document.querySelectorAll('[data-file-input]').forEach((box) => {
    const input = box.querySelector('input[type="file"]');
    const name = box.querySelector('[data-file-name]');
    if (!input || !name) {
        return;
    }
    input.addEventListener('change', () => {
        const files = input.files ? [...input.files] : [];
        if (files.length === 0) {
            name.textContent = name.dataset.none;
        } else if (files.length === 1) {
            name.textContent = files[0].name;
        } else {
            name.textContent = name.dataset.many.replace(':count', String(files.length));
        }
        box.classList.toggle('has-file', files.length > 0);
    });
});
