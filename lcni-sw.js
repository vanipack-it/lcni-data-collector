/**
 * LCNI Service Worker — Web Push Notifications
 *
 * File này cần được serve tại root domain: https://yourdomain.com/lcni-sw.js
 * Cách dễ nhất: tạo file wp-content/plugins/lcni-data-collector/lcni-sw.js
 * rồi dùng rewrite rule hoặc WordPress hook để serve tại /lcni-sw.js
 *
 * Hoặc copy file này vào thư mục gốc của WordPress (cùng cấp với wp-config.php)
 * và truy cập https://yourdomain.com/lcni-sw.js
 */

self.addEventListener('push', function(event) {
    if (!event.data) return;

    var data;
    try { data = event.data.json(); }
    catch(e) { data = { title: 'Tín hiệu mới', body: event.data.text() }; }

    var title   = data.title || '📈 LCNI Signal';
    var options = {
        body:    data.body    || 'Có tín hiệu mới từ rule bạn theo dõi.',
        icon:    data.icon    || '/favicon.ico',
        badge:   '/favicon.ico',
        tag:     data.tag     || 'lcni-signal',
        data:    { url: data.url || '/' },
        actions: [
            { action: 'view', title: 'Xem ngay' },
            { action: 'dismiss', title: 'Bỏ qua' },
        ],
        requireInteraction: false,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    if (event.action === 'dismiss') return;

    var url = (event.notification.data && event.notification.data.url) || '/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            for (var i = 0; i < list.length; i++) {
                if (list[i].url === url && 'focus' in list[i]) {
                    return list[i].focus();
                }
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});

self.addEventListener('install', function() { self.skipWaiting(); });
self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});
