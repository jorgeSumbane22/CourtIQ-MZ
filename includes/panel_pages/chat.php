<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (todos): Chat
 * Sala da equipa + conversas privadas. Cada um só fala com colegas da mesma equipa
 * e com o superadmin (suporte); o superadmin fala com todos e não tem sala.
 *
 * A mesma página responde aos pedidos da interface (assets/js/chat.js):
 *   GET  ?api=poll&with=ID&after=N&first=N   mensagens novas + lista de conversas
 *   POST api=send | api=delete               (com csrf_token)
 */

require_once __DIR__ . '/../repositories/ChatRepository.php';

if (panel_name() === 'superadmin') {
    [$pdo, $user] = panel_boot_global();
    $team = null;
} else {
    [$pdo, , $team, $user] = panel_boot(panel_roles());
}

$eu    = (int) $user['id'];
$papel = (string) ($user['role'] ?? '');
$chat  = new ChatRepository($pdo);
$chat->updateUserActivity($eu);

$temSala   = $chat->hasRoom();
$moderador = $papel === 'admin';
// estes rótulos seguem em JSON para o browser (não passam pelo tradutor de páginas), por isso traduzem-se aqui
$papeis    = array_map('t', ['superadmin' => 'Suporte CourtIQMZ', 'admin' => 'Administrador', 'treinador' => 'Treinador', 'jogador' => 'Jogador']);

/** Mensagem no formato que a interface espera. */
$formatar = function (array $m) use ($eu, $moderador, $papeis): array {
    return [
        'id'    => (int) $m['id'],
        'mine'  => (int) $m['user_id'] === $eu,
        'name'  => (string) ($m['user_name'] ?? 'Conta removida'),
        'role'  => $papeis[$m['user_role'] ?? ''] ?? '',
        'text'  => (string) $m['content'],
        'at'    => (string) $m['created_at'],
        'can_delete' => (int) $m['user_id'] === $eu || $moderador,
    ];
};
$conversas = function () use ($chat, $eu, $papeis): array {
    return array_map(fn($c) => [
        'id' => (int) $c['id'], 'name' => (string) $c['name'], 'role' => $papeis[$c['role']] ?? '', 'team' => (string) ($c['team_name'] ?? ''),
        'online' => (bool) $c['online'], 'last' => mb_strimwidth((string) $c['last_message'], 0, 70, '…'), 'last_mine' => (int) $c['last_from'] === $eu,
        'at' => (string) $c['last_time'], 'unread' => (int) $c['unread'],
    ], $chat->threads($eu));
};
/** A conversa pedida é válida para este utilizador? (0 = sala da equipa) */
$podeAbrir = fn(int $com): bool => $com === 0 ? $temSala : $chat->canMessage($com);

// ============================================================================
// API (JSON)
// ============================================================================
$api = (string) ($_POST['api'] ?? $_GET['api'] ?? '');
if ($api !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $responder = function (array $dados, int $codigo = 200): void {
        http_response_code($codigo);
        if (isset($dados['error'])) $dados['error'] = t((string) $dados['error']);
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
        exit;
    };

    try {
        if ($api === 'poll') {
            $com = (int) ($_GET['with'] ?? -1);
            $out = ['ok' => true, 'messages' => [], 'reload' => false];
            if ($com >= 0 && $podeAbrir($com)) {
                $apos     = max(0, (int) ($_GET['after'] ?? 0));
                $primeira = max(0, (int) ($_GET['first'] ?? 0));
                $tenho    = max(0, (int) ($_GET['count'] ?? 0));
                $novas    = $chat->thread($eu, $com, $apos);
                // se alguém apagou mensagens, a contagem deixa de bater certo: manda-se a conversa inteira
                if ($apos > 0 && $chat->threadCount($eu, $com, $primeira) !== $tenho + count($novas)) {
                    $novas = $chat->thread($eu, $com);
                    $out['reload'] = true;
                }
                $out['messages'] = array_map($formatar, $novas);
                if ($com > 0) $chat->markPrivateMessagesAsRead($eu, $com);
            }
            $out['threads']   = $conversas();
            $out['room_last'] = $chat->roomLastId();
            $responder($out);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
            $responder(['ok' => false, 'error' => 'A sessão expirou. Atualize a página.'], 403);
        }

        if ($api === 'send') {
            $com   = (int) ($_POST['with'] ?? -1);
            $texto = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['content'] ?? '')));
            if ($com < 0 || !$podeAbrir($com)) $responder(['ok' => false, 'error' => 'Só pode enviar mensagens a membros da sua equipa.'], 403);
            if ($texto === '') $responder(['ok' => false, 'error' => 'Escreva uma mensagem.'], 422);
            if (mb_strlen($texto) > 2000) $responder(['ok' => false, 'error' => 'A mensagem é demasiado longa (máximo 2000 caracteres).'], 422);

            // no máximo 20 mensagens em 30 segundos
            $recentes = array_filter((array) ($_SESSION['chat_sent'] ?? []), fn($t) => (int) $t > time() - 30);
            if (count($recentes) >= 20) $responder(['ok' => false, 'error' => 'Está a enviar mensagens depressa demais. Aguarde um pouco.'], 429);
            $recentes[] = time();
            $_SESSION['chat_sent'] = array_values($recentes);

            $chat->sendMessage($eu, $texto, $com, $com === 0 ? 'general' : 'private');
            $responder(['ok' => true]);
        }

        if ($api === 'delete') {
            $responder(['ok' => $chat->deleteOwn((int) ($_POST['id'] ?? 0), $eu, $moderador)]);
        }

        $responder(['ok' => false, 'error' => 'Pedido desconhecido.'], 400);
    } catch (RuntimeException $e) {
        $responder(['ok' => false, 'error' => $e->getMessage()], 403);
    } catch (Throwable $e) {
        error_log('[CourtIQ] chat: ' . $e->getMessage());
        $responder(['ok' => false, 'error' => 'Não foi possível concluir o pedido.'], 500);
    }
}

// "Marcar todas como lidas" do menu de mensagens da barra superior
if (($_GET['action'] ?? '') === 'mark_all_read') {
    if (csrf_verify($_GET['token'] ?? '')) $chat->markAllAsRead($eu);
    header('Location: chat.php');
    exit;
}

// ============================================================================
// PÁGINA
// ============================================================================
$pessoas = array_map(fn($p) => [
    'id' => (int) $p['id'], 'name' => (string) $p['name'], 'role' => $papeis[$p['role']] ?? '', 'team' => (string) ($p['team_name'] ?? ''), 'online' => (bool) $p['online'],
], $chat->people($eu));

// conversa aberta à chegada: ?com=ID, senão a sala da equipa, senão a conversa mais recente
$lista = $conversas();
$abrir = isset($_GET['com']) ? (int) $_GET['com'] : ($temSala ? 0 : (int) ($lista[0]['id'] ?? -1));
if ($abrir < 0 || !$podeAbrir($abrir)) $abrir = $temSala ? 0 : -1;

$estado = [
    'me' => $eu, 'csrf' => csrf_token(), 'hasRoom' => $temSala, 'roomName' => $team ? t('Sala ' . $team['name']) : '',
    'open' => $abrir, 'threads' => $lista, 'people' => $pessoas, 'roomLast' => $chat->roomLastId(),
];

$pageTitle = 'Chat';
$activeNav = 'chat';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Chat', $temSala
        ? 'Sala de ' . $team['name'] . ' e conversas privadas com a equipa.'
        : 'Conversas privadas com administradores, treinadores e jogadores de todas as equipas.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novaConversa"><i class="fa-solid fa-pen-to-square"></i> Nova conversa</button>'); ?>

    <section class="lp-card pn-chat" id="chat" data-state="<?= pub_e(json_encode($estado, JSON_UNESCAPED_UNICODE)) ?>">
        <aside class="pn-chat-side" aria-label="Conversas">
            <div class="pn-chat-search"><div class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="chatFilter" placeholder="Procurar conversa..." aria-label="Procurar conversa"></div></div>
            <nav class="pn-chat-threads" id="chatThreads"></nav>
        </aside>

        <div class="pn-chat-main">
            <header class="pn-chat-head">
                <button type="button" class="pn-icon-btn pn-chat-back" id="chatBack" aria-label="Voltar às conversas"><i class="fa-solid fa-arrow-left"></i></button>
                <span class="lp-avatar lp-avatar--sm" id="chatAvatar"></span>
                <div class="grow"><b id="chatTitle"></b><small id="chatSub"></small></div>
            </header>
            <div class="pn-chat-log" id="chatLog" role="log" aria-live="polite" tabindex="0"></div>
            <form class="pn-chat-form" id="chatForm">
                <textarea id="chatInput" rows="1" maxlength="2000" placeholder="Escreva uma mensagem..." aria-label="Mensagem"></textarea>
                <button type="submit" class="lp-btn lp-btn--primary" aria-label="Enviar"><i class="fa-solid fa-paper-plane"></i></button>
            </form>
            <p class="pn-chat-error" id="chatError" role="alert" hidden></p>
        </div>
    </section>
</div>

<dialog class="pn-modal" id="novaConversa">
    <div>
        <div class="pn-modal-head"><h2>Nova conversa</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body">
            <div class="lp-search" style="margin-bottom:12px;"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="peopleFilter" placeholder="Procurar pessoa..." aria-label="Procurar pessoa"></div>
            <div class="pn-chat-people" id="chatPeople"></div>
        </div>
    </div>
</dialog>

<script src="../assets/js/chat.js?v=18" defer></script>
<?php require panel_layout('bottom'); ?>
