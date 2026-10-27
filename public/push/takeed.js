/*!
 * Takeed web SDK v1.
 * Standard Web Push (no Firebase). Same shape as OneSignal's web SDK v16:
 *
 *   <script src="https://takeed-iq.com/sdk/v1/takeed.js" defer></script>
 *   <script>
 *     window.TakeedDeferred = window.TakeedDeferred || [];
 *     TakeedDeferred.push(async (Takeed) => {
 *       await Takeed.init({ appId: '...', serviceWorkerPath: '/push/TakeedSDKWorker.js' });
 *     });
 *   </script>
 */
(function (global) {
    'use strict';

    var STORE = 'takeed:';
    var SDK_VERSION = 'takeed-web/1.0.0';
    var SESSION_GAP_MS = 30 * 60 * 1000;

    function storage() {
        try {
            return global.localStorage;
        } catch (e) {
            return null;
        }
    }

    function load(key) {
        try {
            var v = storage() && storage().getItem(STORE + key);
            return v ? JSON.parse(v) : null;
        } catch (e) {
            return null;
        }
    }

    function save(key, value) {
        try {
            if (!storage()) return;
            if (value === null || value === undefined) storage().removeItem(STORE + key);
            else storage().setItem(STORE + key, JSON.stringify(value));
        } catch (e) {
            /* private mode: keep going without persistence */
        }
    }

    function urlBase64ToUint8Array(base64) {
        var padding = '='.repeat((4 - (base64.length % 4)) % 4);
        var raw = global.atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function arrayBufferToBase64Url(buffer) {
        if (!buffer) return null;
        var bytes = new Uint8Array(buffer);
        var s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return global.btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function browserName(ua) {
        ua = ua || '';
        if (/Edg\//.test(ua)) return 'Edge';
        if (/Firefox\//.test(ua)) return 'Firefox';
        if (/Chrome\//.test(ua)) return 'Chrome';
        if (/Safari\//.test(ua)) return 'Safari';
        return 'Other';
    }

    function scriptOrigin() {
        var current = global.document && global.document.currentScript;
        try {
            return current && current.src ? new URL(current.src).origin : null;
        } catch (e) {
            return null;
        }
    }

    var detectedApiOrigin = scriptOrigin();

    function createSdk() {
        var options = null;
        var config = null;
        var registration = null;
        var listeners = { click: [], permissionChange: [], foregroundWillDisplay: [] };
        var ready = null;
        // Calls made before init() wait for it instead of failing.
        var resolveInit;
        var initCalled = new Promise(function (resolve) {
            resolveInit = resolve;
        });

        function api(path, method, body) {
            var url = options.apiUrl.replace(/\/$/, '') + '/api/v1/apps/' + encodeURIComponent(options.appId) + path;
            return global
                .fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: body ? JSON.stringify(body) : undefined,
                    credentials: 'omit',
                })
                .then(function (res) {
                    return res.json().then(function (json) {
                        if (!res.ok) {
                            var err = new Error((json.errors && json.errors[0] && json.errors[0].message) || 'Takeed request failed');
                            err.code = json.errors && json.errors[0] && json.errors[0].code;
                            err.status = res.status;
                            throw err;
                        }
                        return json;
                    });
                });
        }

        function supported() {
            return !!(global.navigator && 'serviceWorker' in global.navigator && 'PushManager' in global && 'Notification' in global);
        }

        function permissionGranted() {
            return supported() && global.Notification.permission === 'granted';
        }

        function deviceFields() {
            var nav = global.navigator || {};
            var tz = null;
            try {
                tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            } catch (e) {}
            return {
                language: (nav.language || 'en').slice(0, 2),
                timezone: tz,
                browser: browserName(nav.userAgent),
                device_model: browserName(nav.userAgent),
                os_version: nav.platform || null,
                sdk_version: SDK_VERSION,
            };
        }

        function identity(externalId, hash) {
            var body = { external_id: externalId };
            if (hash) body.identity_hash = hash;
            return body;
        }

        /** Subscribe with the browser and register (or refresh) the device. Silent when permission is already granted. */
        function subscribe() {
            if (!permissionGranted() || !registration) return Promise.resolve(null);
            return registration.pushManager
                .getSubscription()
                .then(function (existing) {
                    return (
                        existing ||
                        registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(config.vapid_public_key),
                        })
                    );
                })
                .then(function (sub) {
                    var json = sub.toJSON ? sub.toJSON() : {};
                    var keys = json.keys || {
                        p256dh: arrayBufferToBase64Url(sub.getKey && sub.getKey('p256dh')),
                        auth: arrayBufferToBase64Url(sub.getKey && sub.getKey('auth')),
                    };
                    var pending = load('external_id');
                    var body = Object.assign(
                        { type: 'web', token: sub.endpoint, web_p256dh: keys.p256dh, web_auth: keys.auth, notification_permission: !load('opted_out') },
                        deviceFields(),
                        options.branch ? { branch: options.branch } : {},
                        pending ? identity(pending.id, pending.hash) : {},
                    );
                    return api('/subscriptions', 'POST', body);
                })
                .then(function (res) {
                    save('subscription_id', res.id);
                    save('user_id', res.user_id);
                    save('last_session', Date.now());
                    return res;
                });
        }

        function update(body) {
            var id = load('subscription_id');
            if (!id) return Promise.resolve(null);
            return api('/subscriptions/' + encodeURIComponent(id), 'PATCH', body).catch(function (err) {
                if (err.status === 404) save('subscription_id', null);
                throw err;
            });
        }

        function pingSession() {
            var last = load('last_session') || 0;
            if (!load('subscription_id') || Date.now() - last < SESSION_GAP_MS) return Promise.resolve();
            save('last_session', Date.now());
            return update(Object.assign({ session: true }, deviceFields())).catch(function () {});
        }

        function init(opts) {
            if (ready) return ready;
            if (!opts || !opts.appId) return Promise.reject(new Error('Takeed: appId is required'));

            options = Object.assign(
                {
                    apiUrl: detectedApiOrigin || global.location.origin,
                    serviceWorkerPath: '/TakeedSDKWorker.js',
                    serviceWorkerScope: undefined,
                    branch: null,
                },
                opts,
            );

            if (!supported()) {
                ready = Promise.resolve(false);
                resolveInit(ready);
                return ready;
            }

            ready = api('/sdk-config', 'GET')
                .then(function (cfg) {
                    config = cfg;
                    var workerUrl =
                        options.serviceWorkerPath +
                        (options.serviceWorkerPath.indexOf('?') === -1 ? '?' : '&') +
                        'appId=' + encodeURIComponent(options.appId) +
                        '&api=' + encodeURIComponent(options.apiUrl);
                    var regOptions = options.serviceWorkerScope ? { scope: options.serviceWorkerScope } : undefined;
                    return global.navigator.serviceWorker.register(workerUrl, regOptions);
                })
                .then(function (reg) {
                    registration = reg;
                    global.navigator.serviceWorker.addEventListener('message', function (event) {
                        var msg = event.data || {};
                        if (msg.type === 'takeed:click') emit('click', msg.payload);
                        if (msg.type === 'takeed:foreground') emit('foregroundWillDisplay', msg.payload);
                    });
                    return subscribe();
                })
                .then(function () {
                    return pingSession();
                })
                .then(function () {
                    return true;
                });
            resolveInit(ready);

            return ready;
        }

        function emit(name, payload) {
            listeners[name].forEach(function (fn) {
                try {
                    fn(payload);
                } catch (e) {
                    if (global.console) global.console.error(e);
                }
            });
        }

        function requireInit() {
            return ready || initCalled;
        }

        var sdk = {
            VERSION: SDK_VERSION,
            init: init,

            login: function (externalId, identityHash) {
                if (!externalId) return Promise.reject(new Error('Takeed: externalId is required'));
                save('external_id', { id: String(externalId), hash: identityHash || null });
                return requireInit().then(function () {
                    return update(identity(String(externalId), identityHash));
                });
            },

            logout: function () {
                save('external_id', null);
                return requireInit().then(function () {
                    return update({ external_id: null });
                });
            },

            Notifications: {
                get permission() {
                    return permissionGranted();
                },
                get permissionNative() {
                    return supported() ? global.Notification.permission : 'denied';
                },
                isPushSupported: supported,
                requestPermission: function () {
                    if (!supported()) return Promise.resolve(false);
                    return Promise.resolve(global.Notification.requestPermission()).then(function (result) {
                        var granted = result === 'granted';
                        emit('permissionChange', granted);
                        return requireInit().then(function () {
                            return granted ? subscribe().then(function () { return true; }) : update({ notification_permission: false }).then(function () { return false; });
                        });
                    });
                },
                addEventListener: function (name, fn) {
                    if (listeners[name]) listeners[name].push(fn);
                },
                removeEventListener: function (name, fn) {
                    if (listeners[name]) listeners[name] = listeners[name].filter(function (f) { return f !== fn; });
                },
            },

            User: {
                addTag: function (key, value) {
                    var tags = {};
                    tags[key] = String(value);
                    return requireInit().then(function () { return update({ tags: tags }); });
                },
                addTags: function (tags) {
                    var clean = {};
                    Object.keys(tags || {}).forEach(function (k) { clean[k] = String(tags[k]); });
                    return requireInit().then(function () { return update({ tags: clean }); });
                },
                removeTag: function (key) {
                    var tags = {};
                    tags[key] = null;
                    return requireInit().then(function () { return update({ tags: tags }); });
                },
                removeTags: function (keys) {
                    var tags = {};
                    (keys || []).forEach(function (k) { tags[k] = null; });
                    return requireInit().then(function () { return update({ tags: tags }); });
                },
                setLanguage: function (language) {
                    return requireInit().then(function () { return update({ language: language }); });
                },
                setEmail: function (email) {
                    return requireInit().then(function () { return update({ email: email }); });
                },
                get onesignalId() {
                    return load('user_id');
                },
                get externalId() {
                    var e = load('external_id');
                    return e ? e.id : null;
                },
                PushSubscription: {
                    get id() {
                        return load('subscription_id');
                    },
                    get optedIn() {
                        return permissionGranted() && !load('opted_out') && !!load('subscription_id');
                    },
                    optIn: function () {
                        save('opted_out', null);
                        return requireInit().then(function () {
                            return permissionGranted() ? update({ notification_permission: true }) : sdk.Notifications.requestPermission();
                        });
                    },
                    optOut: function () {
                        save('opted_out', true);
                        return requireInit().then(function () { return update({ notification_permission: false }); });
                    },
                },
            },

            // Exposed for tests.
            _internals: { urlBase64ToUint8Array: urlBase64ToUint8Array, browserName: browserName },
        };

        return sdk;
    }

    var Takeed = createSdk();
    global.Takeed = Takeed;

    // Run callbacks queued before the script loaded, and any pushed later.
    var deferred = global.TakeedDeferred || [];
    var run = function (fn) {
        try {
            var r = fn(Takeed);
            if (r && typeof r.catch === 'function') r.catch(function (e) { if (global.console) global.console.error(e); });
        } catch (e) {
            if (global.console) global.console.error(e);
        }
    };
    if (Array.isArray(deferred)) deferred.forEach(run);
    global.TakeedDeferred = { push: run };

    if (typeof module !== 'undefined' && module.exports) module.exports = { createSdk: createSdk };
})(typeof window !== 'undefined' ? window : globalThis);
