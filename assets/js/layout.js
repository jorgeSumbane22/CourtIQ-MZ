/* ==========================================================================
   CourtIQMZ — MOLDURA COMUM (sidebar, navbar e footer)
   Tema claro/escuro, menu em ecrãs pequenos, seletor de clube, avisos (toast)
   e confirmação ao sair. Antes isto estava copiado no fim de cada layout.
   ========================================================================== */
(function () {
    'use strict';

    var html = document.documentElement;

    // ---------------------------------------------------------------- tema
    function currentTheme() { return html.classList.contains('dark-mode') ? 'dark' : 'light'; }
    function updateLabel() {
        var label = document.getElementById('themeLabel');
        if (label) label.textContent = currentTheme() === 'dark' ? T('Modo claro') : T('Modo escuro');
    }
    function setTheme(theme) {
        html.classList.remove('dark-mode', 'light-mode');
        html.classList.add(theme === 'dark' ? 'dark-mode' : 'light-mode');
        document.cookie = 'courtIQ_dark_mode=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
        updateLabel();
    }
    window.toggleDarkMode = function () { setTheme(currentTheme() === 'dark' ? 'light' : 'dark'); };

    // ---------------------------------------------------------------- menu lateral em ecrãs pequenos
    var sidebar = document.getElementById('sidebar');
    function setOpen(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        var btn = document.getElementById('menuToggle');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    window.toggleSidebar = function () { setOpen(!(sidebar && sidebar.classList.contains('open'))); };
    window.closeSidebar = function () { setOpen(false); };

    // ---------------------------------------------------------------- avisos
    var toastIcons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    window.showToast = function (message, type) {
        var toast = document.getElementById('toastEl');
        var msg = document.getElementById('toastMsg');
        var icon = document.getElementById('toastIcon');
        if (!toast || !msg) return;
        type = toastIcons[type] ? type : 'info';
        msg.textContent = message || T('Operação concluída');
        if (icon) icon.className = 'fa-solid ' + toastIcons[type];
        toast.className = 'toast show ' + type;
        clearTimeout(toast._timeout);
        toast._timeout = setTimeout(function () { toast.classList.remove('show'); }, 4000);
    };

    document.addEventListener('click', function (e) {
        if (e.target.closest('#darkModeToggle')) { e.preventDefault(); window.toggleDarkMode(); return; }
        if (e.target.closest('#menuToggle')) { window.toggleSidebar(); return; }
        if (e.target.id === 'sidebarBackdrop') { setOpen(false); return; }
        var sair = e.target.closest('[data-confirm-leave]');
        if (sair && !window.confirm(T('Tem a certeza de que quer sair?'))) e.preventDefault();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 768) setOpen(false); });

    // ---------------------------------------------------------------- barra do site público (topnav.php)
    function closeDropdowns(except) {
        document.querySelectorAll('.pubnav-dd.is-open').forEach(function (li) {
            if (li === except) return;
            li.classList.remove('is-open');
            li.querySelector('[data-dd]').setAttribute('aria-expanded', 'false');
        });
    }
    document.addEventListener('click', function (e) {
        var burger = e.target.closest('[data-topnav-toggle]');
        var nav = document.getElementById('pubnav');
        if (burger && nav) {
            var aberto = !nav.classList.contains('is-open');
            nav.classList.toggle('is-open', aberto);
            burger.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            return;
        }
        var dd = e.target.closest('[data-dd]');
        closeDropdowns(dd ? dd.parentNode : null);
        if (dd) {
            var abre = !dd.parentNode.classList.contains('is-open');
            dd.parentNode.classList.toggle('is-open', abre);
            dd.setAttribute('aria-expanded', abre ? 'true' : 'false');
        }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDropdowns(null); });

    // seletor de clube (sidebar e barra do site): recarrega a página no clube escolhido
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-team-switch]')) window.location.href = '?equipa=' + encodeURIComponent(e.target.value);
    });

    updateLabel();
})();
