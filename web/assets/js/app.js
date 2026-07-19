/* Walkie — boot + global new-message notifier. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, Notify = window.Notify;

    /* ---- notifier: watches unread counts app-wide and fires notifications ---- */
    var timer = null, prev = null;

    function startNotifier() {
        stopNotifier();
        prev = null;                 // first poll only establishes a baseline
        timer = setInterval(pollNotify, 8000);
    }
    function stopNotifier() { if (timer) { clearInterval(timer); timer = null; } }

    async function pollNotify() {
        if (!Api.isAuthed()) { stopNotifier(); return; }
        if (!Notify || !Notify.enabled()) return;    // nothing to do without permission
        try {
            var links = (await Api.links()).links || [];
            var cur = {};
            links.forEach(function (l) { cur[l.link_id] = l; });
            if (prev) {
                links.forEach(function (l) {
                    var before = prev[l.link_id] ? prev[l.link_id].unread : 0;
                    if (l.unread > before) {
                        var viewing = W.state.current && W.state.current.link_id === l.link_id && !document.hidden;
                        if (!viewing) {
                            Notify.show(
                                l.display_name,
                                l.unread > 1 ? l.unread + ' mensajes nuevos' : 'Nuevo mensaje',
                                'walkie-' + l.link_id,
                                function () { W.conversation.open(l); }
                            );
                        }
                    }
                });
            }
            prev = cur;
        } catch (e) { /* ignore */ }
    }

    W.notifier = { start: startNotifier, stop: stopNotifier };

    /* ---- boot ---- */
    async function boot() {
        if (!Api.isAuthed()) {
            W.auth.screen('email');
            return;
        }
        try {
            var me = await Api.me();
            W.state.user = me.user;
            Api.setUser(me.user);
            W.contacts.go();
            startNotifier();
        } catch (e) {
            W.auth.logoutLocal();
        }
    }

    boot();
})();
