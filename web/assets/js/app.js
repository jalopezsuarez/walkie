/* Walkie — app controller. Vanilla JS, no dependencies.
   All user-supplied strings are inserted via textContent to prevent XSS. */
(function () {
    'use strict';

    var Api = window.Api;
    var appEl = document.getElementById('app');
    var overlayEl = document.getElementById('qr-overlay');
    var toastEl = document.getElementById('toast');

    var state = {
        user: Api.getUser(),
        links: [],
        current: null,      // { link_id, user_id, display_name }
        lastMsgId: 0,
        poll: null,
        listPoll: null,
        recorder: null,
        pending: null       // { blob, mime, durationMs, url }
    };

    /* ---------------- helpers ---------------- */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            if (k === 'class') node.className = attrs[k];
            else if (k === 'text') node.textContent = attrs[k];
            else if (k === 'html') node.innerHTML = attrs[k];
            else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') node.addEventListener(k.slice(2), attrs[k]);
            else if (attrs[k] != null) node.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { if (c != null) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return node;
    }
    function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
    function mount(node) { clear(appEl); appEl.appendChild(node); }
    function initials(name) { return (name || '?').trim().charAt(0).toUpperCase() || '?'; }
    function fmtTime(iso) {
        var d = new Date(iso); if (isNaN(d)) return '';
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    var _toastT;
    function toast(msg) {
        toastEl.textContent = msg; toastEl.classList.remove('hidden');
        clearTimeout(_toastT); _toastT = setTimeout(function () { toastEl.classList.add('hidden'); }, 2600);
    }
    function errMsg(e) {
        if (!e) return 'Error';
        if (e.code === 'rate_limited') return 'Demasiados intentos, espera un momento.';
        if (e.code === 'network') return 'Sin conexión.';
        return e.message || 'Error';
    }
    var ICON = {
        gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3.2"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>',
        back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>',
        close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>',
        mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M6 11a6 6 0 0 0 12 0M12 17v4"/></svg>',
        send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 12l16-8-6 8 6 8-16-8z"/></svg>',
        play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
        pause: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>',
        trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/></svg>',
        people: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5"/><circle cx="17" cy="8" r="2.6"/><path d="M15.5 14.6c2.6.3 4.5 2.4 4.5 5.4"/></svg>',
        logo: '<svg viewBox="0 0 24 24" fill="#fff"><circle cx="12" cy="9" r="3.4"/><rect x="10.5" y="9" width="3" height="9" rx="1.5"/></svg>'
    };
    function stopTimers() {
        if (state.poll) { clearInterval(state.poll); state.poll = null; }
        if (state.listPoll) { clearInterval(state.listPoll); state.listPoll = null; }
    }

    /* ================= AUTH ================= */
    function screenAuth(step, email) {
        step = step || 'email';
        var emailInput, codeInput;

        var brand = el('div', { class: 'brand' }, [
            el('span', { class: 'logo', html: ICON.logo }),
            el('h1', { text: 'Walkie' }),
            el('p', { text: 'Notas de voz y texto, entre dos.' })
        ]);

        var body;
        if (step === 'email') {
            emailInput = el('input', { class: 'input', type: 'email', inputmode: 'email', autocomplete: 'email', placeholder: 'tu@correo.com', value: email || '' });
            var sendBtn = el('button', { class: 'btn block', text: 'Enviar código' });
            async function submit() {
                var val = (emailInput.value || '').trim();
                if (!val) { toast('Escribe tu correo'); return; }
                sendBtn.disabled = true; sendBtn.textContent = 'Enviando…';
                try {
                    var r = await Api.requestCode(val);
                    if (r && r.debug_code) toast('Código (debug): ' + r.debug_code);
                    screenAuth('code', val);
                } catch (e) { toast(errMsg(e)); sendBtn.disabled = false; sendBtn.textContent = 'Enviar código'; }
            }
            sendBtn.addEventListener('click', submit);
            emailInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
            body = el('div', { class: 'stack' }, [
                el('div', {}, [el('div', { class: 'field-label', text: 'Correo' }), emailInput]),
                sendBtn,
                el('p', { class: 'muted', text: 'Te enviaremos un código de 6 dígitos.' })
            ]);
        } else {
            codeInput = el('input', { class: 'input', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', placeholder: '••••••', style: 'letter-spacing:.4em;text-align:center;font-size:22px' });
            var verifyBtn = el('button', { class: 'btn block', text: 'Entrar' });
            async function verify() {
                var code = (codeInput.value || '').trim();
                if (!/^\d{6}$/.test(code)) { toast('Código de 6 dígitos'); return; }
                verifyBtn.disabled = true; verifyBtn.textContent = 'Comprobando…';
                try {
                    var r = await Api.verify(email, code);
                    Api.setToken(r.token); Api.setUser(r.user); state.user = r.user;
                    goHome();
                } catch (e) { toast(errMsg(e)); verifyBtn.disabled = false; verifyBtn.textContent = 'Entrar'; }
            }
            verifyBtn.addEventListener('click', verify);
            codeInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') verify(); });
            body = el('div', { class: 'stack' }, [
                el('div', {}, [el('div', { class: 'field-label', text: 'Código enviado a ' + email }), codeInput]),
                verifyBtn,
                el('button', { class: 'link-btn', text: 'Usar otro correo', onclick: function () { screenAuth('email', email); } })
            ]);
        }

        mount(el('div', { class: 'screen' }, [el('div', { class: 'center-screen' }, [brand, body])]));
        setTimeout(function () { (step === 'email' ? emailInput : codeInput).focus(); }, 50);
    }

    /* ================= HOME (contacts) ================= */
    async function goHome() {
        stopTimers();
        state.current = null;
        renderHome();
        await loadLinks();
        state.listPoll = setInterval(loadLinks, 5000);
        // Handle deep-link pairing (#p=TOKEN).
        maybeClaimFromHash();
    }

    function renderHome() {
        var top = el('div', { class: 'topbar' }, [
            el('h1', { text: 'Walkie' }),
            el('span', { class: 'spacer' }),
            el('button', { class: 'iconbtn', title: 'Ajustes', html: ICON.gear, onclick: screenSettings })
        ]);
        var listWrap = el('div', { id: 'list-wrap', class: 'screen' }, [el('div', { class: 'spinner' })]);
        var fab = el('button', { class: 'btn fab', html: ICON.plus + '<span>Vincular</span>', onclick: openPairOverlay });
        mount(el('div', { class: 'screen' }, [top, listWrap, fab]));
        renderLinks();
    }

    async function loadLinks() {
        try {
            var r = await Api.links();
            state.links = r.links || [];
            renderLinks();
        } catch (e) {
            if (e.status === 401) return logoutLocal();
        }
    }

    function renderLinks() {
        var wrap = document.getElementById('list-wrap');
        if (!wrap) return;
        clear(wrap);
        if (!state.links.length) {
            wrap.appendChild(el('div', { class: 'empty' }, [
                el('span', { html: ICON.people }),
                el('div', { text: 'Aún no tienes contactos.' }),
                el('div', { class: 'muted', text: 'Pulsa «Vincular» y escanea el código QR de la otra persona.' })
            ]));
            return;
        }
        var ul = el('ul', { class: 'list' });
        state.links.forEach(function (lk) {
            var sub = lk.unread > 0 ? 'Nuevos mensajes' : 'Toca para hablar';
            var row = el('li', { class: 'contact', onclick: function () { openChat(lk); } }, [
                el('span', { class: 'avatar', text: initials(lk.display_name) }),
                el('div', { class: 'meta' }, [
                    el('div', { class: 'name', text: lk.display_name }),
                    el('div', { class: 'sub', text: sub })
                ]),
                lk.unread > 0 ? el('span', { class: 'badge', text: String(lk.unread) }) : null
            ]);
            ul.appendChild(row);
        });
        wrap.appendChild(ul);
    }

    /* ================= CHAT ================= */
    function openChat(link) {
        stopTimers();
        state.current = link;
        state.lastMsgId = 0;
        state.pending = null;

        var top = el('div', { class: 'topbar' }, [
            el('button', { class: 'iconbtn', html: ICON.back, onclick: goHome }),
            el('span', { class: 'avatar', style: 'width:34px;height:34px;font-size:14px', text: initials(link.display_name) }),
            el('h1', { text: link.display_name, style: 'font-size:17px' }),
            el('span', { class: 'spacer' }),
            el('button', { class: 'iconbtn', title: 'Eliminar contacto', html: ICON.trash, onclick: function () { confirmUnlink(link); } })
        ]);
        var msgs = el('div', { id: 'messages', class: 'messages' }, [el('div', { class: 'spinner' })]);
        var composer = buildComposer(link);

        mount(el('div', { class: 'screen chat' }, [top, msgs, composer]));

        loadMessages(true);
        state.poll = setInterval(function () { loadMessages(false); }, 2500);
    }

    function buildComposer(link) {
        var input = el('input', { id: 'text-input', class: 'input', type: 'text', placeholder: 'Mensaje…', autocomplete: 'off' });
        var ptt = el('button', { id: 'ptt', class: 'ptt', title: 'Mantén pulsado para hablar', html: ICON.mic });
        var sendBtn = el('button', { class: 'ptt', style: 'display:none', html: ICON.send, title: 'Enviar' });

        function refreshButtons() {
            var hasText = input.value.trim().length > 0;
            sendBtn.style.display = hasText ? 'flex' : 'none';
            ptt.style.display = hasText ? 'none' : 'flex';
        }
        input.addEventListener('input', refreshButtons);
        async function sendText() {
            var t = input.value.trim(); if (!t) return;
            input.value = ''; refreshButtons();
            try { await Api.sendText(link.link_id, t); await loadMessages(false); }
            catch (e) { toast(errMsg(e)); }
        }
        sendBtn.addEventListener('click', sendText);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') sendText(); });

        setupPtt(ptt, link);

        return el('div', { id: 'composer', class: 'composer' }, [input, sendBtn, ptt]);
    }

    /* Push-to-talk: hold to record, release to preview. */
    function setupPtt(ptt, link) {
        var rec = window.WalkieAudio.create();
        var holding = false, tooShort = false, startT = 0;

        async function begin(ev) {
            ev.preventDefault();
            if (state.pending) return;
            if (!rec.supported()) { toast('Grabación no disponible'); return; }
            holding = true; startT = Date.now();
            try {
                await rec.start();
                if (!holding) { rec.cancel(); return; } // released before mic ready
                ptt.classList.add('recording');
                showRecIndicator(true);
            } catch (e) { holding = false; toast('Permiso de micrófono denegado'); }
        }
        async function end(ev) {
            if (!holding) return;
            holding = false;
            ev && ev.preventDefault();
            ptt.classList.remove('recording');
            showRecIndicator(false);
            var elapsed = Date.now() - startT;
            var result = await rec.stop();
            if (!result || elapsed < 500) { toast('Mantén pulsado para grabar'); return; }
            showPreview(result, link);
        }
        ptt.addEventListener('pointerdown', begin);
        ptt.addEventListener('pointerup', end);
        ptt.addEventListener('pointercancel', end);
        ptt.addEventListener('pointerleave', function (e) { if (holding) end(e); });
    }

    function showRecIndicator(on) {
        var composer = document.getElementById('composer');
        if (!composer) return;
        var input = document.getElementById('text-input');
        if (input) input.style.visibility = on ? 'hidden' : 'visible';
        var existing = document.getElementById('rec-ind');
        if (on && !existing) {
            var ind = el('div', { id: 'rec-ind', class: 'rec-indicator', style: 'position:absolute;left:24px' }, [
                el('span', { class: 'rec-dot' }), el('span', { text: 'Grabando…' })
            ]);
            composer.style.position = 'relative';
            composer.appendChild(ind);
        } else if (!on && existing) { existing.remove(); }
    }

    function showPreview(result, link) {
        state.pending = result;
        var url = URL.createObjectURL(result.blob);
        var audio = el('audio', { controls: 'true', src: url });
        var discard = el('button', { class: 'iconbtn', html: ICON.trash, title: 'Descartar', onclick: function () {
            URL.revokeObjectURL(url); state.pending = null; restoreComposer(link);
        } });
        var send = el('button', { class: 'btn', text: 'Enviar', onclick: async function () {
            send.disabled = true; send.textContent = 'Enviando…';
            try {
                var b64 = await window.WalkieAudio.blobToBase64(result.blob);
                await Api.sendAudio(link.link_id, b64, result.mime, result.durationMs);
                URL.revokeObjectURL(url); state.pending = null;
                restoreComposer(link); await loadMessages(false);
            } catch (e) { toast(errMsg(e)); send.disabled = false; send.textContent = 'Enviar'; }
        } });
        var bar = el('div', { id: 'composer', class: 'preview' }, [discard, audio, send]);
        var old = document.getElementById('composer');
        if (old) old.replaceWith(bar);
    }
    function restoreComposer(link) {
        var old = document.getElementById('composer');
        if (old) old.replaceWith(buildComposer(link));
    }

    async function loadMessages(initial) {
        if (!state.current) return;
        try {
            var r = await Api.messages(state.current.link_id, state.lastMsgId);
            var box = document.getElementById('messages');
            if (!box) return;
            if (initial) clear(box);
            var incoming = r.messages || [];
            if (incoming.length) {
                var ph = box.querySelector('.empty');
                if (ph) ph.remove();
            }
            incoming.forEach(function (m) {
                if (m.id > state.lastMsgId) state.lastMsgId = m.id;
                box.appendChild(renderMessage(m));
            });
            if (initial && !box.children.length) {
                box.appendChild(el('div', { class: 'empty', style: 'flex:1' }, [
                    el('div', { class: 'muted', text: 'Di algo 👋' })
                ]));
            }
            if ((r.messages || []).length) box.scrollTop = box.scrollHeight;
        } catch (e) {
            if (e.status === 401) return logoutLocal();
            if (e.status === 404) { toast('Conversación eliminada'); goHome(); }
        }
    }

    function renderMessage(m) {
        var cls = 'bubble ' + (m.mine ? 'mine' : 'theirs');
        var bubble = el('div', { class: cls });
        if (m.type === 'text') {
            bubble.appendChild(el('span', { text: m.text }));
        } else {
            bubble.appendChild(renderAudio(m));
        }
        bubble.appendChild(el('span', { class: 'time', text: fmtTime(m.created_at) }));
        if (m.mine) {
            var del = el('button', { class: 'del', html: ICON.close, title: 'Eliminar', onclick: function (e) {
                e.stopPropagation(); deleteMessage(m, bubble);
            } });
            bubble.appendChild(del);
            bubble.addEventListener('click', function () { bubble.classList.toggle('show-del'); });
        }
        return bubble;
    }

    function renderAudio(m) {
        var mime = m.mime || 'audio/webm';
        var audio = new Audio('data:' + mime + ';base64,' + m.audio);
        var btn = el('button', { html: ICON.play });
        var playing = false;
        btn.addEventListener('click', function () {
            if (playing) { audio.pause(); } else { audio.play(); }
        });
        audio.addEventListener('play', function () { playing = true; btn.innerHTML = ICON.pause; });
        audio.addEventListener('pause', function () { playing = false; btn.innerHTML = ICON.play; });
        audio.addEventListener('ended', function () { playing = false; btn.innerHTML = ICON.play; });
        var secs = m.duration_ms ? Math.round(m.duration_ms / 1000) : null;
        return el('div', { class: 'audio-msg' }, [
            btn, el('span', { class: 'wave' }),
            secs ? el('span', { class: 'dur', text: secs + '″' }) : null
        ]);
    }

    async function deleteMessage(m, node) {
        try { await Api.deleteMessage(state.current.link_id, m.id); node.remove(); }
        catch (e) { toast(errMsg(e)); }
    }

    function confirmUnlink(link) {
        openConfirm('Eliminar a ' + link.display_name + '?',
            'Se borrará la conversación para ambos. Para volver a hablar tendréis que vincularos de nuevo con el QR.',
            'Eliminar', async function () {
                try { await Api.unlink(link.link_id); toast('Contacto eliminado'); goHome(); }
                catch (e) { toast(errMsg(e)); }
            });
    }

    /* ================= SETTINGS ================= */
    function screenSettings() {
        stopTimers();
        var u = state.user || {};
        var nameInput = el('input', { class: 'input', type: 'text', maxlength: '60', value: u.display_name || '' });
        var emailInput = el('input', { class: 'input', type: 'email', value: u.email || '' });

        var saveBtn = el('button', { class: 'btn block', text: 'Guardar cambios', onclick: async function () {
            var patch = {};
            var name = nameInput.value.trim(), email = emailInput.value.trim();
            if (name && name !== u.display_name) patch.display_name = name;
            if (email && email !== u.email) patch.email = email;
            if (!Object.keys(patch).length) { toast('Nada que cambiar'); return; }
            saveBtn.disabled = true; saveBtn.textContent = 'Guardando…';
            try {
                var r = await Api.updateMe(patch);
                state.user = r.user; Api.setUser(r.user);
                toast('Guardado');
            } catch (e) { toast(errMsg(e)); }
            saveBtn.disabled = false; saveBtn.textContent = 'Guardar cambios';
        } });

        var top = el('div', { class: 'topbar' }, [
            el('button', { class: 'iconbtn', html: ICON.back, onclick: goHome }),
            el('h1', { text: 'Ajustes', style: 'font-size:17px' })
        ]);
        var body = el('div', { class: 'pad' }, [
            el('div', { class: 'section-title', text: 'Perfil' }),
            el('div', { class: 'card' }, [
                el('div', { class: 'row' }, [el('div', { class: 'field-label', text: 'Nombre' }), nameInput]),
                el('div', { class: 'row' }, [el('div', { class: 'field-label', text: 'Correo' }), emailInput])
            ]),
            el('div', { style: 'height:14px' }),
            saveBtn,
            el('div', { class: 'section-title', text: 'Sesión' }),
            el('button', { class: 'btn danger block', text: 'Cerrar sesión', onclick: doLogout }),
            el('p', { class: 'muted', style: 'margin-top:22px', text: 'Los audios se guardan 1 h y los textos 24 h. Al leerse se borran del servidor.' })
        ]);
        mount(el('div', { class: 'screen' }, [top, body]));
    }

    async function doLogout() {
        try { await Api.logout(); } catch (e) { /* ignore */ }
        logoutLocal();
    }
    function logoutLocal() {
        stopTimers();
        Api.setToken(null); Api.setUser(null); state.user = null; state.links = [];
        screenAuth('email');
    }

    /* ================= PAIR (QR) OVERLAY ================= */
    var _scanner = null;
    var _ovInterval = null;
    function openPairOverlay() {
        showOverlay('mine');
    }
    function closeOverlay() {
        if (_scanner) { _scanner.stop(); _scanner = null; }
        if (_ovInterval) { clearInterval(_ovInterval); _ovInterval = null; }
        overlayEl.classList.add('hidden'); clear(overlayEl);
    }

    function showOverlay(tab) {
        clear(overlayEl);
        overlayEl.classList.remove('hidden');

        var tabs = el('div', { class: 'ov-tabs' }, [
            el('button', { class: tab === 'mine' ? 'active' : '', text: 'Mi código', onclick: function () { showOverlay('mine'); } }),
            el('button', { class: tab === 'scan' ? 'active' : '', text: 'Escanear', onclick: function () { showOverlay('scan'); } })
        ]);
        var top = el('div', { class: 'ov-top' }, [
            tabs, el('span', { class: 'spacer' }),
            el('button', { class: 'iconbtn', html: ICON.close, onclick: closeOverlay })
        ]);
        var body = el('div', { class: 'ov-body', id: 'ov-body' }, [el('div', { class: 'spinner light' })]);
        overlayEl.appendChild(el('div', { class: 'screen' }, [top, body]));

        if (_scanner) { _scanner.stop(); _scanner = null; }
        if (_ovInterval) { clearInterval(_ovInterval); _ovInterval = null; }
        if (tab === 'mine') renderMyQr(body); else renderScanner(body);
    }

    async function renderMyQr(body) {
        try {
            var r = await Api.createQr();
            clear(body);
            body.appendChild(el('div', { class: 'qr-card', html: r.qr_svg }));
            body.appendChild(el('h2', { text: 'Escanéame' }));
            body.appendChild(el('p', { text: 'Pide a la otra persona que abra Walkie, pulse «Escanear» y apunte a este código.' }));
            // Poll links so we auto-close when the other side scans and pairs.
            var before = state.links.length;
            _ovInterval = setInterval(async function () {
                try {
                    var lk = await Api.links();
                    if ((lk.links || []).length > before) {
                        state.links = lk.links; toast('¡Vinculado!');
                        closeOverlay(); goHome();
                    }
                } catch (e) { if (_ovInterval) { clearInterval(_ovInterval); _ovInterval = null; } }
            }, 2500);
        } catch (e) {
            clear(body); body.appendChild(el('p', { text: errMsg(e) }));
        }
    }

    function renderScanner(body) {
        clear(body);
        if (!window.QrScanner.supported()) { renderManualPair(body); return; }
        var video = el('video', { id: 'scanner-video', muted: 'true', playsinline: 'true' });
        body.appendChild(video);
        body.appendChild(el('p', { class: 'scan-hint', text: 'Apunta al código QR de la otra persona.' }));
        body.appendChild(el('button', { class: 'link-btn', style: 'color:#b9b9be', text: 'Introducir código manualmente', onclick: function () { renderManualPair(body); } }));
        _scanner = window.QrScanner.create();
        _scanner.start(video, onScanned, function () { renderManualPair(body); });
    }

    function renderManualPair(body) {
        if (_scanner) { _scanner.stop(); _scanner = null; }
        clear(body);
        var input = el('input', { class: 'input', style: 'max-width:320px', placeholder: 'Pega el enlace o código' });
        body.appendChild(el('h2', { text: 'Vincular manualmente' }));
        body.appendChild(el('p', { text: 'Pega el enlace del QR de la otra persona.' }));
        body.appendChild(input);
        body.appendChild(el('button', { class: 'btn', text: 'Vincular', onclick: function () {
            var token = window.QrScanner.extractToken(input.value);
            if (!token) { toast('Código no válido'); return; }
            onScanned(token);
        } }));
    }

    async function onScanned(token) {
        try {
            var r = await Api.claim(token);
            toast('¡Vinculado con ' + r.link.display_name + '!');
            closeOverlay(); goHome();
        } catch (e) {
            toast(errMsg(e));
            // Reopen scanner so they can retry.
            showOverlay('scan');
        }
    }

    function maybeClaimFromHash() {
        var token = window.QrScanner.extractToken(location.hash || '');
        if (!token) return;
        history.replaceState(null, '', location.pathname + location.search);
        onScanned(token);
    }

    /* ================= confirm dialog ================= */
    function openConfirm(title, msg, confirmLabel, onConfirm) {
        clear(overlayEl); overlayEl.classList.remove('hidden');
        var card = el('div', { class: 'ov-body' }, [
            el('h2', { text: title }),
            el('p', { text: msg }),
            el('div', { class: 'stack', style: 'width:min(90vw,320px)' }, [
                el('button', { class: 'btn danger block', text: confirmLabel, onclick: function () { closeOverlay(); onConfirm(); } }),
                el('button', { class: 'btn subtle block', text: 'Cancelar', onclick: closeOverlay })
            ])
        ]);
        overlayEl.appendChild(el('div', { class: 'screen' }, [
            el('div', { class: 'ov-top' }, [el('span', { class: 'spacer' }), el('button', { class: 'iconbtn', html: ICON.close, onclick: closeOverlay })]),
            card
        ]));
    }

    /* ================= boot ================= */
    async function boot() {
        if (!Api.isAuthed()) { screenAuth('email'); return; }
        // Validate session, then go home.
        try { var me = await Api.me(); state.user = me.user; Api.setUser(me.user); goHome(); }
        catch (e) { logoutLocal(); }
    }
    boot();
})();
