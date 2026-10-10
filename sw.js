/**
 * Service worker of the online customers' page (online.php, scope /online): shows the
 * Web Push notifications sent by includes/web_push.php — also with the page and the
 * browser closed — and opens the page (or a promotion's link) when one is tapped.
 * No fetch handler: it never sits between the page and the network.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
    let m = {};
    try { m = e.data ? e.data.json() : {}; } catch (err) { m = { body: e.data ? e.data.text() : '' }; }
    e.waitUntil(self.registration.showNotification(m.title || '', {
        body: m.body || '',
        icon: '/app-icon.php?s=192',
        tag: m.tag || undefined,
        renotify: !!m.tag,
        vibrate: [200, 100, 200],
        data: { url: m.url || '/online.php' },
    }));
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    const url = new URL((e.notification.data && e.notification.data.url) || '/online.php', self.location.origin).href;
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async list => {
        // The page already open: back to it, on what was tapped (a promotion: its offer).
        for (const c of list) {
            if (c.url.split('?')[0] !== url.split('?')[0] || !('focus' in c)) continue;
            await c.focus();
            if (c.url !== url && 'navigate' in c) {
                try { await c.navigate(url); } catch (err) { return self.clients.openWindow(url); }
            }
            return;
        }
        return self.clients.openWindow(url);
    }));
});
