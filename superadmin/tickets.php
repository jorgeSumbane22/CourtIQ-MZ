<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Tickets
 * Pedidos de suporte enviados pelas equipas (a partir de Configurações, em cada painel).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

$estados     = ['open' => ['Aberto', 'loss'], 'answered' => ['Respondido', 'gold'], 'closed' => ['Fechado', 'win']];
$prioridades = ['low' => ['Baixa', ''], 'normal' => ['Normal', 'info'], 'high' => ['Alta', 'loss']];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['ticket_id'] ?? 0);

    try {
        $stmt = $pdo->prepare('SELECT * FROM support_tickets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $ticket = $stmt->fetch();
        if (!$ticket) throw new RuntimeException('Pedido não encontrado.');

        if ($acao === 'reply') {
            $mensagem = trim((string) ($_POST['message'] ?? ''));
            if ($mensagem === '') throw new RuntimeException('Escreva a resposta.');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (:t, :u, :m)')
                ->execute(['t' => $id, 'u' => (int) $user['id'], 'm' => $mensagem]);
            $fechar = !empty($_POST['close']);
            $pdo->prepare('UPDATE support_tickets SET status = :s WHERE id = :id')->execute(['s' => $fechar ? 'closed' : 'answered', 'id' => $id]);
            // avisa quem abriu o pedido
            $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (:u, :t, :m, :type)')->execute([
                'u' => (int) $ticket['user_id'], 't' => 'Resposta ao seu pedido de suporte', 'm' => mb_substr($ticket['subject'] . ': ' . $mensagem, 0, 500), 'type' => 'info',
            ]);
            $pdo->commit();
            panel_log_global($pdo, 'ticket_respondido', '#' . $id . ' ' . $ticket['subject'], $ticket['team_id'] !== null ? (int) $ticket['team_id'] : null);
            flash_set('success', 'Resposta enviada' . ($fechar ? ' e pedido fechado.' : '.'));

        } elseif ($acao === 'status') {
            $estado = isset($estados[$_POST['status'] ?? '']) ? (string) $_POST['status'] : 'open';
            $pdo->prepare('UPDATE support_tickets SET status = :s WHERE id = :id')->execute(['s' => $estado, 'id' => $id]);
            flash_set('success', 'Estado do pedido alterado.');

        } elseif ($acao === 'delete') {
            $pdo->prepare('DELETE FROM support_tickets WHERE id = :id')->execute(['id' => $id]);
            flash_set('success', 'Pedido apagado.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a ação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/tickets: ' . $e->getMessage());
    }
    panel_redirect('tickets.php');
}

$tickets = $pdo->query(
    "SELECT s.*, u.name AS user_name, u.username, u.role, t.name AS team_name
     FROM support_tickets s JOIN users u ON u.id = s.user_id LEFT JOIN teams t ON t.id = s.team_id
     ORDER BY FIELD(s.status, 'open', 'answered', 'closed'), FIELD(s.priority, 'high', 'normal', 'low'), s.created_at DESC"
)->fetchAll();

$respostas = [];
foreach ($pdo->query('SELECT r.*, u.name AS user_name, u.role FROM ticket_replies r LEFT JOIN users u ON u.id = r.user_id ORDER BY r.created_at, r.id')->fetchAll() as $r) {
    $respostas[(int) $r['ticket_id']][] = $r;
}
$porEstado = array_count_values(array_column($tickets, 'status'));

$pageTitle = 'Tickets';
$activeNav = 'tickets';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Pedidos de suporte', (int) ($porEstado['open'] ?? 0) . ' abertos · ' . (int) ($porEstado['answered'] ?? 0) . ' respondidos · ' . (int) ($porEstado['closed'] ?? 0) . ' fechados.'); ?>

    <section class="lp-stack" data-filter>
        <div class="lp-toolbar">
            <div class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Procurar pedido, pessoa ou equipa..." aria-label="Procurar pedidos"></div>
            <div class="lp-chips" role="group" aria-label="Filtrar por estado">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos <small><?= count($tickets) ?></small></button>
                <?php foreach ($estados as $chave => [$rotulo]): ?>
                <button type="button" class="lp-chip" data-filter-value="<?= $chave ?>" aria-pressed="false"><?= $rotulo ?> <small><?= (int) ($porEstado[$chave] ?? 0) ?></small></button>
                <?php endforeach; ?>
            </div>
        </div>

        <?php foreach ($tickets as $t):
            [$estado, $tipo] = $estados[$t['status']];
            [$prio, $prioTipo] = $prioridades[$t['priority']] ?? $prioridades['normal'];
            $tid = (int) $t['id'];
        ?>
        <article class="lp-card" data-filter-item data-tags="<?= pub_e($t['status']) ?>" data-name="<?= pub_e(mb_strtolower($t['subject'] . ' ' . $t['user_name'] . ' ' . $t['team_name'] . ' ' . $t['message'])) ?>">
            <div class="lp-pad">
                <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;">
                    <div style="min-width:0;flex:1;">
                        <div class="lp-chips" style="margin-bottom:10px;">
                            <span class="lp-badge lp-badge--<?= $tipo ?>"><?= $estado ?></span>
                            <span class="lp-badge lp-badge--<?= $prioTipo ?>">Prioridade <?= mb_strtolower($prio) ?></span>
                            <span class="lp-badge">#<?= $tid ?></span>
                        </div>
                        <h2 style="font-size:19px;font-weight:700;color:var(--text);line-height:1.3;"><?= pub_e($t['subject']) ?></h2>
                        <div class="lp-game-meta" style="margin:8px 0 12px;">
                            <span><i class="fa-solid fa-user"></i><?= pub_e($t['user_name']) ?> (<?= pub_e($t['role']) ?>)</span>
                            <span><i class="fa-solid fa-shield-halved"></i><?= pub_e($t['team_name'] ?: 'Sem equipa') ?></span>
                            <span><i class="fa-solid fa-clock"></i><?= pub_e(pub_date($t['created_at'], 'd/m/Y H:i')) ?></span>
                        </div>
                        <p style="font-size:15px;line-height:1.65;color:var(--text-secondary);"><?= nl2br(pub_e($t['message'])) ?></p>
                    </div>
                    <span class="pn-row-actions">
                        <?php if ($t['status'] !== 'closed'): ?>
                        <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="status"><input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="status" value="closed">
                            <button type="submit" class="pn-icon-btn" title="Fechar pedido" aria-label="Fechar pedido"><i class="fa-solid fa-check"></i></button></form>
                        <?php else: ?>
                        <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="status"><input type="hidden" name="ticket_id" value="<?= $tid ?>"><input type="hidden" name="status" value="open">
                            <button type="submit" class="pn-icon-btn" title="Reabrir pedido" aria-label="Reabrir pedido"><i class="fa-solid fa-rotate-left"></i></button></form>
                        <?php endif; ?>
                        <form method="post" data-confirm="Apagar o pedido #<?= $tid ?> e as respostas?"><?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="ticket_id" value="<?= $tid ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Apagar" aria-label="Apagar pedido"><i class="fa-solid fa-trash"></i></button></form>
                    </span>
                </div>

                <?php foreach ($respostas[$tid] ?? [] as $r): $suporte = $r['role'] === 'superadmin'; ?>
                <div style="margin-top:14px;padding:12px 16px;border-radius:12px;background:var(--<?= $suporte ? 'glow' : 'surface2' ?>);border-left:3px solid var(--<?= $suporte ? 'accent' : 'border' ?>);">
                    <small style="display:block;font-size:12px;color:var(--muted);margin-bottom:4px;"><b style="color:var(--text);"><?= pub_e($r['user_name'] ?: 'Conta apagada') ?></b><?= $suporte ? ' · Suporte' : '' ?> · <?= pub_e(pub_date($r['created_at'], 'd/m/Y H:i')) ?></small>
                    <span style="font-size:14px;line-height:1.6;color:var(--text-secondary);"><?= nl2br(pub_e($r['message'])) ?></span>
                </div>
                <?php endforeach; ?>

                <?php if ($t['status'] !== 'closed'): ?>
                <form method="post" class="lp-form" style="margin-top:16px;">
                    <?= panel_csrf() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="<?= $tid ?>">
                    <div class="lp-field"><label for="r-<?= $tid ?>">Responder</label><textarea id="r-<?= $tid ?>" name="message" required style="min-height:80px;"></textarea></div>
                    <div class="pn-form-actions" style="justify-content:space-between;align-items:center;">
                        <label class="lp-check"><input type="checkbox" name="close" value="1"><span>Fechar o pedido depois de responder</span></label>
                        <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-reply"></i> Enviar resposta</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>

        <p class="lp-empty" data-filter-empty <?= $tickets ? 'hidden' : '' ?>>
            <?= $tickets ? 'Nenhum pedido corresponde ao filtro.' : 'Não há pedidos de suporte. As equipas enviam-nos a partir de Configurações, no seu painel.' ?>
        </p>
    </section>
</div>

<?php require panel_layout('bottom'); ?>
