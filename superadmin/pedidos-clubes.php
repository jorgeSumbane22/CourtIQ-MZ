<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Pedidos de clubes
 * Clubes que se inscreveram em registar-clube.php. Aprovar cria o clube e a conta de
 * administrador com os dados do pedido; recusar não cria nada.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require_once __DIR__ . '/../includes/clubs.php';

[$pdo, $user] = panel_boot_global();

$maxEquipas = (int) ($pdo->query("SELECT config_value FROM system_config WHERE config_key = 'max_teams'")->fetchColumn() ?: 0);

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['request_id'] ?? 0);

    try {
        if ($acao === 'auto') {
            // liga ou desliga a entrada imediata dos clubes que se inscrevem
            $novo = !empty($_POST['ligar']) ? '1' : '0';
            $pdo->prepare("INSERT INTO system_config (config_key, config_value, description) VALUES ('club_auto_approve', :v, 'Clubes ficam ativos ao inscrever-se (1) ou esperam aprovação (0)')
                           ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)")->execute(['v' => $novo]);
            panel_log_global($pdo, 'clubes_aprovacao_automatica', $novo === '1' ? 'ligada' : 'desligada');
            flash_set('success', $novo === '1' ? 'Os clubes passam a ficar ativos logo ao inscrever-se.' : 'Cada clube passa a esperar pela sua aprovação.');
            panel_redirect('pedidos-clubes.php');
        }

        $stmt = $pdo->prepare("SELECT * FROM club_requests WHERE id = :id AND status = 'pending'");
        $stmt->execute(['id' => $id]);
        $pedido = $stmt->fetch();
        if (!$pedido) throw new RuntimeException('Pedido não encontrado ou já decidido.');

        if ($acao === 'approve') {
            $existentes = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
            if ($maxEquipas > 0 && $existentes >= $maxEquipas) {
                throw new RuntimeException('Limite de ' . $maxEquipas . ' equipas atingido. Aumente o limite em Configurações antes de aprovar mais clubes.');
            }
            team_name_check((string) $pedido['club_name']);
            $stmt = $pdo->prepare('SELECT id FROM teams WHERE name = :n');
            $stmt->execute(['n' => $pedido['club_name']]);
            if ($stmt->fetch()) throw new RuntimeException('Entretanto foi criado um clube com esse nome. Recuse o pedido e peça outro nome.');
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
            $stmt->execute(['u' => $pedido['admin_username'], 'e' => $pedido['admin_email']]);
            if ($stmt->fetch()) throw new RuntimeException('Entretanto esse utilizador ou email passou a pertencer a outra conta. Recuse o pedido e peça outros dados.');

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO teams (name, city, stadium, founded_year, escaloes, logo_initials, status)
                 VALUES (:name, :city, :stadium, :founded_year, :escaloes, :initials, 'active')"
            )->execute([
                'name' => $pedido['club_name'], 'city' => $pedido['city'], 'stadium' => $pedido['stadium'], 'founded_year' => $pedido['founded_year'],
                'escaloes' => escaloes_from_post(explode(',', (string) $pedido['escaloes'])), 'initials' => mb_strtoupper(mb_substr((string) $pedido['club_name'], 0, 2)),
            ]);
            $teamId = (int) $pdo->lastInsertId();
            role_limit_check($pdo, 'admin', $teamId);
            $pdo->prepare(
                "INSERT INTO users (name, username, email, phone, password_hash, role, team_id, status, avatar_initials)
                 VALUES (:name, :username, :email, :phone, :hash, 'admin', :team_id, 'active', :initials)"
            )->execute([
                'name' => $pedido['admin_name'], 'username' => $pedido['admin_username'], 'email' => $pedido['admin_email'], 'phone' => $pedido['admin_phone'],
                'hash' => $pedido['password_hash'], 'team_id' => $teamId, 'initials' => mb_strtoupper(mb_substr((string) $pedido['admin_name'], 0, 2)),
            ]);
            $adminId = (int) $pdo->lastInsertId();
            // a palavra-passe já está na conta: deixa de ficar guardada no pedido
            $pdo->prepare("UPDATE club_requests SET status = 'approved', decided_by = :by, decided_at = :now, team_id = :team_id, password_hash = '' WHERE id = :id")
                ->execute(['by' => (int) $user['id'], 'now' => date('Y-m-d H:i:s'), 'team_id' => $teamId, 'id' => $id]);
            $pdo->commit();

            notify_user($pdo, $adminId, 'Bem-vindo ao CourtIQMZ',
                'O clube ' . $pedido['club_name'] . ' foi aprovado. Comece por completar os dados do clube em "Equipa" e por adicionar jogadores e treinadores.', 'success');
            panel_log_global($pdo, 'clube_aprovado', $pedido['club_name'] . ' · admin ' . $pedido['admin_username'], $teamId);
            flash_set('success', $pedido['club_name'] . ' foi aprovado. ' . $pedido['admin_name'] . ' já pode entrar com o utilizador ' . $pedido['admin_username'] . '.');

        } elseif ($acao === 'reject') {
            $nota = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
            $pdo->prepare("UPDATE club_requests SET status = 'rejected', decided_by = :by, decided_at = :now, decision_note = :note, password_hash = '' WHERE id = :id")
                ->execute(['by' => (int) $user['id'], 'now' => date('Y-m-d H:i:s'), 'note' => $nota ?: null, 'id' => $id]);
            panel_log_global($pdo, 'clube_recusado', (string) $pedido['club_name']);
            flash_set('success', 'Pedido de ' . $pedido['club_name'] . ' recusado. Não foi criado nenhum clube nem conta.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a ação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/pedidos-clubes: ' . $e->getMessage());
    }
    panel_redirect('pedidos-clubes.php');
}

$pedidos = $pdo->query(
    "SELECT r.*, d.name AS decided_name FROM club_requests r LEFT JOIN users d ON d.id = r.decided_by
     ORDER BY (r.status = 'pending') DESC, r.created_at DESC LIMIT 200"
)->fetchAll();
$pendentes  = array_values(array_filter($pedidos, fn($p) => $p['status'] === 'pending'));
$decididos  = array_values(array_filter($pedidos, fn($p) => $p['status'] !== 'pending'));
$existentes = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
$vagas      = $maxEquipas > 0 ? max(0, $maxEquipas - $existentes) : null;
$estados    = ['approved' => ['Aprovado', 'win'], 'rejected' => ['Recusado', 'loss']];
$listaEscaloes = fn(string $s) => implode(', ', array_map('escalao_label', array_filter(explode(',', $s))));

$pageTitle = 'Pedidos de clubes';
$activeNav = 'pedidos-clubes';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Pedidos de clubes', 'Clubes que pediram para aderir ao CourtIQMZ.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../registar-clube.php"><i class="fa-solid fa-globe"></i> Ver o formulário</a>'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= count($pendentes) ?></b><span>Por decidir</span></div>
        <div class="lp-card lp-kpi"><b><?= $existentes ?><?= $maxEquipas > 0 ? ' / ' . $maxEquipas : '' ?></b><span>Clubes no sistema</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $vagas === 0 ? 'down' : 'up' ?>"><b><?= $vagas === null ? '∞' : $vagas ?></b><span>Vagas disponíveis</span></div>
        <div class="lp-card lp-kpi"><b><?= count(array_filter($decididos, fn($p) => $p['status'] === 'approved')) ?></b><span>Aprovados</span></div>
    </section>

    <?php if ($vagas === 0 && $pendentes): ?>
    <div class="lp-notice lp-notice--error" role="status"><i class="fa-solid fa-triangle-exclamation"></i>
        <div><b>Não há vagas.</b> O limite de <?= $maxEquipas ?> clubes está atingido: para aprovar pedidos, aumente "Máximo de equipas" em <a href="config-gerais.php" style="color:var(--accent-text);">Configurações</a>.</div></div>
    <?php endif; ?>

    <?php $auto = club_auto_approve($pdo); ?>
    <section class="lp-card lp-pad" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
        <div style="flex:1 1 320px;">
            <b style="color:var(--text);"><i class="fa-solid fa-bolt" style="color:var(--accent);margin-right:8px;"></i>Entrada dos clubes: <?= $auto ? 'imediata' : 'com aprovação' ?></b>
            <p class="pn-help" style="margin:4px 0 0;"><?= $auto
                ? 'Um clube que se inscreve fica ativo logo e quem o inscreveu entra como administrador. Enquanto não houver vagas, os pedidos ficam aqui à espera.'
                : 'Cada clube que se inscreve fica aqui à espera da sua decisão; até lá não existe clube nem conta.' ?></p>
        </div>
        <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="auto"><input type="hidden" name="ligar" value="<?= $auto ? '0' : '1' ?>">
            <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-<?= $auto ? 'user-check' : 'bolt' ?>"></i> <?= $auto ? 'Passar a exigir aprovação' : 'Passar a entrada imediata' ?></button></form>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-building-circle-check"></i>Por decidir</h2><small><?= count($pendentes) ?></small></div>
        <div class="pn-list">
            <?php foreach ($pendentes as $p): ?>
            <div style="flex-wrap:wrap;">
                <span class="lp-avatar lp-avatar--sm" style="border-radius:8px;"><?= pub_initial($p['club_name']) ?></span>
                <div class="grow">
                    <b><?= pub_e($p['club_name']) ?></b>
                    <small><?= pub_e($p['city'] ?: '—') ?><?= $p['stadium'] ? ' · ' . pub_e($p['stadium']) : '' ?><?= $p['founded_year'] ? ' · fundado em ' . (int) $p['founded_year'] : '' ?> · <?= pub_e($listaEscaloes((string) $p['escaloes'])) ?></small>
                    <small>Responsável: <?= pub_e($p['admin_name']) ?> · <?= pub_e($p['admin_username']) ?> · <?= pub_e($p['admin_email']) ?><?= $p['admin_phone'] ? ' · ' . pub_e($p['admin_phone']) : '' ?></small>
                    <small>Pedido em <?= pub_e(pub_date($p['created_at'], 'd/m/Y H:i')) ?></small>
                    <?php if ($p['message']): ?><small style="white-space:normal;color:var(--text-secondary);margin-top:4px;">"<?= pub_e($p['message']) ?>"</small><?php endif; ?>
                </div>
                <span class="pn-row-actions">
                    <form method="post" data-confirm="Aprovar <?= pub_e($p['club_name']) ?>? É criado o clube e a conta de administrador <?= pub_e($p['admin_username']) ?>.">
                        <?= panel_csrf() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Aprovar</button>
                    </form>
                    <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal="#recusarClube" data-reset data-values="<?= pn_values(['request_id' => (int) $p['id'], 'quem' => $p['club_name']]) ?>"><i class="fa-solid fa-xmark"></i> Recusar</button>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$pendentes): ?><div class="pn-empty"><i class="fa-solid fa-inbox"></i>Não há pedidos à espera.</div><?php endif; ?>
    </section>

    <?php if ($decididos): ?>
    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-clock-rotate-left"></i>Já decididos</h2></div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Clube</th><th scope="col">Responsável</th><th scope="col">Decisão</th><th scope="col">Por</th></tr></thead>
            <tbody>
                <?php foreach ($decididos as $p): [$rotulo, $tipo] = $estados[$p['status']]; ?>
                <tr>
                    <td><?= pub_e($p['club_name']) ?><small><?= pub_e($p['city'] ?: '—') ?></small></td>
                    <td><?= pub_e($p['admin_name']) ?><small><?= pub_e($p['admin_username']) ?> · <?= pub_e($p['admin_email']) ?></small></td>
                    <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= $rotulo ?></span><?php if ($p['decision_note']): ?><small style="white-space:normal;"><?= pub_e($p['decision_note']) ?></small><?php endif; ?></td>
                    <td><?= pub_e($p['decided_name'] ?: '—') ?><small><?= $p['decided_at'] ? pub_e(pub_date($p['decided_at'], 'd/m/Y H:i')) : '' ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
</div>

<dialog class="pn-modal" id="recusarClube">
    <form method="post">
        <div class="pn-modal-head"><h2>Recusar pedido</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="request_id">
            <p style="color:var(--text-secondary);">O clube <b data-text="quem" style="color:var(--text);"></b> não é criado, nem a conta do responsável.</p>
            <div class="lp-field"><label for="r-note">Motivo (fica registado)</label><input id="r-note" name="note" type="text" maxlength="255"></div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--danger lp-btn--sm"><i class="fa-solid fa-xmark"></i> Recusar</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
