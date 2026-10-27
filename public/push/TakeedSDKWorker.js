/*!
 * Takeed service worker v1. Host this file on your own site (same origin as the
 * pages), e.g. /push/TakeedSDKWorker.js, and pass its path to Takeed.init().
 * The same file works on every subdomain.
 */
'use strict';

var params = new URL(self.location.href).searchParams;
var APP_ID = params.get('appId');
var API = (params.get('api') || '').replace(/\/$/, '');

function report(type, receiptId, buttonId) {
    if (!APP_ID || !API || !receiptId) return Promise.resolve();
    return fetch(API + '/api/v1/apps/' + encodeURIComponent(APP_ID) + '/events', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: type, receipt_id: receiptId, button_id: buttonId || undefined }),
        credentials: 'omit',
    }).catch(function () {});
}

// Relative links open on the site the user subscribed from (each company's own subdomain).
// Only http(s) links are followed.
function safeUrl(url) {
    if (!url) return null;
    try {
        var resolved = new URL(url, self.location.origin);
        return resolved.protocol === 'https:' || resolved.protocol === 'http:' ? resolved.href : null;
    } catch (e) {
        return null;
    }
}

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    if (!event.data) return;

    var payload;
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: event.data.text() };
    }

    var buttons = Array.isArray(payload.buttons) ? payload.buttons.slice(0, 2) : [];
    var options = {
        body: payload.body || '',
        icon: payload.icon,
        image: payload.image,
        badge: payload.badge,
        tag: payload.tag || payload.notification_id,
        renotify: !!payload.tag,
        data: { url: payload.url, buttons: buttons, receipt_id: payload.receipt_id, notification_id: payload.notification_id, data: payload.data || {} },
        actions: buttons.map(function (b) {
            return { action: String(b.id), title: String(b.text), icon: b.icon };
        }),
    };

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(payload.title || '', options),
            report('received', payload.receipt_id),
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
                clients.forEach(function (c) {
                    c.postMessage({ type: 'takeed:foreground', payload: payload });
                });
            }),
        ]),
    );
});

self.addEventListener('notificationclick', function (event) {
    var data = event.notification.data || {};
    var button = (data.buttons || []).find(function (b) {
        return String(b.id) === event.action;
    });
    var url = safeUrl((button && button.url) || data.url);
    event.notification.close();

    var payload = { notification_id: data.notification_id, action_id: event.action || null, url: url, additional_data: data.data };

    event.waitUntil(
        Promise.all([
            report('clicked', data.receipt_id, event.action),
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
                clients.forEach(function (c) {
                    c.postMessage({ type: 'takeed:click', payload: payload });
                });
                if (!url) return clients[0] && clients[0].focus ? clients[0].focus() : null;
                var existing = clients.find(function (c) {
                    return c.url === url;
                });
                return existing && existing.focus ? existing.focus() : self.clients.openWindow(url);
            }),
        ]),
    );
});
