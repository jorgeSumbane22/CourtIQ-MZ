<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (treinador e admin): Pedidos de inscrição
 * Quem cria conta em registar.php e diz ser jogador fica aqui à espera.
 * Aceitar: a conta passa a jogador e ganha ficha no plantel. Recusar: continua adepto.
 */

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$posicoes = ['PG', 'SG', 'SF', 'PF', 'C'];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['request_id'] ?? 0);

    try {
        // o pedido tem de ser deste clube e estar por decidir
        $stmt = $pdo->prepare(
            "SELECT r.*, u.name, u.username, u.role, u.team_id AS user_team, u.status AS user_status
             FROM player_requests r JOIN users u ON u.id = r.user_id
             WHERE r.id = :id AND r.team_id = :team_id AND r.status = 'pending'"
        );
        $stmt->execute(['id' => $id, 'team_id' => $teamId]);
        $pedido = $stmt->fetch();
        if (!$pedido) throw new RuntimeException('Pedido não encontrado ou já decidido.');
        // um treinador só decide pedidos para os escalões que treina
        if (!escalao_allowed($team, (string) $pedido['escalao'])) throw new RuntimeException('Esse pedido é para um escalão que não treina.');

        if ($acao === 'approve') {
            $numero  = (int) ($_POST['jersey_number'] ?? -1);
            $posicao = (string) ($_POST['position'] ?? '');
            if ($numero < 0 || $numero > 99) throw new RuntimeException('O número da camisola deve estar entre 0 e 99.');
            if (!in_array($posicao, $posicoes, true)) throw new RuntimeException('Posição inválida.');
            $escalao = escalao_from_post($team, $_POST['escalao'] ?? $pedido['escalao']);
            if ($pedido['role'] !== 'adepto' || (int) $pedido['user_team'] !== $teamId || $pedido['user_status'] !== 'active') {
                throw new RuntimeException('Esta conta já não pode ser aceite como jogador deste clube.');
            }
            $stmt = $pdo->prepare('SELECT id FROM players WHERE team_id = :team_id AND jersey_number = :n');
            $stmt->execute(['team_id' => $teamId, 'n' => $numero]);
            if ($stmt->fetch()) throw new RuntimeException('Já existe um jogador com o número ' . $numero . '. Escolha outro.');

            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO players (team_id, user_id, name, jersey_number, position, escalao, birth_date, status)
                 VALUES (:team_id, :user_id, :name, :n, :position, :escalao, :birth_date, 'active')"
            )->execute(['team_id' => $teamId, 'user_id' => (int) $pedido['user_id'], 'name' => $pedido['name'], 'n' => $numero, 'position' => $posicao, 'escalao' => $escalao, 'birth_date' => $pedido['birth_date']]);
            $pdo->prepare("UPDATE users SET role = 'jogador' WHERE id = :id AND role = 'adepto'")->execute(['id' => (int) $pedido['user_id']]);
            $pdo->prepare("UPDATE player_requests SET status = 'approved', decided_by = :by, decided_at = :now WHERE id = :id")
                ->execute(['by' => (int) $user['id'], 'now' => date('Y-m-d H:i:s'), 'id' => $id]);
            $pdo->commit();

            notify_user($pdo, (int) $pedido['user_id'], 'Pedido aceite: já é jogador de ' . $team['name'],
                'O seu pedido foi aceite. Ficou com o número ' . $numero . '. Na próxima vez que abrir o site entra no painel do jogador.', 'success');
            panel_log($pdo, $teamId, 'pedido_jogador_aceite', $pedido['username'] . ' → #' . $numero);
            flash_set('success', $pedido['name'] . ' foi aceite e já está no plantel com o número ' . $numero . '.');

        } elseif ($acao === 'reject') {
            $nota = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
            $pdo->prepare("UPDATE player_requests SET status = 'rejected', decided_by = :by, decided_at = :now, decision_note = :note WHERE id = :id")
                ->execute(['by' => (int) $user['id'], 'now' => date('Y-m-d H:i:s'), 'note' => $nota ?: null, 'id' => $id]);
            notify_user($pdo, (int) $pedido['user_id'], 'Pedido de jogador recusado',
                'O clube ' . $team['name'] . ' não confirmou a sua inscrição como jogador.' . ($nota !== '' ? ' Motivo: ' . $nota : '') . ' A sua conta continua ativa como adepto.', 'warning');
            panel_log($pdo, $teamId, 'pedido_jogador_recusado', $pedido['username']);
            flash_set('success', 'Pedido de ' . $pedido['name'] . ' recusado. A conta continua como adepto.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a ação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] pedidos: ' . $e->getMessage());
    }
    panel_redirect('pedidos.php');
}

$stmt = $pdo->prepare(
    'SELECT r.*, u.name, u.username, u.email, u.role, d.name AS decided_name
     FROM player_requests r JOIN users u ON u.id = r.user_id LEFT JOIN users d ON d.id = r.decided_by
     WHERE r.team_id = :team_id ORDER BY (r.status = \'pending\') DESC, r.created_at DESC LIMIT 200'
);
$stmt->execute(['team_id' => $teamId]);
// o treinador vê os pedidos para os seus escalões; o administrador vê todos
$pedidos   = array_values(array_filter($stmt->fetchAll(), fn($p) => escalao_allowed($team, (string) $p['escalao'])));
$pendentes = array_values(array_filter($pedidos, fn($p) => $p['status'] === 'pending'));
$decididos = array_values(array_filter($pedidos, fn($p) => $p['status'] !== 'pending'));

$stmt = $pdo->prepare('SELECT jersey_number FROM players WHERE team_id = :team_id AND jersey_number IS NOT NULL');
$stmt->execute(['team_id' => $teamId]);
$ocupados = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
/** Primeiro número livre a partir do preferido. */
$sugerir = function (?int $preferido) use ($ocupados): int {
    if ($preferido !== null && !in_array($preferido, $ocupados, true)) return $preferido;
    for ($n = 4; $n <= 99; $n++) if (!in_array($n, $ocupados, true)) return $n;
    return 0;
};

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE team_id = :team_id AND role = 'adepto' AND status = 'active'");
$stmt->execute(['team_id' => $teamId]);
$adeptos = (int) $stmt->fetchColumn();

$podeDecidir = can('requests.decide');
$estados = ['approved' => ['Aceite', 'win'], 'rejected' => ['Recusado', 'loss']];

$pageTitle = 'Pedidos de inscrição';
$activeNav = 'pedidos';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Pedidos de inscrição', 'Pessoas que criaram conta e dizem ser jogadores de ' . $team['name'] . '.'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= count($pendentes) ?></b><span>Por decidir</span></div>
        <div class="lp-card lp-kpi lp-kpi--up"><b><?= count(array_filter($decididos, fn($p) => $p['status'] === 'approved')) ?></b><span>Aceites</span></div>
        <div class="lp-card lp-kpi lp-kpi--down"><b><?= count(array_filter($decididos, fn($p) => $p['status'] === 'rejected')) ?></b><span>Recusados</span></div>
        <div class="lp-card lp-kpi"><b><?= $adeptos ?></b><span>Adeptos a seguir o clube</span></div>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-user-clock"></i>Por decidir</h2><small><?= count($pendentes) ?></small></div>
        <div class="pn-list">
            <?php foreach ($pendentes as $p): ?>
            <div style="flex-wrap:wrap;">
                <span class="lp-avatar lp-avatar--sm"><?= pub_initial($p['name']) ?></span>
                <div class="grow">
                    <b><?= pub_e($p['name']) ?></b>
                    <small><?= pub_e($p['username']) ?> · <?= pub_e($p['email']) ?> · pedido em <?= pub_e(pub_date($p['created_at'], 'd/m/Y H:i')) ?></small>
                    <small><?= pub_e(escalao_label($p['escalao'])) ?> · <?= pub_e(pub_position($p['position'])) ?><?= $p['birth_date'] ? ' · nascido em ' . pub_e(pub_date($p['birth_date'])) : '' ?><?= $p['jersey_number'] !== null ? ' · prefere o n.º ' . (int) $p['jersey_number'] : '' ?></small>
                    <?php if ($p['message']): ?><small style="white-space:normal;color:var(--text-secondary);margin-top:4px;">"<?= pub_e($p['message']) ?>"</small><?php endif; ?>
                </div>
                <?php if ($podeDecidir): ?>
                <span class="pn-row-actions">
                    <button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#aceitarPedido" data-values="<?= pn_values([
                        'request_id' => (int) $p['id'], 'quem' => $p['name'], 'position' => $p['position'], 'escalao' => $p['escalao'],
                        'jersey_number' => $sugerir($p['jersey_number'] !== null ? (int) $p['jersey_number'] : null),
                    ]) ?>"><i class="fa-solid fa-check"></i> Aceitar</button>
                    <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal="#recusarPedido" data-reset data-values="<?= pn_values(['request_id' => (int) $p['id'], 'quem' => $p['name']]) ?>"><i class="fa-solid fa-xmark"></i> Recusar</button>
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$pendentes): ?><div class="pn-empty"><i class="fa-solid fa-inbox"></i>Não há pedidos à espera.</div><?php endif; ?>
    </section>

    <?php if ($decididos): ?>
    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-clock-rotate-left"></i>Já decididos</h2></div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Pessoa</th><th scope="col">Pedido</th><th scope="col">Decisão</th><th scope="col">Por</th></tr></thead>
            <tbody>
                <?php foreach ($decididos as $p): [$rotulo, $tipo] = $estados[$p['status']]; ?>
                <tr>
                    <td><?= pub_e($p['name']) ?><small><?= pub_e($p['username']) ?></small></td>
                    <td><?= pub_e(pub_date($p['created_at'])) ?><small><?= pub_e(escalao_label($p['escalao'])) ?> · <?= pub_e(pub_position($p['position'])) ?></small></td>
                    <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= $rotulo ?></span><?php if ($p['decision_note']): ?><small style="white-space:normal;"><?= pub_e($p['decision_note']) ?></small><?php endif; ?></td>
                    <td><?= pub_e($p['decided_name'] ?: '—') ?><small><?= $p['decided_at'] ? pub_e(pub_date($p['decided_at'], 'd/m/Y H:i')) : '' ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
</div>

<dialog class="pn-modal" id="aceitarPedido">
    <form method="post">
        <div class="pn-modal-head"><h2>Aceitar no plantel</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="request_id">
            <p style="color:var(--text-secondary);"><b data-text="quem" style="color:var(--text);"></b> passa a ter conta de jogador e uma ficha no plantel.</p>
            <div class="pn-form-grid">
                <div class="lp-field"><label for="a-num">Número da camisola</label><input id="a-num" name="jersey_number" type="number" min="0" max="99" required></div>
                <div class="lp-field"><label for="a-pos">Posição</label>
                    <select id="a-pos" name="position" required><?php foreach ($posicoes as $pos): ?><option value="<?= $pos ?>"><?= pub_e(pub_position($pos)) ?> (<?= $pos ?>)</option><?php endforeach; ?></select></div>
                <div class="lp-field full"><label for="a-esc">Escalão</label><select id="a-esc" name="escalao" required><?= escalao_options($team, 'seniores') ?></select></div>
            </div>
            <p class="pn-help">Os restantes dados (altura, peso, fotografia) editam-se depois em Jogadores.</p>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Aceitar jogador</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="recusarPedido">
    <form method="post">
        <div class="pn-modal-head"><h2>Recusar pedido</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="request_id">
            <p style="color:var(--text-secondary);">A conta de <b data-text="quem" style="color:var(--text);"></b> continua ativa como adepto; só não entra no plantel.</p>
            <div class="lp-field"><label for="r-note">Motivo (opcional, a pessoa vê-o)</label><input id="r-note" name="note" type="text" maxlength="255" placeholder="Ex.: não faz parte do plantel desta época"></div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--danger lp-btn--sm"><i class="fa-solid fa-xmark"></i> Recusar</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
