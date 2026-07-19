/* Walkie API client — OAuth 2.0 Bearer (JWT access token + refresh). */
(function () {
    'use strict';

    var BASE = ((document.body && document.body.dataset.api) || window.WALKIE_API || '/api').replace(/\/$/, '');
    var ACCESS_KEY = 'walkie.access';
    var REFRESH_KEY = 'walkie.refresh';
    var USER_KEY = 'walkie.user';
    var EMAIL_CODE_GRANT = 'urn:walkie:params:oauth:grant-type:email-code';

    function getAccess() { return localStorage.getItem(ACCESS_KEY); }
    function getRefresh() { return localStorage.getItem(REFRESH_KEY); }
    function getUser() { try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch (e) { return null; } }
    function setUser(u) { u ? localStorage.setItem(USER_KEY, JSON.stringify(u)) : localStorage.removeItem(USER_KEY); }

    function setTokens(access, refresh) {
        access ? localStorage.setItem(ACCESS_KEY, access) : localStorage.removeItem(ACCESS_KEY);
        refresh ? localStorage.setItem(REFRESH_KEY, refresh) : localStorage.removeItem(REFRESH_KEY);
    }
    function clearSession() { setTokens(null, null); setUser(null); }

    function ApiError(status, code, message, retryAfter) {
        this.status = status; this.code = code; this.message = message || code; this.retryAfter = retryAfter;
    }
    ApiError.prototype = Object.create(Error.prototype);

    /* POST an application/x-www-form-urlencoded body (OAuth token/revoke). */
    async function postForm(path, params) {
        var res;
        try {
            res = await fetch(BASE + path, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params).toString()
            });
        } catch (e) { throw new ApiError(0, 'network', 'No connection'); }
        var data = null;
        try { data = await res.json(); } catch (e) { /* ignore */ }
        if (!res.ok) {
            throw new ApiError(res.status, (data && data.error) || 'error', (data && data.error_description) || res.statusText);
        }
        return data;
    }

    /* Single in-flight refresh shared by concurrent 401s. */
    var _refreshing = null;
    function refresh() {
        if (_refreshing) return _refreshing;
        var token = getRefresh();
        if (!token) return Promise.resolve(false);
        _refreshing = postForm('/oauth/token', { grant_type: 'refresh_token', refresh_token: token })
            .then(function (t) { setTokens(t.access_token, t.refresh_token); return true; })
            .catch(function () { clearSession(); return false; })
            .then(function (ok) { _refreshing = null; return ok; });
        return _refreshing;
    }

    async function request(method, path, body, extra, _retried) {
        var headers = { 'Accept': 'application/json' };
        var token = getAccess();
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

        // Access token expired → refresh once and retry the original request.
        if (res.status === 401 && !_retried && getRefresh()) {
            if (await refresh()) return request(method, path, body, extra, true);
        }

        if (res.status === 204) return null;

        var data = null;
        try { data = await res.json(); } catch (e) { /* ignore */ }

        if (!res.ok) {
            var code = (data && data.error) || 'error';
            var msg = (data && data.message) || res.statusText;
            if (res.status === 401) clearSession();
            throw new ApiError(res.status, code, msg, data && data.retry_after);
        }
        return data;
    }

    window.Api = {
        getUser: getUser, setUser: setUser,
        clearSession: clearSession,
        isAuthed: function () { return !!getAccess(); },

        requestCode: function (email) { return request('POST', '/auth/request-code', { email: email }); },

        /* Exchange the emailed code for tokens (OAuth2 email-code grant), then
           load and store the profile. Returns the user. */
        login: async function (email, code) {
            var t = await postForm('/oauth/token', { grant_type: EMAIL_CODE_GRANT, email: email, code: code });
            setTokens(t.access_token, t.refresh_token);
            var me = await request('GET', '/me');
            setUser(me.user);
            return me.user;
        },
        logout: async function () {
            var token = getRefresh();
            if (token) { try { await postForm('/oauth/revoke', { token: token }); } catch (e) { /* best effort */ } }
            clearSession();
        },

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
