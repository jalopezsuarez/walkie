/* Walkie core — shared state, DOM helpers, icons, overlay & toast.
   Feature slices (auth, contacts, conversation, pairing, settings) attach to `W`. */
(function () {
    'use strict';

    var appEl = document.getElementById('app');
    var overlayEl = document.getElementById('qr-overlay');
    var toastEl = document.getElementById('toast');

    var state = {
        user: window.Api.getUser(),
        links: [],
        current: null,      // open conversation { link_id, user_id, display_name }
        lastMsgId: 0,
        poll: null,
        listPoll: null,
        abortPoll: null,    // aborts the in-flight long-poll (set by conversation)
        pending: null       // recorded-but-unsent voice note
    };

    /* DOM builder: el('div', {class:'x', text:'y', onclick:fn}, [children]) */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) {
            if (k === 'class') node.className = attrs[k];
            else if (k === 'text') node.textContent = attrs[k];
            else if (k === 'html') node.innerHTML = attrs[k];
            else if (k.slice(0, 2) === 'on' && typeof attrs[k] === 'function') node.addEventListener(k.slice(2), attrs[k]);
            else if (attrs[k] != null) node.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) {
            if (c != null) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); }
    function mount(node) { clear(appEl); appEl.appendChild(node); }
    function initials(name) { return (name || '?').trim().charAt(0).toUpperCase() || '?'; }
    function fmtTime(iso) {
        var d = new Date(iso);
        return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    var _toastT;
    function toast(msg) {
        toastEl.textContent = msg;
        toastEl.classList.remove('hidden');
        clearTimeout(_toastT);
        _toastT = setTimeout(function () { toastEl.classList.add('hidden'); }, 2600);
    }

    function errMsg(e) {
        if (!e) return 'Error';
        if (e.code === 'rate_limited') return 'Demasiados intentos, espera un momento.';
        if (e.code === 'network') return 'Sin conexión.';
        return e.message || 'Error';
    }

    function stopTimers() {
        if (state.poll) { clearInterval(state.poll); state.poll = null; }
        if (state.listPoll) { clearInterval(state.listPoll); state.listPoll = null; }
        if (state.abortPoll) { try { state.abortPoll(); } catch (e) {} state.abortPoll = null; }
    }

    /* Overlay management (full-screen QR / dialogs) */
    var _ovCleanup = null;
    function openOverlay(node, onClose) {
        closeOverlay();
        _ovCleanup = onClose || null;
        overlayEl.appendChild(node);
        overlayEl.classList.remove('hidden');
    }
    function closeOverlay() {
        if (_ovCleanup) { try { _ovCleanup(); } catch (e) {} _ovCleanup = null; }
        overlayEl.classList.add('hidden');
        clear(overlayEl);
    }

    function confirmDialog(title, msg, confirmLabel, onConfirm) {
        var ICON = W.ICON;
        openOverlay(el('div', { class: 'screen' }, [
            el('div', { class: 'ov-top' }, [
                el('span', { class: 'spacer' }),
                el('button', { class: 'iconbtn', html: ICON.close, onclick: closeOverlay })
            ]),
            el('div', { class: 'ov-body' }, [
                el('h2', { text: title }),
                el('p', { text: msg }),
                el('div', { class: 'stack', style: 'width:min(90vw,320px)' }, [
                    el('button', { class: 'btn danger block', text: confirmLabel, onclick: function () { closeOverlay(); onConfirm(); } }),
                    el('button', { class: 'btn subtle block', text: 'Cancelar', onclick: closeOverlay })
                ])
            ])
        ]));
    }

    var ICON = {
        gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="12" cy="12" r="9.5"/><circle cx="7" cy="12" r="1.35" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.35" fill="currentColor" stroke="none"/><circle cx="17" cy="12" r="1.35" fill="currentColor" stroke="none"/></svg>',
        back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>',
        close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>',
        mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M6 11a6 6 0 0 0 12 0M12 17v4"/></svg>',
        send: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
        play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
        pause: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>',
        trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/></svg>',
        people: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5"/><circle cx="17" cy="8" r="2.6"/><path d="M15.5 14.6c2.6.3 4.5 2.4 4.5 5.4"/></svg>',
        logo: '<svg viewBox="8 12 48 48"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M37.9,45h-3.5C28.3,45,18,52,18,52v-4"/><path d="M20.9,45h-3c-3.3,0-5.9-2.6-5.9-5.9V25c0-3.3,2.7-6,6-6h28.1c3.3,0,5.9,2.6,5.9,5.9V36"/><rect x="42" y="44" width="10" height="8"/><path d="M50,44h-6v-2c0-1.7,1.3-3,3-3h0c1.7,0,3,1.3,3,3V44z"/><circle cx="21" cy="32" r="3"/><circle cx="32" cy="32" r="3"/><circle cx="43" cy="32" r="3"/></g><circle cx="47" cy="48" r="1.1" fill="currentColor"/></svg>',
        block: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="7" y1="12" x2="17" y2="12"/></svg>',
        check1: '<svg viewBox="0 0 20 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l4 4 9-11"/></svg>',
        check2: '<svg viewBox="0 0 26 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9l4 4 9-11"/><path d="M11 13l1 1 9-11"/></svg>'
    };

    window.W = {
        state: state,
        el: el, clear: clear, mount: mount,
        initials: initials, fmtTime: fmtTime,
        toast: toast, errMsg: errMsg,
        stopTimers: stopTimers,
        openOverlay: openOverlay, closeOverlay: closeOverlay, confirmDialog: confirmDialog,
        ICON: ICON
    };

    /* Block pinch / gesture zoom (app-like). Vertical scrolling still works. */
    ['gesturestart', 'gesturechange', 'gestureend'].forEach(function (ev) {
        document.addEventListener(ev, function (e) { e.preventDefault(); }, { passive: false });
    });
    document.addEventListener('touchmove', function (e) {
        if (e.touches && e.touches.length > 1) e.preventDefault();
    }, { passive: false });
})();
