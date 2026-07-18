/* Walkie — native browser notifications (Web Notifications API).
   Works where the browser supports it: desktop, Android Chrome, and installed
   PWAs on iOS/iPadOS 16.4+. No-ops gracefully elsewhere. */
(function () {
    'use strict';

    var PREF = 'walkie.notify';

    function supported() {
        return typeof window !== 'undefined' && 'Notification' in window;
    }
    function permission() {
        return supported() ? Notification.permission : 'unsupported';
    }
    function enabled() {
        return supported() && Notification.permission === 'granted' && localStorage.getItem(PREF) !== 'off';
    }
    function setPref(on) {
        localStorage.setItem(PREF, on ? 'on' : 'off');
    }

    // Must be called from a user gesture on most browsers.
    function request() {
        if (!supported()) return Promise.resolve('unsupported');
        try {
            var p = Notification.requestPermission();
            // Older Safari uses a callback signature.
            if (p && typeof p.then === 'function') return p;
            return new Promise(function (res) { Notification.requestPermission(res); });
        } catch (e) {
            return Promise.resolve(permission());
        }
    }

    function show(title, body, tag, onClick) {
        if (!enabled()) return null;
        try {
            var n = new Notification(title, {
                body: body,
                tag: tag,
                icon: 'assets/icons/icon-192.png',
                badge: 'assets/icons/icon-192.png',
                renotify: true
            });
            n.onclick = function () {
                try { window.focus(); } catch (e) {}
                if (typeof onClick === 'function') onClick();
                n.close();
            };
            return n;
        } catch (e) {
            return null;
        }
    }

    window.Notify = {
        supported: supported,
        permission: permission,
        enabled: enabled,
        setPref: setPref,
        request: request,
        show: show
    };
})();
