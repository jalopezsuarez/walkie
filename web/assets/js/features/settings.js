/* Feature: Settings — random funny name picker + email, logout. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    function screen() {
        W.stopTimers();
        var u = W.state.user || {};
        var Names = window.WalkieNames;

        // --- name picker: read-only field + back/new buttons, 10-item history ---
        var history = [];
        var start = u.display_name || (Names ? Names.random() : 'Walkie');
        var nameField = el('input', { class: 'input name-field', readonly: 'readonly', value: start });
        var backBtn = el('button', { class: 'name-btn', title: 'Nombre anterior', html: W.ICON.back, disabled: 'disabled' });
        var newBtn = el('button', { class: 'name-btn primary', title: 'Generar otro', text: '+' });

        function updateBack() {
            if (history.length) backBtn.removeAttribute('disabled');
            else backBtn.setAttribute('disabled', 'disabled');
        }
        newBtn.addEventListener('click', function () {
            if (!Names) return;
            history.push(nameField.value);
            if (history.length > 10) history.shift();     // keep last 10
            nameField.value = Names.random();
            updateBack();
        });
        backBtn.addEventListener('click', function () {
            if (!history.length) return;
            nameField.value = history.pop();
            updateBack();
        });

        var emailInput = el('input', { class: 'input', type: 'email', value: u.email || '' });

        var saveBtn = el('button', { class: 'btn block', text: 'Guardar cambios', onclick: async function () {
            var patch = {};
            var name = nameField.value.trim(), email = emailInput.value.trim();
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
                el('h1', { text: 'Ajustes', style: 'font-size:1.06rem' })
            ]),
            el('div', { class: 'pad' }, [
                el('div', { class: 'section-title', text: 'Perfil' }),
                el('div', { class: 'card' }, [
                    el('div', { class: 'row' }, [
                        el('div', { class: 'field-label', text: 'Pseudónimo' }),
                        el('div', { class: 'name-picker' }, [nameField, backBtn, newBtn])
                    ]),
                    el('div', { class: 'row' }, [el('div', { class: 'field-label', text: 'Correo' }), emailInput])
                ]),
                el('div', { style: 'height:.9rem' }),
                saveBtn,
                el('div', { class: 'section-title', text: 'Sesión' }),
                el('button', { class: 'btn danger block', text: 'Cerrar sesión', onclick: W.auth.logout }),
                el('p', { class: 'muted', style: 'margin-top:1.2rem', text: 'Los audios se guardan 1 h y los textos 24 h. Al leerse se borran del servidor.' })
            ])
        ]));
    }

    W.settings = { screen: screen };
})();
