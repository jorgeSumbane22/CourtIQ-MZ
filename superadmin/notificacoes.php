<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Notificações
 * Avisos do sistema para todas as equipas, uma equipa ou um papel, e as notificações recebidas.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();
$userId  = (int) $user['id'];
$equipas = panel_all_teams($pdo);
$tipos   = ['info' => 'Informação', 'success' => 'Sucesso', 'warning' => 'Aviso', 'error' => 'Urgente'];
$papeis  = ['admin' => 'Administradores', 'treinador' => 'Treinadores', 'jogador' => 'Jogadores'];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    try {
        if ($acao === 'send') {
            $titulo   = trim((string) ($_POST['title'] ?? ''));
            $mensagem = trim((string) ($_POST['content'] ?? ''));
            $tipo     = isset($tipos[$_POST['type'] ?? '']) ? (string) $_POST['type'] : 'info';
            $teamId   = (int) ($_POST['team_id'] ?? 0);
            $papel    = (string) ($_POST['role'] ?? '');
            if ($titulo === '' || mb_strlen($titulo) > 200) throw new RuntimeException('Indique o título.');
            if ($mensagem === '') throw new RuntimeException('Escreva a mensagem.');

            $sql    = "SELECT id FROM users WHERE status = 'active' AND id <> :me";
            $params = ['me' => $userId];
            if ($teamId > 0) {
                if (!isset($equipas[$teamId])) throw new RuntimeException('Equipa inválida.');
                $sql .= ' AND team_id = :team_id';
                $params['team_id'] = $teamId;
            }
            if (isset($papeis[$papel])) {
                $sql .= ' AND role = :role';
                $params['role'] = $papel;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) throw new RuntimeException('Não há destinatários para essa escolha.');

            $insert = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, :type)');
            foreach ($ids as $id) {
                $insert->execute(['user_id' => (int) $id, 'title' => $titulo, 'message' => $mensagem, 'type' => $tipo]);
            }
            panel_log_global($pdo, 'notificacao_global', $titulo . ' (' . count($ids) . ' destinatários)', $teamId ?: null);
            flash_set('success', 'Notificação enviada a ' . count($ids) . ' pessoa(s).');

        } elseif ($acao === 'read_all') {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :u')->execute(['u' => $userId]);
        } elseif ($acao === 'read') {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :u')->execute(['id' => (int) ($_POST['id'] ?? 0), 'u' => $userId]);
        } elseif ($acao === 'delete') {
            $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :u')->execute(['id' => (int) ($_POST['id'] ?? 0), 'u' => $userId]);
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a ação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/notificacoes: ' . $e->getMessage());
    }
    panel_redirect('notificacoes.php');
}

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT 100');
$stmt->execute(['u' => $userId]);
$notificacoes = $stmt->fetchAll();
$porLer = count(array_filter($notificacoes, fn($n) => !$n['is_read']));

$enviadas = $pdo->query(
    "SELECT a.created_at, a.details, t.name AS team_name FROM audit_logs a LEFT JOIN teams t ON t.id = a.team_id
     WHERE a.action = 'notificacao_global' ORDER BY a.created_at DESC LIMIT 10"
)->fetchAll();

$icones = ['info' => 'circle-info', 'success' => 'circle-check', 'warning' => 'triangle-exclamation', 'error' => 'circle-exclamation'];

$pageTitle = 'Notificações';
$activeNav = 'notificacoes';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Notificações', 'Avisos do sistema para as equipas.'); ?>

    <div class="pn-cols pn-cols--even">
        <form method="post" class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-bullhorn"></i>Enviar aviso</h2></div>
            <div class="lp-pad lp-form">
                <?= panel_csrf() ?><input type="hidden" name="action" value="send">
                <div class="pn-form-grid">
                    <div class="lp-field"><label for="n-team">Equipa</label>
                        <select id="n-team" name="team_id"><option value="0">Todas as equipas</option>
                            <?php foreach ($equipas as $e): ?><option value="<?= (int) $e['id'] ?>"><?= pub_e($e['name']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="lp-field"><label for="n-role">Papel</label>
                        <select id="n-role" name="role"><option value="">Todos os papéis</option>
                            <?php foreach ($papeis as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="lp-field full"><label for="n-type">Tipo</label>
                        <select id="n-type" name="type"><?php foreach ($tipos as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="lp-field full"><label for="n-title">Título</label><input id="n-title" name="title" type="text" maxlength="200" required></div>
                    <div class="lp-field full"><label for="n-content">Mensagem</label><textarea id="n-content" name="content" required></textarea></div>
                </div>
                <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-paper-plane"></i> Enviar</button></div>
            </div>
        </form>

        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card">
                <div class="pn-section-title">
                    <h2><i class="fa-solid fa-bell"></i>Recebidas <?= $porLer ? '(' . $porLer . ' por ler)' : '' ?></h2>
                    <?php if ($porLer): ?><form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="read_all">
                        <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-check-double"></i> Marcar todas</button></form><?php endif; ?>
                </div>
                <div class="pn-list">
                    <?php foreach (array_slice($notificacoes, 0, 12) as $n): ?>
                    <div style="<?= $n['is_read'] ? '' : 'background:var(--glow);' ?>">
                        <span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-<?= $icones[$n['type']] ?? 'bell' ?>"></i></span>
                        <div class="grow"><b style="white-space:normal;"><?= pub_e($n['title']) ?></b><small style="white-space:normal;"><?= pub_e($n['message']) ?></small><small><?= pub_e(pub_date($n['created_at'], 'd/m/Y H:i')) ?></small></div>
                        <span class="pn-row-actions">
                            <?php if (!$n['is_read']): ?><form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <button type="submit" class="pn-icon-btn" title="Marcar como lida" aria-label="Marcar como lida"><i class="fa-solid fa-check"></i></button></form><?php endif; ?>
                            <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Apagar" aria-label="Apagar notificação"><i class="fa-solid fa-trash"></i></button></form>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$notificacoes): ?><div class="pn-empty"><i class="fa-solid fa-bell-slash"></i>Não tem notificações.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-clock-rotate-left"></i>Avisos enviados</h2></div>
                <div class="pn-list">
                    <?php foreach ($enviadas as $a): ?>
                    <div><div class="grow"><b style="white-space:normal;"><?= pub_e($a['details']) ?></b><small><?= pub_e(pub_date($a['created_at'], 'd/m/Y H:i')) ?> · <?= pub_e($a['team_name'] ?: 'Todas as equipas') ?></small></div></div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$enviadas): ?><div class="pn-empty"><i class="fa-solid fa-paper-plane"></i>Ainda não enviou avisos.</div><?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
