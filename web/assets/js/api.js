/* Walkie API client — thin fetch wrapper with bearer-token handling. */
(function () {
    'use strict';

    var BASE = ((document.body && document.body.dataset.api) || window.WALKIE_API || '/api').replace(/\/$/, '');
    var TOKEN_KEY = 'walkie.token';
    var USER_KEY = 'walkie.user';

    function getToken() { return localStorage.getItem(TOKEN_KEY); }
    function setToken(t) { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); }
    function getUser() { try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch (e) { return null; } }
    function setUser(u) { u ? localStorage.setItem(USER_KEY, JSON.stringify(u)) : localStorage.removeItem(USER_KEY); }

    function ApiError(status, code, message, retryAfter) {
        this.status = status; this.code = code; this.message = message || code; this.retryAfter = retryAfter;
    }
    ApiError.prototype = Object.create(Error.prototype);

    async function request(method, path, body, extra) {
        var headers = { 'Accept': 'application/json' };
        var token = getToken();
        if (token) headers['Authorization'] = 'Bearer ' + token;
        var opts = { method: method, headers: headers };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        if (extra && extra.signal) opts.signal = extra.signal;

        var res;
        try {
            res = await fetch(BASE + path, opts);
        } catch (e) {
            if (e && e.name === 'AbortError') throw new ApiError(-1, 'aborted', 'Aborted');
            throw new ApiError(0, 'network', 'No connection');
        }

        if (res.status === 204) return null;

        var data = null;
        try { data = await res.json(); } catch (e) { /* ignore */ }

        if (!res.ok) {
            var code = (data && data.error) || 'error';
            var msg = (data && data.message) || res.statusText;
            var retry = data && data.retry_after;
            if (res.status === 401) { setToken(null); setUser(null); }
            throw new ApiError(res.status, code, msg, retry);
        }
        return data;
    }

    window.Api = {
        BASE: BASE,
        ApiError: ApiError,
        getToken: getToken, setToken: setToken,
        getUser: getUser, setUser: setUser,
        isAuthed: function () { return !!getToken(); },

        requestCode: function (email) { return request('POST', '/auth/request-code', { email: email }); },
        verify: function (email, code) { return request('POST', '/auth/verify', { email: email, code: code }); },
        logout: function () { return request('POST', '/auth/logout'); },

        me: function () { return request('GET', '/me'); },
        updateMe: function (patch) { return request('PATCH', '/me', patch); },

        createQr: function () { return request('POST', '/link/qr'); },
        claim: function (token) { return request('POST', '/link/claim', { token: token }); },
        links: function () { return request('GET', '/links'); },
        unlink: function (linkId) { return request('DELETE', '/links/' + linkId); },

        messages: function (linkId, after, opts) {
            var q = [];
            if (after) q.push('after=' + after);
            if (opts && opts.wait) q.push('wait=1');
            return request('GET', '/links/' + linkId + '/messages' + (q.length ? '?' + q.join('&') : ''), undefined, opts);
        },
        sendText: function (linkId, text) {
            return request('POST', '/links/' + linkId + '/messages', { type: 'text', text: text });
        },
        sendAudio: function (linkId, base64, mime, durationMs) {
            return request('POST', '/links/' + linkId + '/messages', {
                type: 'audio', audio: base64, mime: mime, duration_ms: durationMs
            });
        },
        deleteMessage: function (linkId, msgId) {
            return request('DELETE', '/links/' + linkId + '/messages/' + msgId);
        },
        statuses: function (linkId) {
            return request('GET', '/links/' + linkId + '/statuses');
        },
        markRead: function (linkId, ids) {
            return request('POST', '/links/' + linkId + '/read', { ids: ids });
        }
    };
})();
