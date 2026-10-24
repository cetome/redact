// Applies the saved (or system) colour theme before the first paint.
// Loaded synchronously from <head>; kept tiny and dependency-free.
(function () {
    document.documentElement.classList.add('js');
    var theme = null;
    try {
        theme = localStorage.getItem('theme');
    } catch (e) {
        // storage blocked
    }
    if (theme === 'dark' || (theme === null && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    }
})();
