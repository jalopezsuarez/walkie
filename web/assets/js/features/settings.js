/* Feature: Settings — edit display name and email, logout. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    function screen() {
        W.stopTimers();
        var u = W.state.user || {};
        var nameInput = el('input', { class: 'input', type: 'text', maxlength: '60', value: u.display_name || '' });
        var emailInput = el('input', { class: 'input', type: 'email', value: u.email || '' });

        var saveBtn = el('button', { class: 'btn block', text: 'Guardar cambios', onclick: async function () {
            var patch = {};
            var name = nameInput.value.trim(), email = emailInput.value.trim();
            if (name && name !== u.display_name) patch.display_name = name;
            if (email && email !== u.email) patch.email = email;
            if (!Object.keys(patch).length) { W.toast('Nada que cambiar'); return; }

            saveBtn.disabled = true; saveBtn.textContent = 'Guardando…';
            try {
                var r = await Api.updateMe(patch);
                W.state.user = r.user;
                Api.setUser(r.user);
                W.toast('Guardado');
            } catch (e) { W.toast(W.errMsg(e)); }
            saveBtn.disabled = false; saveBtn.textContent = 'Guardar cambios';
        } });

        W.mount(el('div', { class: 'screen' }, [
            el('div', { class: 'topbar' }, [
                el('button', { class: 'iconbtn', html: W.ICON.back, onclick: W.contacts.go }),
                el('h1', { text: 'Ajustes', style: 'font-size:17px' })
            ]),
            el('div', { class: 'pad' }, [
                el('div', { class: 'section-title', text: 'Perfil' }),
                el('div', { class: 'card' }, [
                    el('div', { class: 'row' }, [el('div', { class: 'field-label', text: 'Nombre' }), nameInput]),
                    el('div', { class: 'row' }, [el('div', { class: 'field-label', text: 'Correo' }), emailInput])
                ]),
                el('div', { style: 'height:14px' }),
                saveBtn,
                el('div', { class: 'section-title', text: 'Sesión' }),
                el('button', { class: 'btn danger block', text: 'Cerrar sesión', onclick: W.auth.logout }),
                el('p', { class: 'muted', style: 'margin-top:22px', text: 'Los audios se guardan 1 h y los textos 24 h. Al leerse se borran del servidor.' })
            ])
        ]));
    }

    W.settings = { screen: screen };
})();
