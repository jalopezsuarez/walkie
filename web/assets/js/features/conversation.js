/* Feature: Chat — conversation view, text send, push-to-talk voice notes. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    function open(link) {
        W.stopTimers();
        stopCurrent();
        W.state.current = link;
        W.state.lastMsgId = 0;
        W.state.pending = null;

        var top = el('div', { class: 'topbar' }, [
            el('button', { class: 'iconbtn', html: W.ICON.back, onclick: W.contacts.go }),
            el('span', { class: 'avatar', style: 'width:2rem;height:2rem;font-size:.85rem', text: W.initials(link.display_name) }),
            el('h1', { text: link.display_name, style: 'font-size:1.06rem' }),
            el('span', { class: 'spacer' }),
            el('button', { class: 'iconbtn', title: 'Eliminar contacto', html: W.ICON.trash, onclick: function () { W.contacts.remove(link); } })
        ]);
        var msgs = el('div', { id: 'messages', class: 'messages' }, [el('div', { class: 'spinner' })]);

        W.mount(el('div', { class: 'screen chat' }, [top, msgs, composer(link)]));

        load(true);
        W.state.poll = setInterval(function () { load(false); }, 2500);
    }

    /* ---- composer (image attach + text + push-to-talk) ---- */
    function composer(link) {
        var input = el('input', { id: 'text-input', class: 'input', type: 'text', placeholder: 'Mensaje…', autocomplete: 'off' });
        var ptt = el('button', { id: 'ptt', class: 'ptt', title: 'Mantén pulsado para hablar', html: W.ICON.mic });
        var sendBtn = el('button', { class: 'ptt', style: 'display:none', html: W.ICON.send, title: 'Enviar' });
        var attach = el('button', { class: 'iconbtn attach', title: 'Enviar imagen o sticker', html: W.ICON.image });
        var fileInput = el('input', { type: 'file', accept: 'image/*', style: 'display:none' });

        function refresh() {
            var hasText = input.value.trim().length > 0;
            sendBtn.style.display = hasText ? 'flex' : 'none';
            ptt.style.display = hasText ? 'none' : 'flex';
        }
        async function sendText() {
            var t = input.value.trim();
            if (!t) return;
            input.value = ''; refresh();
            try { await Api.sendText(link.link_id, t); await load(false); }
            catch (e) { W.toast(W.errMsg(e)); }
        }
        input.addEventListener('input', refresh);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') sendText(); });
        sendBtn.addEventListener('click', sendText);

        attach.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            var f = fileInput.files && fileInput.files[0];
            if (f) sendImageFile(f, link);
            fileInput.value = '';
        });
        // Paste an image (e.g. a copied Memoji sticker).
        input.addEventListener('paste', function (e) {
            var items = e.clipboardData && e.clipboardData.items;
            if (!items) return;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image') === 0) {
                    var f = items[i].getAsFile();
                    if (f) { e.preventDefault(); sendImageFile(f, link); }
                }
            }
        });

        bindPushToTalk(ptt, link);
        return el('div', { id: 'composer', class: 'composer' }, [attach, fileInput, input, sendBtn, ptt]);
    }

    /* ---- images / stickers ---- */
    async function sendImageFile(file, link) {
        if (!/^image\//.test(file.type || '')) { W.toast('Selecciona una imagen'); return; }
        W.toast('Enviando imagen…');
        try {
            var img = await prepImage(file);
            await Api.sendImage(link.link_id, img.base64, img.mime);
            await load(false);
        } catch (e) { W.toast(W.errMsg(e)); }
    }

    // Keep small PNG/GIF/WebP as-is (transparency / animation); downscale big
    // photos to a compressed JPEG so uploads stay small.
    function prepImage(file) {
        var MAX = 2 * 1024 * 1024;
        if (file.size <= MAX && /image\/(png|gif|webp)/i.test(file.type)) {
            return window.WalkieAudio.blobToBase64(file).then(function (b64) {
                return { base64: b64, mime: file.type.toLowerCase() };
            });
        }
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                var max = 1280, w = img.naturalWidth, h = img.naturalHeight;
                var scale = Math.min(1, max / Math.max(w, h));
                var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
                var canvas = document.createElement('canvas');
                canvas.width = cw; canvas.height = ch;
                canvas.getContext('2d').drawImage(img, 0, 0, cw, ch);
                var mime = /png|webp/i.test(file.type) ? 'image/png' : 'image/jpeg';
                var data = canvas.toDataURL(mime, mime === 'image/jpeg' ? 0.82 : undefined);
                var b64 = data.split(',')[1];
                if (b64.length * 0.75 > MAX) {                 // still too big → harder JPEG
                    b64 = canvas.toDataURL('image/jpeg', 0.6).split(',')[1];
                    mime = 'image/jpeg';
                }
                resolve({ base64: b64, mime: mime });
            };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen')); };
            img.src = url;
        });
    }

    function buildImage(node, m) {
        var src = 'data:' + (m.mime || 'image/png') + ';base64,' + m.image;
        node.appendChild(el('img', { class: 'img-msg', src: src, alt: 'imagen', loading: 'lazy' }));
        node.addEventListener('click', function () {
            if (node._suppressClick) { node._suppressClick = false; return; } // long-press
            W.openOverlay(el('div', { class: 'screen' }, [
                el('div', { class: 'ov-top' }, [
                    el('span', { class: 'spacer' }),
                    el('button', { class: 'iconbtn', html: W.ICON.close, onclick: W.closeOverlay })
                ]),
                el('div', { class: 'ov-body' }, [el('img', { class: 'img-full', src: src, alt: 'imagen' })])
            ]));
        });
    }

    function bindPushToTalk(ptt, link) {
        var rec = window.WalkieAudio.create();
        var holding = false, startT = 0;

        async function begin(ev) {
            ev.preventDefault();
            if (W.state.pending) return;
            if (!rec.supported()) {
                W.toast('Este navegador no permite grabar audio (necesita HTTPS y micrófono)');
                return;
            }
            holding = true; startT = Date.now();
            // Immediate visual feedback while the mic permission resolves.
            ptt.classList.add('recording');
            recIndicator(true);
            try {
                await rec.start();
                if (!holding) { rec.cancel(); ptt.classList.remove('recording'); recIndicator(false); return; }
            } catch (e) {
                holding = false;
                ptt.classList.remove('recording');
                recIndicator(false);
                var name = e && e.name ? e.name : '';
                var msg = name === 'NotAllowedError' || name === 'SecurityError'
                        ? 'Permiso de micrófono denegado. Actívalo en los ajustes del navegador.'
                    : name === 'NotFoundError' || name === 'DevicesNotFoundError'
                        ? 'No se detecta ningún micrófono.'
                    : 'No se pudo grabar: ' + (name || (e && e.message) || e);
                W.toast(msg);
            }
        }
        async function end(ev) {
            if (!holding) return;
            holding = false;
            if (ev) ev.preventDefault();
            ptt.classList.remove('recording');
            recIndicator(false);
            var elapsed = Date.now() - startT;
            var result = await rec.stop();
            if (!result || elapsed < 500) { return; }  // too short — silently ignore
            preview(result, link);
        }
        ptt.addEventListener('pointerdown', begin);
        ptt.addEventListener('pointerup', end);
        ptt.addEventListener('pointercancel', end);
        ptt.addEventListener('pointerleave', function (e) { if (holding) end(e); });
    }

    function recIndicator(on) {
        var comp = document.getElementById('composer');
        if (!comp) return;
        var input = document.getElementById('text-input');
        if (input) input.style.visibility = on ? 'hidden' : 'visible';
        var existing = document.getElementById('rec-ind');
        if (on && !existing) {
            comp.style.position = 'relative';
            comp.appendChild(el('div', { id: 'rec-ind', class: 'rec-indicator', style: 'position:absolute;left:24px' }, [
                el('span', { class: 'rec-dot' }), el('span', { text: 'Grabando…' })
            ]));
        } else if (!on && existing) {
            existing.remove();
        }
    }

    /* Listen back before sending: send / discard. */
    function preview(result, link) {
        W.state.pending = result;
        var url = URL.createObjectURL(result.blob);

        var discard = el('button', { class: 'discard-btn', html: W.ICON.close, title: 'Descartar', onclick: function () {
            URL.revokeObjectURL(url);
            W.state.pending = null;
            swapComposer(link);
        } });
        var send = el('button', { class: 'btn', text: 'Enviar', onclick: async function () {
            send.disabled = true; send.textContent = 'Enviando…';
            try {
                var b64 = await window.WalkieAudio.blobToBase64(result.blob);
                await Api.sendAudio(link.link_id, b64, result.mime, result.durationMs);
                URL.revokeObjectURL(url);
                W.state.pending = null;
                swapComposer(link);
                await load(false);
            } catch (e) {
                W.toast(W.errMsg(e));
                send.disabled = false; send.textContent = 'Enviar';
            }
        } });

        var bar = el('div', { id: 'composer', class: 'preview' }, [
            discard,
            el('audio', { controls: 'true', src: url }),
            send
        ]);
        var old = document.getElementById('composer');
        if (old) old.replaceWith(bar);
    }

    function swapComposer(link) {
        var old = document.getElementById('composer');
        if (old) old.replaceWith(composer(link));
    }

    /* ---- messages ---- */
    async function load(initial) {
        if (!W.state.current) return;
        try {
            var r = await Api.messages(W.state.current.link_id, W.state.lastMsgId);
            var box = document.getElementById('messages');
            if (!box) return;
            if (initial) W.clear(box);

            var incoming = r.messages || [];
            if (incoming.length) {
                var ph = box.querySelector('.empty');
                if (ph) ph.remove();
            }
            incoming.forEach(function (m) {
                if (m.id > W.state.lastMsgId) W.state.lastMsgId = m.id;
                box.appendChild(bubble(m));
            });
            if (initial && !box.children.length) {
                box.appendChild(el('div', { class: 'empty', style: 'flex:1' }, [
                    el('div', { class: 'muted', text: 'Di algo 👋' })
                ]));
            }
            if (incoming.length) box.scrollTop = box.scrollHeight;
        } catch (e) {
            if (e.status === 401) return W.auth.logoutLocal();
            if (e.status === 404) { W.toast('Conversación eliminada'); W.contacts.go(); }
        }
    }

    // Number of emoji if the text is emoji-only (whitespace ignored), else 0.
    function emojiOnlyCount(text) {
        var t = (text || '').trim();
        if (!t) return 0;
        var rest;
        try {
            rest = t.replace(/[\p{Extended_Pictographic}\p{Emoji_Modifier}\p{Regional_Indicator}‍️\s]/gu, '');
        } catch (e) {
            return 0; // browser without Unicode property escapes
        }
        if (rest.length > 0) return 0;
        if (typeof Intl !== 'undefined' && Intl.Segmenter) {
            var c = 0, it = new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(t);
            for (var seg of it) { if (seg.segment.trim()) c++; } // ignore whitespace
            return c;
        }
        return Array.from(t.replace(/\s/g, '')).filter(function (ch) { return ch !== '‍' && ch !== '️'; }).length;
    }

    function bubble(m) {
        var node = el('div', { class: 'bubble ' + (m.mine ? 'mine' : 'theirs') });
        if (m.type === 'text') {
            var n = emojiOnlyCount(m.text);
            if (n >= 1 && n <= 8) {
                node.classList.add('emoji-only', n === 1 ? 'e1' : n <= 3 ? 'e2' : n <= 6 ? 'e3' : 'e4');
            }
            node.appendChild(el('span', { class: 'btext', text: m.text }));
        } else if (m.type === 'image') {
            node.classList.add('image');
            buildImage(node, m);
        } else {
            node.classList.add('audio');
            buildAudio(node, m);
        }
        node.appendChild(el('span', { class: 'time', text: W.fmtTime(m.created_at) }));

        // Long-press your own bubble to delete it.
        if (m.mine) {
            bindLongPress(node, function () {
                W.confirmDialog('¿Eliminar este mensaje?', 'Se borrará para los dos y no dejará rastro.', 'Eliminar', function () {
                    remove(m, node);
                });
            });
        }
        return node;
    }

    /* Long-press / long-click helper. Cancels on move or early release, and
       swallows the click that follows so it doesn't also trigger audio play. */
    function bindLongPress(node, onLong) {
        var timer = null, sx = 0, sy = 0;
        node._suppressClick = false;

        function cancel() {
            if (timer) { clearTimeout(timer); timer = null; }
            node.classList.remove('pressing');
        }
        node.addEventListener('pointerdown', function (e) {
            if (e.button && e.button !== 0) return;
            sx = e.clientX; sy = e.clientY;
            node.classList.add('pressing');
            timer = setTimeout(function () {
                timer = null;
                node.classList.remove('pressing');
                node._suppressClick = true;
                onLong();
            }, 550);
        });
        node.addEventListener('pointerup', cancel);
        node.addEventListener('pointercancel', cancel);
        node.addEventListener('pointermove', function (e) {
            if (timer && (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10)) cancel();
        });
        node.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    }

    /* Only one audio plays at a time across the whole conversation. */
    var current = null; // { audio, btn, fill, dur, total }

    function stopCurrent() {
        if (!current) return;
        current.audio.pause();
        current.btn.innerHTML = W.ICON.play;
        current = null;
    }

    function fmtDur(ms) {
        var s = Math.max(0, Math.round((ms || 0) / 1000));
        return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }

    function buildAudio(node, m) {
        var audio = new Audio('data:' + (m.mime || 'audio/webm') + ';base64,' + m.audio);
        audio.preload = 'metadata';
        var btn = el('button', { class: 'audio-play', html: W.ICON.play });
        var fill = el('div', { class: 'audio-fill' });
        var dur = el('span', { class: 'audio-dur', text: fmtDur(m.duration_ms) });
        var body = el('div', { class: 'audio-body' }, [el('div', { class: 'audio-bar' }, [fill]), dur]);

        audio.addEventListener('timeupdate', function () {
            var t = audio.duration && isFinite(audio.duration) ? audio.duration : (m.duration_ms || 0) / 1000;
            if (t > 0) {
                fill.style.width = (audio.currentTime / t * 100) + '%';
                dur.textContent = fmtDur((t - audio.currentTime) * 1000);
            }
        });
        audio.addEventListener('ended', function () {
            btn.innerHTML = W.ICON.play;
            fill.style.width = '0%';
            dur.textContent = fmtDur(m.duration_ms);
            if (current && current.audio === audio) current = null;
        });

        // Tapping anywhere on the bubble toggles this note; starting one stops others.
        node.addEventListener('click', function () {
            if (node._suppressClick) { node._suppressClick = false; return; } // long-press just fired
            if (current && current.audio === audio) {
                stopCurrent();
            } else {
                stopCurrent();
                audio.play().catch(function () { W.toast('No se pudo reproducir'); });
                btn.innerHTML = W.ICON.pause;
                current = { audio: audio, btn: btn, fill: fill };
            }
        });

        node.appendChild(el('div', { class: 'audio-msg' }, [btn, body]));
    }

    async function remove(m, node) {
        if (current && node.contains(current.btn)) stopCurrent();
        try { await Api.deleteMessage(W.state.current.link_id, m.id); node.remove(); }
        catch (e) { W.toast(W.errMsg(e)); }
    }

    W.chat = { open: open };
})();
