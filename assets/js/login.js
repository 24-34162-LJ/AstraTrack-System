// AstraTrack - Login page scripts
(function () {
    'use strict';

    var form    = document.getElementById('loginForm');
    var login   = document.getElementById('login');
    var pass    = document.getElementById('password');
    var toggle  = document.getElementById('togglePass');
    var eyeIcon = document.getElementById('eyeIcon');
    var caps    = document.getElementById('capsHint');
    var btn     = document.getElementById('loginBtn');
    if (!form) return;

    var EYE     = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    var EYE_OFF = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/>';

    /* ---------- Show / hide password ---------- */
    toggle.addEventListener('click', function () {
        var show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        eyeIcon.innerHTML = show ? EYE_OFF : EYE;
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        pass.focus();
    });

    /* ---------- Caps Lock hint ---------- */
    function checkCaps(e) {
        if (typeof e.getModifierState !== 'function') return;
        caps.hidden = !e.getModifierState('CapsLock');
    }
    pass.addEventListener('keydown', checkCaps);
    pass.addEventListener('keyup', checkCaps);
    pass.addEventListener('blur', function () { caps.hidden = true; });

    /* ---------- Inline validation ---------- */
    var passWrap = pass.closest('.password-wrap');

    function setInvalid(input, bad) {
        input.classList.toggle('is-invalid', bad);
        input.setAttribute('aria-invalid', bad ? 'true' : 'false');
        if (input === pass) passWrap.classList.toggle('has-error', bad);
    }

    [login, pass].forEach(function (input) {
        input.addEventListener('input', function () {
            if (input.value.trim() !== '') setInvalid(input, false);
        });
    });

    /* ---------- Submit: validate, then show loading state ---------- */
    function setLoading(on) {
        btn.disabled = on;
        btn.querySelector('.btn-text').hidden = on;
        btn.querySelector('.btn-loading').hidden = !on;
    }

    form.addEventListener('submit', function (e) {
        var badLogin = login.value.trim() === '';
        var badPass  = pass.value === '';
        setInvalid(login, badLogin);
        setInvalid(pass, badPass);

        if (badLogin || badPass) {
            e.preventDefault();
            (badLogin ? login : pass).focus();
            return;
        }
        setLoading(true);
    });

    // If the user comes back with the browser Back button, reset the button
    window.addEventListener('pageshow', function () { setLoading(false); });
})();