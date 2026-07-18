/* Feature: Pairing — full-screen QR (show + scan) and deep-link claims. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    var _scanner = null;
    var _pollT = null;

    function cleanup() {
        if (_scanner) { _scanner.stop(); _scanner = null; }
        if (_pollT) { clearInterval(_pollT); _pollT = null; }
    }

    function open() { show('mine'); }

    function show(tab) {
        cleanup();
        var tabs = el('div', { class: 'ov-tabs' }, [
            el('button', { class: tab === 'mine' ? 'active' : '', text: 'Mi código', onclick: function () { show('mine'); } }),
            el('button', { class: tab === 'scan' ? 'active' : '', text: 'Escanear', onclick: function () { show('scan'); } })
        ]);
        var body = el('div', { class: 'ov-body' }, [el('div', { class: 'spinner light' })]);

        W.openOverlay(el('div', { class: 'screen' }, [
            el('div', { class: 'ov-top' }, [
                tabs,
                el('span', { class: 'spacer' }),
                el('button', { class: 'iconbtn', html: W.ICON.close, onclick: W.closeOverlay })
            ]),
            body
        ]), cleanup);

        tab === 'mine' ? myCode(body) : scanner(body);
    }

    /* My full-screen QR for the other person to scan. */
    async function myCode(body) {
        try {
            var r = await Api.createQr();
            W.clear(body);
            body.appendChild(el('div', { class: 'qr-card', html: r.qr_svg }));
            body.appendChild(el('h2', { text: 'Escanéame' }));
            body.appendChild(el('p', { text: 'Pide a la otra persona que abra Walkie, pulse «Escanear» y apunte a este código.' }));

            // Auto-close as soon as the other side completes the pairing.
            var before = W.state.links.length;
            _pollT = setInterval(async function () {
                try {
                    var lk = await Api.links();
                    if ((lk.links || []).length > before) {
                        W.state.links = lk.links;
                        W.toast('¡Vinculado!');
                        W.closeOverlay();
                        W.contacts.go();
                    }
                } catch (e) { cleanup(); }
            }, 2500);
        } catch (e) {
            W.clear(body);
            body.appendChild(el('p', { text: W.errMsg(e) }));
        }
    }

    /* Camera scanner (native BarcodeDetector) with manual fallback. */
    function scanner(body) {
        W.clear(body);
        if (!window.QrScanner.supported()) { manual(body); return; }

        var video = el('video', { id: 'scanner-video', muted: 'true', playsinline: 'true' });
        body.appendChild(video);
        body.appendChild(el('p', { class: 'scan-hint', text: 'Apunta al código QR de la otra persona.' }));
        body.appendChild(el('button', { class: 'link-btn', style: 'color:#b9b9be', text: 'Introducir código manualmente', onclick: function () { manual(body); } }));

        _scanner = window.QrScanner.create();
        _scanner.start(video, claim, function () { manual(body); });
    }

    function manual(body) {
        if (_scanner) { _scanner.stop(); _scanner = null; }
        W.clear(body);
        var input = el('input', { class: 'input', style: 'max-width:320px', placeholder: 'Pega el enlace o código' });
        body.appendChild(el('h2', { text: 'Vincular manualmente' }));
        body.appendChild(el('p', { text: 'Pega el enlace del QR de la otra persona.' }));
        body.appendChild(input);
        body.appendChild(el('button', { class: 'btn', text: 'Vincular', onclick: function () {
            var token = window.QrScanner.extractToken(input.value);
            if (!token) { W.toast('Código no válido'); return; }
            claim(token);
        } }));
    }

    async function claim(token) {
        try {
            var r = await Api.claim(token);
            W.toast('¡Vinculado con ' + r.link.display_name + '!');
            W.closeOverlay();
            W.contacts.go();
        } catch (e) {
            W.toast(W.errMsg(e));
            show('scan'); // let them retry
        }
    }

    /* Deep link support: /web/#p=TOKEN (QR opened in the browser). */
    function claimFromHash() {
        var token = window.QrScanner.extractToken(location.hash || '');
        if (!token) return;
        history.replaceState(null, '', location.pathname + location.search);
        claim(token);
    }

    W.pairing = { open: open, claimFromHash: claimFromHash };
})();
