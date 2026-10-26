/**
 * CourtIQMZ — site público
 * Comportamento partilhado pelas páginas de /visitante:
 *   - filtros por etiqueta e pesquisa por nome ([data-filter])
 *   - visualizador de fotografias (.lp-photo)
 */
(function () {
    'use strict';

    var root = document.querySelector('.lp');
    if (!root) return;

    // ------------------------------------------------------------------ filtros
    // <div data-filter>
    //   <input data-search> <button data-filter-value="win">…</button>
    //   <… data-filter-item data-tags="finished win" data-name="texto">
    //   <p data-filter-empty hidden>…</p>
    root.querySelectorAll('[data-filter]').forEach(function (scope) {
        var items   = scope.querySelectorAll('[data-filter-item]');
        var buttons = scope.querySelectorAll('[data-filter-value]');
        var search  = scope.querySelector('[data-search]');
        var empty   = scope.querySelector('[data-filter-empty]');
        var groups  = scope.querySelectorAll('[data-filter-group]');
        var active  = '';

        buttons.forEach(function (btn) {
            if (btn.classList.contains('is-active')) active = btn.getAttribute('data-filter-value');
        });

        function apply() {
            var term  = search ? search.value.trim().toLowerCase() : '';
            var shown = 0;
            items.forEach(function (item) {
                var tags = (item.getAttribute('data-tags') || '').split(/\s+/);
                var name = item.getAttribute('data-name') || '';
                var ok = (!active || tags.indexOf(active) !== -1) && (!term || name.indexOf(term) !== -1);
                item.hidden = !ok;
                if (ok) shown++;
            });
            // esconde os títulos de grupo (ex.: meses) que ficaram sem itens visíveis
            groups.forEach(function (group) {
                group.hidden = !group.querySelector('[data-filter-item]:not([hidden])');
            });
            if (empty) empty.hidden = shown > 0;
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                active = btn.getAttribute('data-filter-value');
                buttons.forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                    b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                });
                apply();
            });
        });
        if (search) search.addEventListener('input', apply);
        apply();
    });

    // ------------------------------------------------------------------ fotografias
    var photos = root.querySelectorAll('.lp-photo[data-full]');
    if (photos.length) {
        var box = document.createElement('div');
        box.className = 'lp-lightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.innerHTML = '<button type="button" aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button><img alt=""><p></p>';
        document.body.appendChild(box);

        var img = box.querySelector('img');
        var caption = box.querySelector('p');
        var lastFocus = null;

        function close() {
            box.classList.remove('is-open');
            if (lastFocus) lastFocus.focus();
        }

        photos.forEach(function (photo) {
            photo.addEventListener('click', function () {
                lastFocus = photo;
                img.src = photo.getAttribute('data-full');
                img.alt = photo.getAttribute('data-caption') || '';
                caption.textContent = photo.getAttribute('data-caption') || '';
                box.classList.add('is-open');
                box.querySelector('button').focus();
            });
        });
        box.addEventListener('click', function (e) { if (e.target !== img) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    }
})();
