<?php
declare(strict_types=1);

/**
 * CourtIQMZ — A minha conta (adeptos)
 * A casa de quem criou conta em registar.php: novidades do clube que segue, avisos,
 * estado do pedido de jogador e dados da conta. Os outros perfis têm o seu painel.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/team_helper.php';
require_once __DIR__ . '/includes/public_helpers.php';

Auth::requireLogin();
TeamHelper::maintenanceGuard();

$pdo = Database::getConnection();

// o treinador pode ter aceite o pedido entretanto: a sessão acompanha o papel guardado
session_sync_role($pdo);
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
$paineis = ['superadmin' => 'superadmin', 'admin' => 'admin', 'treinador' => 'treinador', 'jogador' => 'jogador'];
if (isset($paineis[Auth::role()])) {
    header('Location: ' . $paineis[Auth::role()] . '/dashboard.php');
    exit;
}

$userId   = Auth::id();
$equipas  = array_column(TeamHelper::activeTeams(), null, 'id');
$posicoes = ['PG' => 'Base (PG)', 'SG' => 'Extremo-base (SG)', 'SF' => 'Extremo (SF)', 'PF' => 'Extremo-poste (PF)', 'C' => 'Poste (C)'];

$pedidoPendente = function () use ($pdo, $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM player_requests WHERE user_id = :u AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['u' => $userId]);
    return $stmt->fetch() ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['action'] ?? '');
    try {
        if (!csrf_verify($_POST['csrf_token'] ?? '')) throw new RuntimeException('A sessão expirou. Tente novamente.');
        $teamId = (int) ($_SESSION['user']['team_id'] ?? 0);

        if ($acao === 'profile') {
            $nome  = trim((string) ($_POST['name'] ?? ''));
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            if (mb_strlen($nome) < 3 || mb_strlen($nome) > 100) throw new RuntimeException('Indique o seu nome.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) throw new RuntimeException('Indique um email válido.');
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :id');
            $stmt->execute(['e' => $email, 'id' => $userId]);
            if ($stmt->fetch()) throw new RuntimeException('Esse email já está a ser usado por outra conta.');
            $pdo->prepare('UPDATE users SET name = :n, email = :e, avatar_initials = :i WHERE id = :id')
                ->execute(['n' => $nome, 'e' => $email, 'i' => mb_strtoupper(mb_substr($nome, 0, 2)), 'id' => $userId]);
            $_SESSION['user']['name'] = $_SESSION['user']['nome'] = $nome;
            $_SESSION['user']['email'] = $email;
            flash_set('success', 'Dados atualizados.');

        } elseif ($acao === 'password') {
            $atual = (string) ($_POST['current_password'] ?? '');
            $nova  = (string) ($_POST['new_password'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            if (!password_verify($atual, (string) $stmt->fetchColumn())) throw new RuntimeException('A palavra-passe atual não está correta.');
            if (strlen($nova) < 8 || strlen($nova) > 200) throw new RuntimeException('A nova palavra-passe deve ter pelo menos 8 caracteres.');
            if ($nova !== (string) ($_POST['confirm_password'] ?? '')) throw new RuntimeException('A confirmação não coincide.');
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => password_hash($nova, PASSWORD_DEFAULT), 'id' => $userId]);
            flash_set('success', 'Palavra-passe alterada.');

        } elseif ($acao === 'club') {
            require_can('site.follow');
            $novo = (int) ($_POST['team_id'] ?? 0);
            if (!isset($equipas[$novo])) throw new RuntimeException('Escolha um clube da lista.');
            if ($pedidoPendente()) throw new RuntimeException('Tem um pedido de jogador à espera. Cancele-o antes de mudar de clube.');
            $pdo->prepare("UPDATE users SET team_id = :t WHERE id = :id AND role = 'adepto'")->execute(['t' => $novo, 'id' => $userId]);
            $_SESSION['user']['team_id'] = $novo;
            $_SESSION['public_team_id']  = $novo;
            flash_set('success', 'Passou a seguir ' . $equipas[$novo]['name'] . '.');

        } elseif ($acao === 'request') {
            require_can('site.request_player');
            if (!isset($equipas[$teamId])) throw new RuntimeException('Escolha primeiro o clube que segue.');
            if ($pedidoPendente()) throw new RuntimeException('Já tem um pedido à espera de resposta.');
            // no máximo 3 pedidos por mês, para não incomodar o treinador
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM player_requests WHERE user_id = :u AND created_at > :desde');
            $stmt->execute(['u' => $userId, 'desde' => date('Y-m-d H:i:s', strtotime('-30 days'))]);
            if ((int) $stmt->fetchColumn() >= 3) throw new RuntimeException('Já fez vários pedidos este mês. Fale diretamente com o treinador.');

            $posicao = (string) ($_POST['position'] ?? '');
            $nasc    = trim((string) ($_POST['birth_date'] ?? ''));
            $numero  = trim((string) ($_POST['jersey_number'] ?? ''));
            $msg     = trim((string) ($_POST['message'] ?? ''));
            $ts      = $nasc !== '' ? strtotime($nasc) : false;
            if (!isset($posicoes[$posicao])) throw new RuntimeException('Escolha a sua posição.');
            if (!$ts || $ts > strtotime('-8 years') || $ts < strtotime('-70 years')) throw new RuntimeException('Indique a sua data de nascimento.');
            if ($numero !== '' && (!ctype_digit($numero) || (int) $numero > 99)) throw new RuntimeException('O número da camisola deve estar entre 0 e 99.');
            if (mb_strlen($msg) > 500) throw new RuntimeException('A mensagem é demasiado longa.');

            $pdo->prepare('INSERT INTO player_requests (user_id, team_id, position, escalao, birth_date, jersey_number, message) VALUES (:u, :t, :p, :e, :b, :n, :m)')
                ->execute(['u' => $userId, 't' => $teamId, 'p' => $posicao, 'e' => escalao_from_post($equipas[$teamId], $_POST['escalao'] ?? ''), 'b' => $nasc, 'n' => $numero !== '' ? (int) $numero : null, 'm' => $msg ?: null]);
            $stmt = $pdo->prepare("SELECT id, role FROM users WHERE team_id = :t AND role IN ('treinador', 'admin') AND status = 'active'");
            $stmt->execute(['t' => $teamId]);
            $staff = $stmt->fetchAll();
            $treinadores = array_filter($staff, fn($s) => $s['role'] === 'treinador');
            foreach ($treinadores ?: $staff as $s) {
                notify_user($pdo, (int) $s['id'], 'Novo pedido de inscrição de jogador',
                    $_SESSION['user']['name'] . ' pede para entrar no plantel de ' . $equipas[$teamId]['name'] . '. Aceite ou recuse em "Pedidos de inscrição".', 'warning');
            }
            flash_set('success', 'Pedido enviado ao treinador de ' . $equipas[$teamId]['name'] . '.');

        } elseif ($acao === 'cancel_request') {
            $pdo->prepare("DELETE FROM player_requests WHERE user_id = :u AND status = 'pending'")->execute(['u' => $userId]);
            flash_set('success', 'Pedido cancelado.');

        } elseif ($acao === 'read_all') {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :u')->execute(['u' => $userId]);
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] conta: ' . $e->getMessage());
    }
    header('Location: conta.php');
    exit;
}

// ============================================================================
// DADOS
// ============================================================================
$stmt = $pdo->prepare('SELECT id, name, username, email, team_id, created_at FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$conta  = $stmt->fetch();
$teamId = (int) ($conta['team_id'] ?? 0);
$team   = $equipas[$teamId] ?? null;

$buscar = function (string $sql, array $params) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
};
$proximos   = $team ? $buscar("SELECT * FROM games WHERE team_id = :t AND status = 'scheduled' AND game_date >= CURDATE() ORDER BY game_date, game_time LIMIT 3", ['t' => $teamId]) : [];
$resultados = $team ? $buscar("SELECT * FROM games WHERE team_id = :t AND status = 'finished' ORDER BY game_date DESC LIMIT 3", ['t' => $teamId]) : [];
$noticias   = $team ? $buscar('SELECT id, title, published_at FROM news WHERE team_id = :t AND published_at <= CURDATE() ORDER BY published_at DESC, id DESC LIMIT 4', ['t' => $teamId]) : [];
$avisos     = $buscar('SELECT * FROM notifications WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 20', ['u' => $userId]);
$porLer     = count(array_filter($avisos, fn($a) => !(int) $a['is_read']));
$pedido     = $buscar('SELECT r.*, t.name AS team_name FROM player_requests r JOIN teams t ON t.id = r.team_id WHERE r.user_id = :u ORDER BY r.id DESC LIMIT 1', ['u' => $userId])[0] ?? null;

$boasVindas = ['bemvindo' => 'Conta criada. Bem-vindo ao CourtIQMZ!', 'pedido' => 'Conta criada. O seu pedido de jogador seguiu para o treinador do clube.'][(string) ($_GET['ok'] ?? '')] ?? null;
$tiposAviso = ['success' => ['circle-check', 'win'], 'warning' => ['triangle-exclamation', 'gold'], 'error' => ['circle-xmark', 'loss'], 'info' => ['circle-info', 'info']];

$_SESSION['public_team_id'] = $teamId ?: ($_SESSION['public_team_id'] ?? 0);
$pageTitle = 'A minha conta';
$pageIcon  = 'fa-solid fa-user';
$activeNav = 'conta';
$basePath  = '';
require __DIR__ . '/includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head($team['name'] ?? 'CourtIQMZ', 'Olá, ' . explode(' ', (string) $conta['name'])[0], $team
        ? 'Aqui está tudo o que se passa em ' . $team['name'] . '.'
        : 'Escolha um clube para começar a seguir.', [[$porLer, 'Avisos por ler'], [count($proximos), 'Próximos jogos']]); ?>

    <?php if ($boasVindas): ?><div class="lp-notice" role="status"><i class="fa-solid fa-circle-check"></i><div><?= pub_e($boasVindas) ?></div></div><?php endif; ?>

    <?php if ($pedido && $pedido['status'] === 'pending'): ?>
    <div class="lp-notice" role="status" style="align-items:center;">
        <i class="fa-solid fa-user-clock" style="color:var(--gold);"></i>
        <div style="flex:1;"><b>Pedido de jogador à espera.</b> O treinador de <?= pub_e($pedido['team_name']) ?> ainda não respondeu ao pedido feito em <?= pub_e(pub_date($pedido['created_at'])) ?>. Entretanto a conta funciona como adepto.</div>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_request">
            <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm">Cancelar pedido</button></form>
    </div>
    <?php elseif ($pedido && $pedido['status'] === 'rejected'): ?>
    <div class="lp-notice lp-notice--error" role="status"><i class="fa-solid fa-circle-xmark"></i>
        <div><b>O pedido de jogador foi recusado</b> por <?= pub_e($pedido['team_name']) ?>.<?= $pedido['decision_note'] ? ' Motivo: ' . pub_e($pedido['decision_note']) . '.' : '' ?> A conta continua ativa como adepto.</div></div>
    <?php endif; ?>

    <div class="lp-grid lp-grid--2">
        <section class="lp-card">
            <div class="lp-pad">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
                    <h2 class="lp-sub" style="margin:0;"><i class="fa-solid fa-bell"></i> Avisos</h2>
                    <?php if ($porLer): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>"><input type="hidden" name="action" value="read_all">
                        <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm">Marcar como lidos</button></form><?php endif; ?>
                </div>
                <div class="lp-stack">
                    <?php foreach ($avisos as $a): [$icone, $cor] = $tiposAviso[$a['type']] ?? $tiposAviso['info']; ?>
                    <div style="display:flex;gap:12px;<?= (int) $a['is_read'] ? 'opacity:0.65;' : '' ?>">
                        <span class="lp-badge lp-badge--<?= $cor ?>" style="height:28px;flex:none;"><i class="fa-solid fa-<?= $icone ?>"></i></span>
                        <div style="min-width:0;"><b style="color:var(--text);display:block;"><?= pub_e($a['title']) ?></b>
                            <span style="color:var(--text-secondary);font-size:14px;"><?= pub_e($a['message']) ?></span>
                            <small style="display:block;color:var(--muted);font-size:12px;margin-top:2px;"><?= pub_e(pub_date($a['created_at'], 'd/m/Y H:i')) ?></small></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$avisos): ?><p class="lp-empty">Ainda não há avisos. Quando o clube publicar uma notícia, marcar um jogo ou registar um resultado, aparece aqui.</p><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="lp-card">
            <div class="lp-pad">
                <h2 class="lp-sub"><i class="fa-solid fa-calendar-days"></i> Próximos jogos</h2>
                <div class="lp-stack" style="margin-bottom:24px;">
                    <?php foreach ($proximos as $g): ?>
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
                        <div><b style="color:var(--text);">vs <?= pub_e($g['opponent']) ?></b><small style="display:block;color:var(--muted);font-size:12.5px;"><?= pub_e(pub_date($g['game_date'])) ?> · <?= pub_e(pub_time($g['game_time'])) ?> · <?= ($g['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></small></div>
                        <?php if (($g['home_away'] ?? 'home') !== 'away'): ?><a class="lp-btn lp-btn--ghost lp-btn--sm" href="visitante/bilhetes.php?jogo_id=<?= (int) $g['id'] ?>"><i class="fa-solid fa-ticket"></i> Bilhetes</a><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$proximos): ?><p class="lp-empty">Sem jogos marcados.</p><?php endif; ?>
                </div>

                <h2 class="lp-sub"><i class="fa-solid fa-check-double"></i> Últimos resultados</h2>
                <div class="lp-stack" style="margin-bottom:24px;">
                    <?php foreach ($resultados as $g): $ganhou = (int) $g['our_score'] > (int) $g['opponent_score']; ?>
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
                        <div><b style="color:var(--text);">vs <?= pub_e($g['opponent']) ?></b><small style="display:block;color:var(--muted);font-size:12.5px;"><?= pub_e(pub_date($g['game_date'])) ?></small></div>
                        <span class="lp-badge lp-badge--<?= $ganhou ? 'win' : 'loss' ?>"><?= (int) $g['our_score'] ?>–<?= (int) $g['opponent_score'] ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$resultados): ?><p class="lp-empty">Ainda sem resultados.</p><?php endif; ?>
                </div>

                <h2 class="lp-sub"><i class="fa-solid fa-newspaper"></i> Notícias</h2>
                <div class="lp-stack">
                    <?php foreach ($noticias as $n): ?>
                    <a href="visitante/noticias.php?id=<?= (int) $n['id'] ?>" style="display:block;"><b style="color:var(--text);"><?= pub_e($n['title']) ?></b><small style="display:block;color:var(--muted);font-size:12.5px;"><?= pub_e(pub_date($n['published_at'])) ?></small></a>
                    <?php endforeach; ?>
                    <?php if (!$noticias): ?><p class="lp-empty">Sem notícias.</p><?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="lp-grid lp-grid--2">
        <section class="lp-card"><div class="lp-pad">
            <h2 class="lp-sub"><i class="fa-solid fa-shield-halved"></i> O clube que sigo</h2>
            <form method="post" class="lp-form">
                <input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>"><input type="hidden" name="action" value="club">
                <div class="lp-field"><label for="c-team">Clube</label>
                    <select id="c-team" name="team_id" required><?php foreach ($equipas as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $teamId === (int) $t['id'] ? 'selected' : '' ?>><?= pub_e($t['name']) ?></option><?php endforeach; ?></select></div>
                <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-rotate"></i> Mudar de clube</button>
            </form>

            <?php if (!$pedido || $pedido['status'] !== 'pending'): ?>
            <h2 class="lp-sub" style="margin-top:28px;"><i class="fa-solid fa-basketball"></i> Sou jogador deste clube</h2>
            <form method="post" class="lp-form">
                <input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>"><input type="hidden" name="action" value="request">
                <p style="color:var(--text-secondary);font-size:14px;">O treinador de <?= pub_e($team['name'] ?? 'o clube') ?> recebe o pedido. Se o aceitar, a sua conta passa a ter o painel do jogador.</p>
                <div class="lp-field"><label for="q-pos">Posição</label><select id="q-pos" name="position"><?php foreach ($posicoes as $k => $v): ?><option value="<?= $k ?>"><?= pub_e($v) ?></option><?php endforeach; ?></select></div>
                <div class="lp-field"><label for="q-esc">Escalão</label><select id="q-esc" name="escalao"><?= escalao_options($team, 'seniores') ?></select></div>
                <div class="lp-field"><label for="q-birth">Data de nascimento</label><input id="q-birth" name="birth_date" type="date" required max="<?= date('Y-m-d', strtotime('-8 years')) ?>"></div>
                <div class="lp-field"><label for="q-num">N.º da camisola (opcional)</label><input id="q-num" name="jersey_number" type="number" min="0" max="99"></div>
                <div class="lp-field"><label for="q-msg">Mensagem ao treinador (opcional)</label><textarea id="q-msg" name="message" maxlength="500" style="min-height:80px;"></textarea></div>
                <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-paper-plane"></i> Enviar pedido</button>
            </form>
            <?php endif; ?>
        </div></section>

        <section class="lp-card"><div class="lp-pad">
            <h2 class="lp-sub"><i class="fa-solid fa-user-gear"></i> Os meus dados</h2>
            <form method="post" class="lp-form">
                <input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>"><input type="hidden" name="action" value="profile">
                <div class="lp-field"><label>Utilizador</label><input type="text" value="<?= pub_e($conta['username']) ?>" disabled></div>
                <div class="lp-field"><label for="p-name">Nome</label><input id="p-name" name="name" type="text" maxlength="100" required autocomplete="name" value="<?= pub_e($conta['name']) ?>"></div>
                <div class="lp-field"><label for="p-email">Email</label><input id="p-email" name="email" type="email" maxlength="100" required autocomplete="email" value="<?= pub_e($conta['email']) ?>"></div>
                <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
            </form>

            <h2 class="lp-sub" style="margin-top:28px;"><i class="fa-solid fa-key"></i> Palavra-passe</h2>
            <form method="post" class="lp-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>"><input type="hidden" name="action" value="password">
                <div class="lp-field"><label for="w-cur">Atual</label><input id="w-cur" name="current_password" type="password" required autocomplete="current-password"></div>
                <div class="lp-field"><label for="w-new">Nova (mín. 8 caracteres)</label><input id="w-new" name="new_password" type="password" minlength="8" required autocomplete="new-password"></div>
                <div class="lp-field"><label for="w-conf">Repita a nova</label><input id="w-conf" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
                <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-key"></i> Alterar palavra-passe</button>
            </form>
        </div></section>
    </div>
</div>

<?php require __DIR__ . '/includes/layout/public_bottom.php'; ?>
