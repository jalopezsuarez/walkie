/* Walkie — boot. Validates the stored session and enters the app. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api;

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
        } catch (e) {
            W.auth.logoutLocal();
        }
    }

    boot();
})();
