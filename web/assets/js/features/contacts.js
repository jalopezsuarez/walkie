/* Feature: Contacts — linked-users list (home screen). */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    async function go() {
        W.stopTimers();
        W.state.current = null;
        render();
        await load();
        W.state.listPoll = setInterval(load, 5000);
        W.pairing.claimFromHash();
    }

    function render() {
        var top = el('div', { class: 'topbar' }, [
            el('h1', { text: 'Walkie' }),
            el('span', { class: 'spacer' }),
            el('button', { class: 'iconbtn', title: 'Ajustes', html: W.ICON.gear, onclick: W.settings.screen })
        ]);
        var listWrap = el('div', { id: 'list-wrap', class: 'screen' }, [el('div', { class: 'spinner' })]);
        var fab = el('button', { class: 'btn fab', html: W.ICON.plus + '<span>Vincular</span>', onclick: W.pairing.open });
        W.mount(el('div', { class: 'screen' }, [top, listWrap, fab]));
        renderList();
    }

    async function load() {
        try {
            var r = await Api.links();
            W.state.links = r.links || [];
            renderList();
        } catch (e) {
            if (e.status === 401) W.auth.logoutLocal();
        }
    }

    function renderList() {
        var wrap = document.getElementById('list-wrap');
        if (!wrap) return;
        W.clear(wrap);

        if (!W.state.links.length) {
            wrap.appendChild(el('div', { class: 'empty' }, [
                el('span', { html: W.ICON.people }),
                el('div', { text: 'Aún no tienes contactos.' }),
                el('div', { class: 'muted', text: 'Pulsa «Vincular» y escanea el código QR de la otra persona.' })
            ]));
            return;
        }

        var ul = el('ul', { class: 'list' });
        W.state.links.forEach(function (lk) {
            var pending = lk.unread > 0;
            ul.appendChild(el('li', { class: 'contact' + (pending ? ' has-unread' : ''), onclick: function () { W.chat.open(lk); } }, [
                el('span', { class: 'avatar', text: W.initials(lk.display_name) }),
                el('div', { class: 'meta' }, [
                    el('div', { class: 'name', text: lk.display_name }),
                    el('div', { class: 'sub', text: pending ? 'Mensajes pendientes' : 'Toca para hablar' })
                ]),
                pending ? el('span', { class: 'unread-badge', text: String(lk.unread) }) : null
            ]));
        });
        wrap.appendChild(ul);
    }

    function remove(link) {
        W.confirmDialog(
            'Eliminar a ' + link.display_name + '?',
            'Se borrará la conversación para ambos. Para volver a hablar tendréis que vincularos de nuevo con el QR.',
            'Eliminar',
            async function () {
                try { await Api.unlink(link.link_id); W.toast('Contacto eliminado'); go(); }
                catch (e) { W.toast(W.errMsg(e)); }
            }
        );
    }

    W.contacts = { go: go, remove: remove };
})();
