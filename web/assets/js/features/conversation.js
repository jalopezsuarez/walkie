/* Feature: Chat — conversation view, text send, push-to-talk voice notes. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    // Read/delivery tracking (per open conversation).
    var checkEls = {};       // message id -> checks <span>
    var rendered = null;     // Set of already-rendered message ids (dedup)
    var pendingRead = null;  // Set of ids seen/played, not yet flushed
    var readSent = null;     // Set of ids already reported read
    var readTimer = null;
    var seeObserver = null;  // IntersectionObserver for incoming text
    var convGen = 0;         // bumped on every open / leave to stop the long-poll
    var pollCtl = null;      // AbortController for the in-flight long-poll

    function open(link) {
        W.stopTimers();
        stopCurrent();
        W.state.current = link;
        W.state.lastMsgId = 0;
        W.state.pending = null;

        convGen++;
        var gen = convGen;
        if (pollCtl) { try { pollCtl.abort(); } catch (e) {} pollCtl = null; }
        // Any navigation calls W.stopTimers → abort the long-poll too.
        W.state.abortPoll = function () { convGen++; if (pollCtl) { try { pollCtl.abort(); } catch (e) {} pollCtl = null; } };

        checkEls = {};
        rendered = (typeof Set !== 'undefined') ? new Set() : null;
        pendingRead = (typeof Set !== 'undefined') ? new Set() : null;
        readSent = (typeof Set !== 'undefined') ? new Set() : null;
        if (readTimer) { clearTimeout(readTimer); readTimer = null; }
        if (seeObserver) { seeObserver.disconnect(); seeObserver = null; }
        if (typeof IntersectionObserver !== 'undefined') {
            seeObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting && e.target._mid) {
                        markRead(e.target._mid);
                        seeObserver.unobserve(e.target);
                    }
                });
            }, { threshold: 0.6 });
        }

        var top = el('div', { class: 'topbar' }, [
            el('button', { class: 'iconbtn', html: W.ICON.back, onclick: W.contacts.go }),
            el('span', { class: 'avatar', style: 'width:2rem;height:2rem;font-size:.85rem', text: W.initials(link.display_name) }),
            el('h1', { text: link.display_name, style: 'font-size:1.06rem' }),
            el('span', { class: 'spacer' }),
            el('button', { class: 'block-btn', title: 'Eliminar contacto', html: W.ICON.block, onclick: function () { W.contacts.remove(link); } })
        ]);
        var msgs = el('div', { id: 'messages', class: 'messages' }, [el('div', { class: 'spinner' })]);

        W.mount(el('div', { class: 'screen chat' }, [top, msgs, composer(link)]));

        load(true);
        W.state.poll = setInterval(refreshStatuses, 3000);   // check-mark updates
        longPoll(gen);                                        // near-instant new messages
    }

    // Hold a request open until a new message arrives (or a safe timeout),
    // then immediately re-issue — near real-time without WebSockets.
    async function longPoll(gen) {
        while (gen === convGen && W.state.current) {
            pollCtl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            try {
                var r = await Api.messages(W.state.current.link_id, W.state.lastMsgId,
                    { wait: true, signal: pollCtl ? pollCtl.signal : undefined });
                if (gen !== convGen) return;
                applyMessages(r, false);
            } catch (e) {
                if (gen !== convGen || (e && e.code === 'aborted')) return;
                if (e && e.status === 401) { W.auth.logoutLocal(); return; }
                if (e && e.status === 404) { W.toast('Conversación eliminada'); W.contacts.go(); return; }
                await new Promise(function (res) { setTimeout(res, 2000); }); // back off, then retry
            }
        }
    }

    /* ---- delivery / read checks ---- */
    function setChecks(span, delivered, read) {
        if (read) { span.className = 'checks read'; span.innerHTML = W.ICON.check2; }
        else if (delivered) { span.className = 'checks'; span.innerHTML = W.ICON.check1; }
        else { span.className = 'checks sent'; span.innerHTML = W.ICON.check1; }
    }

    // Mark an incoming message read (text scrolled into view / audio played).
    function markRead(id) {
        if (!pendingRead || (readSent && readSent.has(id))) return;
        pendingRead.add(id);
        if (readTimer) return;
        readTimer = setTimeout(flushRead, 500);
    }
    function flushRead() {
        readTimer = null;
        if (!W.state.current || !pendingRead || !pendingRead.size) return;
        var ids = []; pendingRead.forEach(function (i) { ids.push(i); }); pendingRead.clear();
        ids.forEach(function (i) { if (readSent) readSent.add(i); });
        Api.markRead(W.state.current.link_id, ids).then(refreshStatuses).catch(function () {
            ids.forEach(function (i) { if (readSent) readSent.delete(i); });
        });
    }

    async function refreshStatuses() {
        if (!W.state.current) return;
        try {
            var r = await Api.statuses(W.state.current.link_id);
            (r.statuses || []).forEach(function (s) {
                var span = checkEls[s.id];
                if (span) setChecks(span, s.delivered, s.read);
            });
        } catch (e) { /* ignore */ }
    }

    /* ---- composer (text + push-to-talk) ---- */
    function composer(link) {
        var input = el('input', { id: 'text-input', class: 'input', type: 'text', placeholder: 'Mensaje…', autocomplete: 'off' });
        var ptt = el('button', { id: 'ptt', class: 'ptt', title: 'Mantén pulsado para hablar', html: W.ICON.mic });
        var sendBtn = el('button', { class: 'ptt', style: 'display:none', html: W.ICON.send, title: 'Enviar' });

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

        bindPushToTalk(ptt, link);
        return el('div', { id: 'composer', class: 'composer' }, [input, sendBtn, ptt]);
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
        var send = el('button', { class: 'ptt', html: W.ICON.send, title: 'Enviar', onclick: async function () {
            send.disabled = true;
            try {
                var b64 = await window.WalkieAudio.blobToBase64(result.blob);
                await Api.sendAudio(link.link_id, b64, result.mime, result.durationMs);
                URL.revokeObjectURL(url);
                W.state.pending = null;
                swapComposer(link);
                await load(false);
            } catch (e) {
                W.toast(W.errMsg(e));
                send.disabled = false;
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
            applyMessages(r, initial);
        } catch (e) {
            if (e.status === 401) return W.auth.logoutLocal();
            if (e.status === 404) { W.toast('Conversación eliminada'); W.contacts.go(); }
        }
    }

    // Append new messages; dedup by id so the long-poll and an explicit load
    // can't render the same message twice.
    function applyMessages(r, initial) {
        var box = document.getElementById('messages');
        if (!box) return;
        if (initial) { W.clear(box); rendered = (typeof Set !== 'undefined') ? new Set() : null; }

        var appended = 0;
        (r.messages || []).forEach(function (m) {
            if (rendered && rendered.has(m.id)) return;
            if (rendered) rendered.add(m.id);
            if (m.id > W.state.lastMsgId) W.state.lastMsgId = m.id;
            var ph = box.querySelector('.empty');
            if (ph) ph.remove();
            box.appendChild(bubble(m));
            appended++;
        });
        if (initial && !box.children.length) {
            box.appendChild(el('div', { class: 'empty', style: 'flex:1' }, [
                el('div', { class: 'muted', text: 'Di algo 👋' })
            ]));
        }
        if (appended) box.scrollTop = box.scrollHeight;
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
        node._mid = m.id;
        var isText = m.type === 'text';
        if (isText) {
            var n = emojiOnlyCount(m.text);
            if (n >= 1 && n <= 8) {
                node.classList.add('emoji-only', n === 1 ? 'e1' : n <= 3 ? 'e2' : n <= 6 ? 'e3' : 'e4');
            }
            node.appendChild(el('span', { class: 'btext', text: m.text }));
        } else {
            node.classList.add('audio');
            buildAudio(node, m);
        }

        // Time row with delivery/read checks to the LEFT of the time.
        var checks = el('span', { class: 'checks' });
        checkEls[m.id] = checks;
        setChecks(checks, m.delivered, m.read);
        node.appendChild(el('span', { class: 'time' }, [checks, document.createTextNode(W.fmtTime(m.created_at))]));

        if (m.mine) {
            // Long-press your own bubble to delete it.
            bindLongPress(node, function () {
                W.confirmDialog('¿Eliminar este mensaje?', 'Se borrará para los dos y no dejará rastro.', 'Eliminar', function () {
                    remove(m, node);
                });
            });
        } else if (isText && seeObserver) {
            // Incoming text counts as read once it scrolls into view.
            seeObserver.observe(node);
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
        current.audio.pause();   // 'pause' event resets its button icon
        current = null;
    }

    function b64ToBlob(b64, mime) {
        var bin = atob(b64), len = bin.length, bytes = new Uint8Array(len);
        for (var i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
        return new Blob([bytes], { type: mime });
    }

    /* Cache-first audio: decode each voice note into a blob URL once, keyed by
       message id, and reuse it on replays or when the chat is re-opened. The
       blob is fully local, so playback never depends on the network — no cuts. */
    var audioUrlCache = {};
    var audioUrlOrder = [];
    var AUDIO_CACHE_MAX = 60;

    function audioUrl(m) {
        var id = m.id;
        if (audioUrlCache[id]) return audioUrlCache[id];
        var url;
        try { url = URL.createObjectURL(b64ToBlob(m.audio, m.mime || 'audio/webm')); }
        catch (e) { url = 'data:' + (m.mime || 'audio/webm') + ';base64,' + m.audio; }
        audioUrlCache[id] = url;
        audioUrlOrder.push(id);
        if (audioUrlOrder.length > AUDIO_CACHE_MAX) {
            // Drop the oldest from the map but keep its object URL alive for any
            // element still using it; the browser reclaims it on page unload.
            delete audioUrlCache[audioUrlOrder.shift()];
        }
        return url;
    }

    function fmtDur(ms) {
        var s = Math.max(0, Math.round((ms || 0) / 1000));
        return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }

    function buildAudio(node, m) {
        // Cache-first blob URL: prepared once per message, reused on replay /
        // re-open. A blob plays far more reliably than a big data: URL (the
        // first tap was silent on mobile with data: URLs).
        var audio = new Audio(audioUrl(m));
        audio.preload = 'auto';
        try { audio.load(); } catch (e) {}   // decode ahead so the first tap plays instantly, gap-free
        var btn = el('button', { class: 'audio-play', html: W.ICON.play });
        var fill = el('div', { class: 'audio-fill' });
        var dur = el('span', { class: 'audio-dur', text: fmtDur(m.duration_ms) });
        var body = el('div', { class: 'audio-body' }, [el('div', { class: 'audio-bar' }, [fill]), dur]);

        // Button icon follows the real playback state.
        audio.addEventListener('play', function () { btn.innerHTML = W.ICON.pause; });
        audio.addEventListener('pause', function () { btn.innerHTML = W.ICON.play; });
        audio.addEventListener('timeupdate', function () {
            var t = audio.duration && isFinite(audio.duration) ? audio.duration : (m.duration_ms || 0) / 1000;
            if (t > 0) {
                fill.style.width = (audio.currentTime / t * 100) + '%';
                dur.textContent = fmtDur((t - audio.currentTime) * 1000);
            }
        });
        audio.addEventListener('ended', function () {
            fill.style.width = '0%';
            dur.textContent = fmtDur(m.duration_ms);
            if (current && current.audio === audio) current = null;
        });
        // Playing an incoming voice note counts as read (double check).
        if (!m.mine) {
            audio.addEventListener('play', function () { markRead(m.id); });
        }

        // Tap anywhere on the bubble to toggle; starting one stops any other.
        node.addEventListener('click', function () {
            if (node._suppressClick) { node._suppressClick = false; return; } // long-press just fired
            if (!audio.paused) { audio.pause(); return; }
            stopCurrent();
            current = { audio: audio, btn: btn, fill: fill };
            var p = audio.play();
            if (p && p.catch) p.catch(function () { W.toast('No se pudo reproducir'); });
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
