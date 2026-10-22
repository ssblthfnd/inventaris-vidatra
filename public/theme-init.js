/*
 * Sets data-theme="light|dark" on <html> before the React app renders, so a dark-mode
 * user never sees a white flash. Loaded from welcome.blade.php as an external file
 * (the CSP forbids inline scripts) and NOT bundled by Vite (a module script would run
 * too late). Same storage key and fallback as resources/js/lib/theme.js.
 */
(function () {
    var theme = null;
    try {
        theme = window.localStorage.getItem('inventaris-theme');
    } catch (e) {
        // storage blocked (private mode, disabled site data): follow the system setting
    }
    if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', theme);
})();
