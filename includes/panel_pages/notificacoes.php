<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Notificações
 *   - enviar notificações a membros da própria equipa
 *   - ler as notificações recebidas
 *   - tratar as mensagens enviadas pelo formulário de contacto do site público
 */


[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$userId = (int) $user['id'];

$tipos = ['info' => 'Informação', 'success' => 'Sucesso', 'warning' => 'Aviso', 'error' => 'Urgente'];

if (panel_is_post()) {
    $acao   = (string) ($_POST['action'] ?? '');
    $voltar = 'notificacoes.php';

    try {
        if ($acao === 'send') {
            if (panel_name() === 'jogador') throw new RuntimeException('Os jogadores não enviam notificações à equipa.');
            $titulo   = trim((string) ($_POST['title'] ?? ''));
            $mensagem = trim((string) ($_POST['content'] ?? ''));
            $tipo     = isset($tipos[$_POST['type'] ?? '']) ? (string) $_POST['type'] : 'info';
            $destino  = (string) ($_POST['target'] ?? 'all');
            if ($titulo === '' || mb_strlen($titulo) > 200) throw new RuntimeException('Indique o título.');
            if ($mensagem === '') throw new RuntimeException('Escreva a mensagem.');

            // destinatários: sempre e só contas ativas desta equipa
            $sql    = "SELECT id FROM users WHERE team_id = :team_id AND status = 'active'";
            $params = ['team_id' => $teamId];
            if (in_array($destino, ['jogador', 'treinador', 'admin', 'adepto'], true)) {
                $sql .= ' AND role = :role';
                $params['role'] = $destino;
            } elseif ($destino === 'all') {
                $sql .= " AND role <> 'adepto'";   // "toda a equipa" é o clube por dentro; os adeptos têm opção própria
            } elseif (ctype_digit($destino)) {
                $sql .= ' AND id = :id';
                $params['id'] = (int) $destino;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) throw new RuntimeException('Não há destinatários para essa escolha.');

            $insert = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, :type)');
            foreach ($ids as $id) {
                $insert->execute(['user_id' => (int) $id, 'title' => $titulo, 'message' => $mensagem, 'type' => $tipo]);
            }
            panel_log($pdo, $teamId, 'notificacao_enviada', $titulo . ' (' . count($ids) . ' destinatários)');
            flash_set('success', 'Notificação enviada a ' . count($ids) . ' pessoa(s).');
            $voltar .= '#enviar';

        } elseif ($acao === 'read') {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['id'] ?? 0), 'user_id' => $userId]);

        } elseif ($acao === 'read_all') {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id')->execute(['user_id' => $userId]);
            flash_set('success', 'Todas as notificações foram marcadas como lidas.');

        } elseif ($acao === 'delete') {
            $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user_id')
                ->execute(['id' => (int) ($_POST['id'] ?? 0), 'user_id' => $userId]);

        } elseif ($acao === 'message_status' || $acao === 'message_delete') {
            if (panel_name() !== 'admin') throw new RuntimeException('Só a administração trata as mensagens do site.');
            $id = (int) ($_POST['id'] ?? 0);
            if (!team_owns('contact_messages', $id)) throw new RuntimeException('Mensagem não encontrada.');
            if ($acao === 'message_delete') {
                $pdo->prepare('DELETE FROM contact_messages WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
                flash_set('success', 'Mensagem removida.');
            } else {
                $estado = in_array($_POST['status'] ?? '', ['unread', 'read', 'replied'], true) ? (string) $_POST['status'] : 'read';
                $pdo->prepare('UPDATE contact_messages SET status = :s WHERE id = :id AND team_id = :team_id')
                    ->execute(['s' => $estado, 'id' => $id, 'team_id' => $teamId]);
            }
            $voltar .= '#mensagens';
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a ação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/notificacoes: ' . $e->getMessage());
    }
    panel_redirect($voltar);
}

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 100');
$stmt->execute(['user_id' => $userId]);
$notificacoes = $stmt->fetchAll();
$porLer = count(array_filter($notificacoes, fn($n) => !$n['is_read']));

$stmt = $pdo->prepare('SELECT * FROM contact_messages WHERE team_id = :team_id ORDER BY created_at DESC, id DESC');
$stmt->execute(['team_id' => $teamId]);
$mensagens = $stmt->fetchAll();
// o jogador só lê: vê as suas notificações e os comunicados publicados pelo treinador
$podeEnviar  = panel_name() !== 'jogador';
$comunicados = [];
if (!$podeEnviar) {
    $stmt = $pdo->prepare(
        "SELECT c.*, u.name AS autor FROM comunicados c LEFT JOIN users u ON u.id = c.autor_id
         WHERE c.team_id = :team_id AND c.status = 'publicado'
         ORDER BY c.fixado DESC, COALESCE(c.data_publicacao, c.created_at) DESC LIMIT 50"
    );
    $stmt->execute(['team_id' => $teamId]);
    $comunicados = $stmt->fetchAll();
}

// as mensagens do formulário de contacto são tratadas pela administração
$verMensagens = panel_name() === 'admin';
if (!$verMensagens) {
    $mensagens = [];
}
$mensagensNovas = count(array_filter($mensagens, fn($m) => $m['status'] === 'unread'));

$membros = array_filter(get_team_members($teamId), fn($m) => (int) $m['id'] !== $userId);
$icones  = ['info' => 'circle-info', 'success' => 'circle-check', 'warning' => 'triangle-exclamation', 'error' => 'circle-exclamation'];
$estadosMsg = ['unread' => ['Nova', 'info'], 'read' => ['Lida', ''], 'replied' => ['Respondida', 'win']];

$pageTitle = 'Notificações';
$activeNav = 'notificacoes';
require panel_layout('top');
?>

<div class="lp pn" data-tabs>
    <?php panel_head('Notificações', $porLer . ' por ler' . ($verMensagens ? ' · ' . $mensagensNovas . ' mensagens novas do site.' : '.')); ?>

    <div class="pn-tabs" role="tablist">
        <button type="button" class="pn-tab" data-tab="recebidas" role="tab">Recebidas <?= $porLer ? '(' . $porLer . ')' : '' ?></button>
        <?php if ($podeEnviar): ?>
        <button type="button" class="pn-tab" data-tab="enviar" role="tab">Enviar à equipa</button>
        <?php else: ?>
        <button type="button" class="pn-tab" data-tab="comunicados" role="tab">Comunicados da equipa (<?= count($comunicados) ?>)</button>
        <?php endif; ?>
        <?php if ($verMensagens): ?>
        <button type="button" class="pn-tab" data-tab="mensagens" role="tab">Mensagens do site <?= $mensagensNovas ? '(' . $mensagensNovas . ')' : '' ?></button>
        <?php endif; ?>
    </div>

    <section class="lp-card" data-tab-panel="recebidas" role="tabpanel">
        <div class="pn-section-title">
            <h2><i class="fa-solid fa-bell"></i>As minhas notificações</h2>
            <?php if ($porLer): ?>
            <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="read_all">
                <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-check-double"></i> Marcar todas como lidas</button></form>
            <?php endif; ?>
        </div>
        <div class="pn-list">
            <?php foreach ($notificacoes as $n): ?>
            <div style="<?= $n['is_read'] ? '' : 'background:var(--glow);' ?>">
                <span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-<?= $icones[$n['type']] ?? 'bell' ?>"></i></span>
                <div class="grow">
                    <b style="white-space:normal;"><?= pub_e($n['title']) ?></b>
                    <small style="white-space:normal;font-size:13px;"><?= pub_e($n['message']) ?></small>
                    <small><?= pub_e(pub_date($n['created_at'], 'd/m/Y H:i')) ?></small>
                </div>
                <span class="pn-row-actions">
                    <?php if (!$n['is_read']): ?>
                    <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="pn-icon-btn" title="Marcar como lida" aria-label="Marcar como lida"><i class="fa-solid fa-check"></i></button></form>
                    <?php endif; ?>
                    <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Apagar" aria-label="Apagar notificação"><i class="fa-solid fa-trash"></i></button></form>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$notificacoes): ?><div class="pn-empty"><i class="fa-solid fa-bell-slash"></i>Não tem notificações.</div><?php endif; ?>
    </section>

    <?php if (!$podeEnviar): ?>
    <section class="lp-stack" data-tab-panel="comunicados" role="tabpanel" hidden>
        <?php foreach ($comunicados as $c): ?>
        <article class="lp-card lp-pad" <?= $c['fixado'] ? 'style="border-color:var(--accent);"' : '' ?>>
            <div class="lp-chips" style="margin-bottom:10px;">
                <?php if ($c['fixado']): ?><span class="lp-badge lp-badge--accent"><i class="fa-solid fa-thumbtack"></i> Fixado</span><?php endif; ?>
                <span class="lp-badge lp-badge--info"><?= pub_e(ucfirst((string) $c['categoria'])) ?></span>
                <?php if (in_array($c['prioridade'], ['alta', 'urgente'], true)): ?><span class="lp-badge lp-badge--<?= $c['prioridade'] === 'urgente' ? 'loss' : 'gold' ?>"><?= pub_e(ucfirst($c['prioridade'])) ?></span><?php endif; ?>
            </div>
            <h2 style="font-size:19px;font-weight:700;color:var(--text);line-height:1.3;"><?= pub_e($c['titulo']) ?></h2>
            <p style="margin-top:8px;font-size:15px;line-height:1.65;color:var(--text-secondary);"><?= nl2br(pub_e($c['conteudo'])) ?></p>
            <div class="lp-game-meta" style="margin-top:12px;">
                <span><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($c['data_publicacao'] ?: $c['created_at'], 'd/m/Y H:i')) ?></span>
                <?php if (!empty($c['autor'])): ?><span><i class="fa-solid fa-pen-nib"></i><?= pub_e($c['autor']) ?></span><?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$comunicados): ?><div class="lp-card"><div class="pn-empty"><i class="fa-solid fa-bullhorn"></i>O treinador ainda não publicou comunicados.</div></div><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($podeEnviar): ?>
    <section class="lp-card" data-tab-panel="enviar" role="tabpanel" hidden>
        <div class="pn-section-title"><h2><i class="fa-solid fa-paper-plane"></i>Enviar notificação à equipa</h2><small>Só membros de <?= pub_e($team['name']) ?></small></div>
        <form method="post" class="lp-pad lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="send">
            <div class="pn-form-grid">
                <div class="lp-field"><label for="n-target">Destinatários</label>
                    <select id="n-target" name="target">
                        <option value="all">Toda a equipa</option>
                        <option value="jogador">Só jogadores</option>
                        <option value="treinador">Só treinadores</option>
                        <option value="adepto">Adeptos que seguem o clube</option>
                        <optgroup label="Uma pessoa">
                            <?php foreach ($membros as $m): ?><option value="<?= (int) $m['id'] ?>"><?= pub_e($m['name']) ?> (<?= pub_e($m['role_label']) ?>)</option><?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="lp-field"><label for="n-type">Tipo</label>
                    <select id="n-type" name="type"><?php foreach ($tipos as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field full"><label for="n-title">Título</label><input id="n-title" name="title" type="text" maxlength="200" required></div>
                <div class="lp-field full"><label for="n-content">Mensagem</label><textarea id="n-content" name="content" required></textarea></div>
            </div>
            <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-paper-plane"></i> Enviar</button></div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($verMensagens): ?>
    <section class="lp-card" data-tab-panel="mensagens" role="tabpanel" hidden>
        <div class="pn-section-title"><h2><i class="fa-solid fa-envelope"></i>Mensagens do formulário de contacto</h2><small><?= count($mensagens) ?> mensagens</small></div>
        <div class="pn-list">
            <?php foreach ($mensagens as $m): [$rotulo, $tipo] = $estadosMsg[$m['status']] ?? ['—', '']; ?>
            <div style="align-items:flex-start;">
                <div class="grow">
                    <b style="white-space:normal;"><?= pub_e($m['subject']) ?> <span class="lp-badge lp-badge--<?= $tipo ?>" style="margin-left:6px;"><?= $rotulo ?></span></b>
                    <small><?= pub_e($m['name']) ?> · <a href="mailto:<?= pub_e($m['email']) ?>?subject=<?= rawurlencode('Re: ' . $m['subject']) ?>" style="color:var(--accent-text);"><?= pub_e($m['email']) ?></a> · <?= pub_e(pub_date($m['created_at'], 'd/m/Y H:i')) ?></small>
                    <small style="white-space:normal;font-size:14px;color:var(--text-secondary);margin-top:6px;"><?= nl2br(pub_e($m['message'])) ?></small>
                </div>
                <span class="pn-row-actions">
                    <?php if ($m['status'] !== 'replied'): ?>
                    <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="message_status"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <input type="hidden" name="status" value="<?= $m['status'] === 'unread' ? 'read' : 'replied' ?>">
                        <button type="submit" class="pn-icon-btn" title="<?= $m['status'] === 'unread' ? 'Marcar como lida' : 'Marcar como respondida' ?>" aria-label="<?= $m['status'] === 'unread' ? 'Marcar como lida' : 'Marcar como respondida' ?>"><i class="fa-solid fa-<?= $m['status'] === 'unread' ? 'check' : 'reply' ?>"></i></button></form>
                    <?php endif; ?>
                    <form method="post" data-confirm="Apagar esta mensagem?"><?= panel_csrf() ?><input type="hidden" name="action" value="message_delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Apagar" aria-label="Apagar mensagem"><i class="fa-solid fa-trash"></i></button></form>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$mensagens): ?><div class="pn-empty"><i class="fa-solid fa-inbox"></i>Ainda ninguém escreveu pelo site.</div><?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php require panel_layout('bottom'); ?>
