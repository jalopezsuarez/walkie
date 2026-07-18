/* Feature: Pairing — show my full-screen QR only. It rotates every minute
   (a live countdown below it) so a captured screenshot quickly goes stale.
   The other person pairs by scanning it with their phone camera, which opens
   the /web/#p=TOKEN deep link (handled by claimFromHash). */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    var _timer = null;
    var _pollT = null;
    var _before = 0;

    function cleanup() {
        if (_timer) { clearInterval(_timer); _timer = null; }
        if (_pollT) { clearInterval(_pollT); _pollT = null; }
    }

    function open() {
        cleanup();
        var body = el('div', { class: 'ov-body', id: 'pair-body' }, [el('div', { class: 'spinner light' })]);
        W.openOverlay(el('div', { class: 'screen' }, [
            el('div', { class: 'ov-top' }, [
                el('span', { class: 'spacer' }),
                el('button', { class: 'iconbtn', html: W.ICON.close, onclick: W.closeOverlay })
            ]),
            body
        ]), cleanup);

        _before = W.state.links.length;
        refresh(body);
        _pollT = setInterval(pollLinked, 2500);
    }

    // Fetch a fresh QR and (re)start the countdown.
    async function refresh(body) {
        if (_timer) { clearInterval(_timer); _timer = null; }
        try {
            var r = await Api.createQr();
            W.clear(body);
            var timer = el('div', { class: 'qr-timer' });
            body.appendChild(el('div', { class: 'qr-card', html: r.qr_svg }));
            body.appendChild(el('h2', { text: 'Escanéame' }));
            body.appendChild(el('p', { text: 'Que la otra persona apunte la cámara de su móvil a este código.' }));
            body.appendChild(timer);
            countdown(timer, r.expires_in || 60, body);
        } catch (e) {
            W.clear(body);
            body.appendChild(el('p', { text: W.errMsg(e) }));
        }
    }

    function countdown(elm, secs, body) {
        var left = secs;
        function tick() {
            if (left <= 0) {
                clearInterval(_timer); _timer = null;
                refresh(body);            // rotate to a new QR
                return;
            }
            var m = Math.floor(left / 60), s = left % 60;
            elm.textContent = 'Nuevo código en ' + m + ':' + ('0' + s).slice(-2);
            left--;
        }
        tick();
        _timer = setInterval(tick, 1000);
    }

    async function pollLinked() {
        try {
            var lk = await Api.links();
            if ((lk.links || []).length > _before) {
                W.state.links = lk.links;
                W.toast('¡Vinculado!');
                W.closeOverlay();
                W.contacts.go();
            }
        } catch (e) { /* keep trying */ }
    }

    async function claim(token) {
        try {
            var r = await Api.claim(token);
            W.toast('¡Vinculado con ' + r.link.display_name + '!');
            W.closeOverlay();
            W.contacts.go();
        } catch (e) {
            W.toast(W.errMsg(e));
        }
    }

    // Deep link: /web/#p=TOKEN (the other person scanned my QR with their camera).
    function claimFromHash() {
        var token = window.QrScanner.extractToken(location.hash || '');
        if (!token) return;
        history.replaceState(null, '', location.pathname + location.search);
        claim(token);
    }

    W.pairing = { open: open, claimFromHash: claimFromHash };
})();
