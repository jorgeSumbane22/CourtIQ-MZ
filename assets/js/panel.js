/**
 * CourtIQMZ — painéis
 *   - janelas:      <button data-modal="#id" data-values='{"campo":"valor"}'> abre o <dialog id="id">
 *                   e preenche os campos do formulário lá dentro pelo atributo name
 *   - fechar:       <button data-modal-close>
 *   - confirmação:  <form data-confirm="Tem a certeza?">
 *   - separadores:  <div data-tabs> <button data-tab="x"> … <section data-tab-panel="x">
 */
(function () {
    'use strict';

    function fill(dialog, values) {
        Object.keys(values).forEach(function (name) {
            var fields = dialog.querySelectorAll('[name="' + name + '"], [data-field="' + name + '"]');   // data-field: caixas de listas (name="x[]")
            fields.forEach(function (field) {
                var value = values[name] === null || values[name] === undefined ? '' : values[name];
                if (field.type === 'checkbox') {
                    field.checked = value === true || value === 1 || value === '1';
                } else if (field.type === 'radio') {
                    field.checked = String(field.value) === String(value);
                } else {
                    field.value = value;
                }
            });
            dialog.querySelectorAll('[data-text="' + name + '"]').forEach(function (el) {
                el.textContent = values[name] === null ? '' : values[name];
            });
        });
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal]');
        if (opener) {
            var dialog = document.querySelector(opener.getAttribute('data-modal'));
            if (!dialog) return;
            e.preventDefault();
            var form = dialog.querySelector('form');
            if (form && opener.hasAttribute('data-reset')) form.reset();
            if (opener.hasAttribute('data-values')) {
                try { fill(dialog, JSON.parse(opener.getAttribute('data-values'))); } catch (err) { /* valores inválidos: abre vazio */ }
            }
            if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
            return;
        }

        var closer = e.target.closest('[data-modal-close]');
        if (closer) {
            var parent = closer.closest('dialog');
            if (parent) { e.preventDefault(); parent.close ? parent.close() : parent.removeAttribute('open'); }
            return;
        }

        // clicar fora da janela fecha-a
        if (e.target.tagName === 'DIALOG' && e.target.classList.contains('pn-modal')) {
            e.target.close();
        }
    });

    document.addEventListener('submit', function (e) {
        var message = e.target.getAttribute && e.target.getAttribute('data-confirm');
        if (message && !window.confirm(message)) e.preventDefault();
    });

    document.querySelectorAll('[data-tabs]').forEach(function (scope) {
        var tabs = scope.querySelectorAll('[data-tab]');
        var panels = scope.querySelectorAll('[data-tab-panel]');
        function show(name) {
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-tab') === name;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            panels.forEach(function (p) { p.hidden = p.getAttribute('data-tab-panel') !== name; });
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                show(t.getAttribute('data-tab'));
                if (history.replaceState) history.replaceState(null, '', '#' + t.getAttribute('data-tab'));
            });
        });
        var initial = location.hash.replace('#', '');
        var exists = Array.prototype.some.call(tabs, function (t) { return t.getAttribute('data-tab') === initial; });
        show(exists ? initial : (tabs[0] ? tabs[0].getAttribute('data-tab') : ''));
    });

    // abre automaticamente a janela indicada em data-open-on-load (ex.: depois de um erro de validação)
    var auto = document.querySelector('dialog[data-open-on-load]');
    if (auto && typeof auto.showModal === 'function') auto.showModal();
})();
