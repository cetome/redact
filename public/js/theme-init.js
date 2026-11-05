// Applies the colour theme before the first paint. Signed in: the account's (meta redact-theme,
// Account > Accessibility). Otherwise: the one saved in this browser. "system" (or nothing) follows
// the device, also when it changes. Loaded synchronously from <head>; kept tiny and dependency-free.
(function () {
    var root = document.documentElement;
    root.classList.add('js');
    var meta = document.querySelector('meta[name="redact-theme"]');
    var theme = meta ? meta.getAttribute('content') : null;
    if (!meta) {
        try {
            theme = localStorage.getItem('theme');
        } catch (e) {
            // storage blocked
        }
    }
    var device = window.matchMedia('(prefers-color-scheme: dark)');
    var apply = function () {
        root.classList.toggle('dark', theme === 'dark' || ((theme !== 'light') && device.matches));
    };
    apply();
    if (device.addEventListener) {
        device.addEventListener('change', function () {
            if (theme !== 'light' && theme !== 'dark') {
                apply();
            }
        });
    }
})();
