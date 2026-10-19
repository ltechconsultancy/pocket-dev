/**
 * PocketDev Service Worker
 *
 * 1. PWA: makes PocketDev installable (Chrome "Install app", Android home screen)
 *    and shows an offline page when the server can't be reached.
 * 2. Web Push notifications (push + notificationclick).
 *
 * Registered on every page by partials/pwa-head.blade.php (and by webpush.js).
 *
 * Caching rules (allow-list, everything else goes straight to the network):
 * - navigations (PocketDev's own pages only, see APP_PAGES): network-first,
 *   offline.html only when the network fails. Other apps behind the same proxy
 *   (/coti, /hotraco, ...) are left alone.
 *   The app HTML itself is never cached (it carries the user + CSRF token).
 * - /build/*: Vite assets with a content hash in the name -> cache-first.
 * - /icons/*, /manifest.json, /offline.html: precached.
 * - never touched: /api (incl. SSE streams), non-GET requests, other origins.
 *
 * Bump VERSION when the precache list or these rules change.
 */
'use strict';

const VERSION = 'v5-update';
const SHELL_CACHE = 'pocketdev-shell-' + VERSION;
const ASSET_CACHE = 'pocketdev-assets-' + VERSION;
const OFFLINE_URL = '/offline.html';
// Top-level path segments of PocketDev pages ('' = '/'); `php artisan route:list`
const APP_PAGES = ['', 'session', 'config', 'settings', 'setup', 'login', 'two-factor-challenge', 'claude', 'codex', 'cursor'];
const isAppPage = (pathname) => APP_PAGES.includes(pathname.split('/')[1] ?? '');

const PRECACHE = [
    OFFLINE_URL,
    '/manifest.json',
    '/favicon.ico',
    '/icons/icon.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
    '/icons/apple-touch-icon.png',
];

// ============================================================================
// PWA lifecycle
// ============================================================================

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll(PRECACHE.map((url) => new Request(url, { cache: 'reload' }))))
            // Nothing user-specific is cached, so a new worker can take over at once
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith('pocketdev-') && key !== SHELL_CACHE && key !== ASSET_CACHE) {
                await caches.delete(key);
            }
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname === '/api' || url.pathname.startsWith('/api/')) return;

    // Page loads: always the live server; offline page only when unreachable
    if (req.mode === 'navigate') {
        if (!isAppPage(url.pathname)) return;
        event.respondWith(
            fetch(req).catch(async () => (await caches.match(OFFLINE_URL)) || Response.error())
        );
        return;
    }

    // Hashed Vite assets never change for a given URL
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(caches.open(ASSET_CACHE).then(async (cache) => {
            const cached = await cache.match(req);
            if (cached) return cached;
            const response = await fetch(req);
            if (response.ok) cache.put(req, response.clone());
            return response;
        }));
        return;
    }

    if (PRECACHE.includes(url.pathname)) {
        event.respondWith(caches.match(url.pathname).then((cached) => cached || fetch(req)));
    }
});

// ============================================================================
// Web Push Notifications
// ============================================================================

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data?.json() ?? {};
    } catch (e) {
        // Malformed payload, use defaults
    }

    const options = {
        body: data.body ?? 'Task completed',
        icon: '/icons/icon-192.png',
        badge: '/icons/favicon-32.png',
        data: { url: data.url ?? '/' },
        tag: data.tag ?? 'pocketdev-complete',
        renotify: true,
        requireInteraction: false,
    };

    event.waitUntil(
        self.registration.showNotification(data.title ?? 'PocketDev', options)
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url ?? '/';
    const fullUrl = self.location.origin + url;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Try to focus an existing PocketDev tab
            for (const client of clientList) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    return client.focus().then(() => {
                        if ('navigate' in client) {
                            return client.navigate(fullUrl);
                        }
                        return clients.openWindow(fullUrl);
                    });
                }
            }
            // No existing tab found, open a new one
            return clients.openWindow(fullUrl);
        })
    );
});
