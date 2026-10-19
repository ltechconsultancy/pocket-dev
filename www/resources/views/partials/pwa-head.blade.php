{{-- PWA: installable app (Chrome desktop/Android "Install app", iOS "Add to Home Screen").
     Manifest + icons live in public/, the service worker is public/sw.js. --}}
{{-- Theme (Classic / Brutalism light / Brutalism dark), per device. Applied before the
     page paints so there is no flash; public/themes/brutalism.css only acts on data-theme. --}}
<link rel="stylesheet" href="/themes/subagents.css?v=2">
<link rel="stylesheet" href="/themes/claude-usage.css?v=2">
<link rel="stylesheet" href="/themes/contributor.css?v=1">
<link rel="stylesheet" href="/themes/brutalism.css?v=8">
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
<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png?v=2">
{{-- iOS launch screens (brutalism: lime + icon), generated per device size --}}
<link rel="apple-touch-startup-image" media="(device-width: 440px) and (device-height: 956px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1320x2868.png">
<link rel="apple-touch-startup-image" media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1290x2796.png">
<link rel="apple-touch-startup-image" media="(device-width: 402px) and (device-height: 874px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1206x2622.png">
<link rel="apple-touch-startup-image" media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1179x2556.png">
<link rel="apple-touch-startup-image" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1170x2532.png">
<link rel="apple-touch-startup-image" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1284x2778.png">
<link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1125x2436.png">
<link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" href="/icons/splash/splash-1242x2688.png">
<link rel="apple-touch-startup-image" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/icons/splash/splash-828x1792.png">
<link rel="apple-touch-startup-image" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/icons/splash/splash-750x1334.png">
<link rel="apple-touch-startup-image" media="(device-width: 820px) and (device-height: 1180px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/icons/splash/splash-1640x2360.png">
<link rel="apple-touch-startup-image" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/icons/splash/splash-1668x2388.png">
<link rel="apple-touch-startup-image" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" href="/icons/splash/splash-2048x2732.png">
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
