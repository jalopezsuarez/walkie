/* Feature: Auth — passwordless email + 6-digit code login. */
(function () {
    'use strict';
    var W = window.W, Api = window.Api, el = W.el;

    function screen(step, email) {
        step = step || 'email';
        var input;

        var brand = el('div', { class: 'brand' }, [
            el('span', { class: 'logo', html: W.ICON.logo }),
            el('h1', { text: 'Walkie' }),
            el('p', { text: 'Notas de voz y texto, entre dos.' })
        ]);

        var body;
        if (step === 'email') {
            input = el('input', { class: 'input', type: 'email', inputmode: 'email', autocomplete: 'email', placeholder: 'tu@correo.com', value: email || '' });
            var sendBtn = el('button', { class: 'btn block', text: 'Enviar código' });
            var submit = async function () {
                var val = (input.value || '').trim();
                if (!val) { W.toast('Escribe tu correo'); return; }
                sendBtn.disabled = true; sendBtn.textContent = 'Enviando…';
                try {
                    var r = await Api.requestCode(val);
                    if (r && r.debug_code) W.toast('Código (debug): ' + r.debug_code);
                    screen('code', val);
                } catch (e) {
                    W.toast(W.errMsg(e));
                    sendBtn.disabled = false; sendBtn.textContent = 'Enviar código';
                }
            };
            sendBtn.addEventListener('click', submit);
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
            body = el('div', { class: 'stack' }, [
                el('div', {}, [el('div', { class: 'field-label', text: 'Correo' }), input]),
                sendBtn,
                el('p', { class: 'muted', text: 'Te enviaremos un código de 6 dígitos.' })
            ]);
        } else {
            input = el('input', { class: 'input', type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', placeholder: '••••••', style: 'letter-spacing:.4em;text-align:center;font-size:22px' });
            var verifyBtn = el('button', { class: 'btn block', text: 'Entrar' });
            var verify = async function () {
                var code = (input.value || '').trim();
                if (!/^\d{6}$/.test(code)) { W.toast('Código de 6 dígitos'); return; }
                verifyBtn.disabled = true; verifyBtn.textContent = 'Comprobando…';
                try {
                    var r = await Api.verify(email, code);
                    Api.setToken(r.token); Api.setUser(r.user);
                    W.state.user = r.user;
                    W.contacts.go();
                } catch (e) {
                    W.toast(W.errMsg(e));
                    verifyBtn.disabled = false; verifyBtn.textContent = 'Entrar';
                }
            };
            verifyBtn.addEventListener('click', verify);
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') verify(); });
            body = el('div', { class: 'stack' }, [
                el('div', {}, [el('div', { class: 'field-label', text: 'Código enviado a ' + email }), input]),
                verifyBtn,
                el('button', { class: 'link-btn', text: 'Usar otro correo', onclick: function () { screen('email', email); } })
            ]);
        }

        W.mount(el('div', { class: 'screen' }, [el('div', { class: 'center-screen' }, [brand, body])]));
        setTimeout(function () { input.focus(); }, 50);
    }

    async function logout() {
        try { await Api.logout(); } catch (e) { /* best effort */ }
        logoutLocal();
    }

    function logoutLocal() {
        W.stopTimers();
        Api.setToken(null); Api.setUser(null);
        W.state.user = null; W.state.links = [];
        screen('email');
    }

    W.auth = { screen: screen, logout: logout, logoutLocal: logoutLocal };
})();
