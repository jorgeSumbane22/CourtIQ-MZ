/* ==========================================================================
   CourtIQMZ — CHAT dos painéis (includes/panel_pages/chat.php)
   Todo o texto vindo do servidor entra com textContent: nunca como HTML.
   ========================================================================== */
(function () {
    'use strict';

    var root = document.getElementById('chat');
    if (!root) return;

    var state = JSON.parse(root.getAttribute('data-state'));
    var el = {
        threads: document.getElementById('chatThreads'), filter: document.getElementById('chatFilter'),
        log: document.getElementById('chatLog'), form: document.getElementById('chatForm'), input: document.getElementById('chatInput'),
        title: document.getElementById('chatTitle'), sub: document.getElementById('chatSub'), avatar: document.getElementById('chatAvatar'),
        error: document.getElementById('chatError'), back: document.getElementById('chatBack'),
        people: document.getElementById('chatPeople'), peopleFilter: document.getElementById('peopleFilter'),
        dialog: document.getElementById('novaConversa')
    };

    var open = state.open;          // -1 nenhuma, 0 sala da equipa, >0 conversa privada
    var messages = [];              // mensagens da conversa aberta
    var threads = state.threads;
    var roomLast = state.roomLast;
    var busy = false;
    var timer = null;
    var ROOM_KEY = 'courtiq_chat_room_seen_' + state.me;

    function node(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function icon(name) { return node('i', 'fa-solid fa-' + name); }
    function initial(name) { return (name || '?').trim().charAt(0).toUpperCase(); }
    function parse(at) { return new Date(String(at).replace(' ', 'T')); }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function hour(at) { var d = parse(at); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function dayKey(at) { return String(at).slice(0, 10); }
    function dayLabel(at) {
        var d = parse(at), today = new Date(), y = new Date(); y.setDate(today.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return T('Hoje');
        if (d.toDateString() === y.toDateString()) return T('Ontem');
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
    }
    function shortTime(at) { return parse(at).toDateString() === new Date().toDateString() ? hour(at) : dayLabel(at).slice(0, 5); }
    function seenRoom() { try { return parseInt(localStorage.getItem(ROOM_KEY) || '0', 10); } catch (e) { return 0; } }
    function markRoomSeen() { try { localStorage.setItem(ROOM_KEY, String(roomLast)); } catch (e) { /* sem armazenamento: fica sem o ponto */ } }

    function person(id) {
        var all = threads.concat(state.people);
        for (var i = 0; i < all.length; i++) if (all[i].id === id) return all[i];
        return null;
    }
    function subtitle(p) { return [p.role, p.team].filter(Boolean).join(' · '); }

    // ---------------------------------------------------------------- lista de conversas
    function threadButton(id, name, sub, at, unread, online, isRoom) {
        var b = node('button', 'pn-chat-thread' + (id === open ? ' is-active' : ''));
        b.type = 'button';
        b.setAttribute('data-name', (name + ' ' + sub).toLowerCase());
        var av = node('span', 'lp-avatar lp-avatar--sm' + (online ? ' is-online' : ''));
        if (isRoom) av.appendChild(icon('users')); else av.textContent = initial(name);
        var mid = node('span', 'grow');
        mid.appendChild(node('b', '', name));
        mid.appendChild(node('small', '', sub));
        var end = node('span', 'pn-chat-meta');
        if (at) end.appendChild(node('time', '', shortTime(at)));
        if (unread) end.appendChild(node('span', 'pn-chat-badge', unread === true ? '' : String(unread)));
        b.appendChild(av); b.appendChild(mid); b.appendChild(end);
        b.addEventListener('click', function () { openThread(id); });
        return b;
    }

    function renderThreads() {
        var q = el.filter.value.trim().toLowerCase();
        el.threads.textContent = '';
        if (state.hasRoom) {
            el.threads.appendChild(threadButton(0, state.roomName, T('Toda a equipa'), null, open !== 0 && roomLast > seenRoom(), false, true));
        }
        var shown = 0;
        threads.forEach(function (t) {
            if (q && (t.name + ' ' + t.role + ' ' + t.team).toLowerCase().indexOf(q) === -1) return;
            shown++;
            el.threads.appendChild(threadButton(t.id, t.name, (t.last_mine ? T('Você: ') : '') + t.last, t.at, t.id === open ? 0 : t.unread, t.online, false));
        });
        // conversa acabada de abrir, ainda sem mensagens
        if (open > 0 && !threads.some(function (t) { return t.id === open; })) {
            var p = person(open);
            if (p) el.threads.appendChild(threadButton(p.id, p.name, T('Nova conversa'), null, 0, p.online, false));
        } else if (!shown && !state.hasRoom) {
            el.threads.appendChild(node('p', 'pn-chat-none', q ? T('Nenhuma conversa encontrada.') : T('Ainda não tem conversas.')));
        }
    }

    // ---------------------------------------------------------------- mensagens
    function renderLog(stick) {
        var nearEnd = el.log.scrollHeight - el.log.scrollTop - el.log.clientHeight < 80;
        el.log.textContent = '';
        if (open < 0) {
            var none = node('div', 'pn-empty');
            none.appendChild(icon('comments'));
            none.appendChild(document.createTextNode(T('Escolha uma conversa ou comece uma nova.')));
            el.log.appendChild(none);
            return;
        }
        if (!messages.length) {
            var empty = node('div', 'pn-empty');
            empty.appendChild(icon('comment-dots'));
            empty.appendChild(document.createTextNode(open === 0 ? T('Ainda ninguém escreveu na sala. Comece a conversa.') : T('Ainda não há mensagens. Diga olá.')));
            el.log.appendChild(empty);
            return;
        }
        var lastDay = '', lastFrom = null;
        messages.forEach(function (m) {
            if (dayKey(m.at) !== lastDay) {
                lastDay = dayKey(m.at); lastFrom = null;
                el.log.appendChild(node('div', 'pn-chat-day', dayLabel(m.at)));
            }
            var row = node('div', 'pn-chat-msg' + (m.mine ? ' is-mine' : ''));
            var from = m.mine ? 'me' : m.name;
            if (open === 0 && !m.mine && from !== lastFrom) row.appendChild(node('span', 'pn-chat-from', m.name + (m.role ? ' · ' + m.role : '')));
            lastFrom = from;
            var bubble = node('div', 'pn-chat-bubble');
            bubble.appendChild(node('p', '', m.text));
            var foot = node('span', 'pn-chat-time', hour(m.at));
            if (m.can_delete) {
                var del = node('button', 'pn-chat-del');
                del.type = 'button'; del.title = T('Apagar mensagem'); del.setAttribute('aria-label', T('Apagar mensagem'));
                del.appendChild(icon('trash'));
                del.addEventListener('click', function () { remove(m.id); });
                foot.appendChild(del);
            }
            bubble.appendChild(foot);
            row.appendChild(bubble);
            el.log.appendChild(row);
        });
        if (stick || nearEnd) el.log.scrollTop = el.log.scrollHeight;
    }

    function renderHead() {
        el.avatar.textContent = '';
        el.avatar.className = 'lp-avatar lp-avatar--sm';
        if (open === 0) {
            el.avatar.appendChild(icon('users'));
            el.title.textContent = state.roomName;
            el.sub.textContent = T('Todos os membros da equipa veem estas mensagens');
        } else if (open > 0) {
            var p = person(open) || { name: T('Conversa'), role: '', team: '', online: false };
            el.avatar.textContent = initial(p.name);
            if (p.online) el.avatar.classList.add('is-online');
            el.title.textContent = p.name;
            el.sub.textContent = subtitle(p) + (p.online ? T(' · online') : '');
        } else {
            el.avatar.appendChild(icon('comments'));
            el.title.textContent = 'Chat';
            el.sub.textContent = '';
        }
        el.form.hidden = open < 0;
    }

    function showError(text) {
        el.error.textContent = text || '';
        el.error.hidden = !text;
    }

    // ---------------------------------------------------------------- servidor
    function request(params, post) {
        var body = new URLSearchParams(params);
        var opts = { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } };
        var url = 'chat.php';
        if (post) { body.append('csrf_token', state.csrf); opts.method = 'POST'; opts.body = body; }
        else { url += '?' + body.toString(); }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { throw new Error(T('A sessão terminou. Atualize a página.')); });
        }).then(function (data) {
            if (!data.ok) throw new Error(data.error || T('Não foi possível concluir o pedido.'));
            return data;
        });
    }

    function poll(full) {
        if (busy) return Promise.resolve();
        busy = true;
        var asked = open;
        var last = full || !messages.length ? 0 : messages[messages.length - 1].id;
        return request({ api: 'poll', 'with': asked, after: last, first: messages.length ? messages[0].id : 0, count: messages.length })
            .then(function (data) {
                if (asked !== open) return;               // mudou de conversa entretanto
                threads = data.threads; roomLast = data.room_last;
                var fresh = data.messages.length > 0;
                if (last === 0 || data.reload) messages = data.messages; else messages = messages.concat(data.messages);
                if (open === 0) markRoomSeen();
                renderThreads(); renderHead();
                if (fresh || last === 0 || data.reload) renderLog(last === 0);
                showError('');
            })
            .catch(function (e) { showError(e.message); })
            .then(function () { busy = false; });
    }

    function schedule() {
        clearInterval(timer);
        timer = setInterval(function () { if (!document.hidden) poll(false); }, 4000);
    }

    function openThread(id) {
        open = id; messages = [];
        root.classList.add('is-open');
        renderThreads(); renderHead(); renderLog(true);
        busy = false;
        poll(true).then(function () { if (window.matchMedia('(min-width: 861px)').matches) el.input.focus(); });
        try { history.replaceState(null, '', id >= 0 ? '?com=' + id : location.pathname); } catch (e) { /* sem histórico */ }
    }

    function remove(id) {
        if (!window.confirm(T('Apagar esta mensagem?'))) return;
        request({ api: 'delete', id: id }, true).then(function () {
            messages = messages.filter(function (m) { return m.id !== id; });
            renderLog(false);
        }).catch(function (e) { showError(e.message); });
    }

    // ---------------------------------------------------------------- eventos
    el.form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = el.input.value.trim();
        if (!text || open < 0) return;
        var btn = el.form.querySelector('button');
        btn.disabled = true;
        request({ api: 'send', 'with': open, content: text }, true).then(function () {
            el.input.value = ''; grow();
            busy = false;
            return poll(false);
        }).then(function () { el.log.scrollTop = el.log.scrollHeight; })
          .catch(function (err) { showError(err.message); })
          .then(function () { btn.disabled = false; el.input.focus(); });
    });
    // Enter envia, Shift+Enter muda de linha
    el.input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); el.form.requestSubmit(); }
    });
    function grow() { el.input.style.height = 'auto'; el.input.style.height = Math.min(el.input.scrollHeight, 140) + 'px'; }
    el.input.addEventListener('input', grow);
    el.filter.addEventListener('input', renderThreads);
    el.back.addEventListener('click', function () { root.classList.remove('is-open'); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(false); });

    // nova conversa
    function renderPeople() {
        var q = el.peopleFilter.value.trim().toLowerCase();
        el.people.textContent = '';
        var lastTeam = null, shown = 0;
        state.people.forEach(function (p) {
            if (q && (p.name + ' ' + p.role + ' ' + p.team).toLowerCase().indexOf(q) === -1) return;
            var group = p.team || T('Suporte CourtIQMZ');
            if (group !== lastTeam) { lastTeam = group; el.people.appendChild(node('div', 'pn-chat-group', group)); }
            var b = node('button', 'pn-chat-thread');
            b.type = 'button';
            b.appendChild(node('span', 'lp-avatar lp-avatar--sm' + (p.online ? ' is-online' : ''), initial(p.name)));
            var mid = node('span', 'grow');
            mid.appendChild(node('b', '', p.name));
            mid.appendChild(node('small', '', p.role + (p.online ? T(' · online') : '')));
            b.appendChild(mid);
            b.addEventListener('click', function () { el.dialog.close(); openThread(p.id); });
            el.people.appendChild(b);
            shown++;
        });
        if (!shown) el.people.appendChild(node('p', 'pn-chat-none', T('Ninguém encontrado.')));
    }
    el.peopleFilter.addEventListener('input', renderPeople);

    renderPeople(); renderThreads(); renderHead(); renderLog(true);
    if (open >= 0) { root.classList.add('is-open'); poll(true); }
    schedule();
})();
