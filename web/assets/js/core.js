/* Walkie core — shared state, DOM helpers, icons, overlay & toast.
   Feature slices (auth, contacts, chat, pairing, settings) attach to `W`. */
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
        gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3.2"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>',
        back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>',
        close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>',
        mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M6 11a6 6 0 0 0 12 0M12 17v4"/></svg>',
        send: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
        play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
        pause: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>',
        trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/></svg>',
        people: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5"/><circle cx="17" cy="8" r="2.6"/><path d="M15.5 14.6c2.6.3 4.5 2.4 4.5 5.4"/></svg>',
        logo: '<svg viewBox="0 0 24 24" fill="#fff"><circle cx="12" cy="9" r="3.4"/><rect x="10.5" y="9" width="3" height="9" rx="1.5"/></svg>',
        image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4.5" width="18" height="15" rx="2.6"/><circle cx="8.5" cy="10" r="1.7"/><path d="M4 17l4.5-4.5L13 17M13 15l3-3 4 4"/></svg>'
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
