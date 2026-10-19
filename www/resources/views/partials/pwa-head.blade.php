{{-- PWA: installable app (Chrome desktop/Android "Install app", iOS "Add to Home Screen").
     Manifest + icons live in public/, the service worker is public/sw.js. --}}
{{-- Theme (Classic / Brutalism light / Brutalism dark), per device. Applied before the
     page paints so there is no flash; public/themes/brutalism.css only acts on data-theme. --}}
<link rel="stylesheet" href="/themes/brutalism.css?v=3">
<script>
    (function () {
        var theme = null;
        try { theme = localStorage.getItem('pd-theme'); } catch (e) {}
        if (theme === 'brutal-light' || theme === 'brutal-dark') {
            document.documentElement.setAttribute('data-theme', theme);
        }
        window.pdThemeColor = function () {
            var t = document.documentElement.getAttribute('data-theme');
            return t === 'brutal-light' ? '#ffffff' : (t === 'brutal-dark' ? '#121212' : '#1f2937');
        };
    })();
</script>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1f2937">
<script>document.querySelector('meta[name=theme-color]').setAttribute('content', window.pdThemeColor());</script>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="PocketDev">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
        });
    }
    // Keep Chrome's install prompt so the menu can offer "App installeren".
    // (Chrome's own menu item / address-bar icon keeps working as well.)
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        window.pwaInstallPrompt = event;
        window.dispatchEvent(new CustomEvent('pwa-installable'));
    });
    window.addEventListener('appinstalled', () => {
        window.pwaInstallPrompt = null;
        window.dispatchEvent(new CustomEvent('pwa-installed'));
    });
    window.pwaInstall = async () => {
        const prompt = window.pwaInstallPrompt;
        if (!prompt) return false;
        prompt.prompt();
        const { outcome } = await prompt.userChoice;
        window.pwaInstallPrompt = null;
        window.dispatchEvent(new CustomEvent('pwa-installed'));
        return outcome === 'accepted';
    };
</script>
