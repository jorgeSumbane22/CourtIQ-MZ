<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Admin: Bilhetes
 * Vendas de bilhetes dos jogos da equipa e validação do código à entrada do pavilhão.
 * Um bilhete pertence à equipa através do jogo (ticket_sales.game_id -> games.team_id).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);

$estados = ['confirmed' => ['Válido', 'info'], 'used' => ['Utilizado', 'win'], 'cancelled' => ['Cancelado', 'loss']];

/** Um bilhete (por código) se o jogo for desta equipa. */
$porCodigo = function (string $codigo) use ($pdo, $teamId): ?array {
    $stmt = $pdo->prepare(
        'SELECT ts.*, g.opponent, g.game_date, g.game_time FROM ticket_sales ts JOIN games g ON g.id = ts.game_id
         WHERE ts.code = :code AND g.team_id = :team_id'
    );
    $stmt->execute(['code' => $codigo, 'team_id' => $teamId]);
    return $stmt->fetch() ?: null;
};

$verificado = null;
$codigoProcurado = '';

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');

    try {
        if ($acao === 'check') {
            // não redireciona: mostra logo o resultado da leitura do código
            $codigoProcurado = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $verificado = $codigoProcurado !== '' ? ($porCodigo($codigoProcurado) ?: false) : null;

        } elseif ($acao === 'post') {
            // lança (ou acerta) nas Finanças a receita de bilheteira de um jogo: um movimento por jogo
            $gameId = (int) ($_POST['game_id'] ?? 0);
            if (!team_owns('games', $gameId)) throw new RuntimeException('Jogo não encontrado.');
            $stmt = $pdo->prepare('SELECT opponent, game_date FROM games WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $gameId, 'team_id' => $teamId]);
            $jogo = $stmt->fetch();

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM ticket_sales WHERE game_id = :id AND status <> 'cancelled'");
            $stmt->execute(['id' => $gameId]);
            $total = round((float) $stmt->fetchColumn(), 2);

            $stmt = $pdo->prepare('SELECT id FROM finances WHERE ticket_game_id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $gameId, 'team_id' => $teamId]);
            $movimento = (int) $stmt->fetchColumn();

            if ($total <= 0) {
                if ($movimento) $pdo->prepare('DELETE FROM finances WHERE id = :id AND team_id = :team_id')->execute(['id' => $movimento, 'team_id' => $teamId]);
                flash_set('success', 'Este jogo já não tem vendas válidas: o movimento saiu das Finanças.');
            } elseif ($movimento) {
                $pdo->prepare('UPDATE finances SET amount = :amount WHERE id = :id AND team_id = :team_id')->execute(['amount' => $total, 'id' => $movimento, 'team_id' => $teamId]);
                flash_set('success', 'Movimento de bilheteira atualizado nas Finanças: ' . pn_money($total) . '.');
            } else {
                $pdo->prepare(
                    "INSERT INTO finances (team_id, description, amount, type, category, date, ticket_game_id)
                     VALUES (:team_id, :description, :amount, 'income', 'bilhetes', :date, :game_id)"
                )->execute([
                    'team_id' => $teamId, 'description' => 'Bilheteira — vs ' . $jogo['opponent'] . ' (' . pub_date($jogo['game_date']) . ')',
                    'amount' => $total, 'date' => min((string) $jogo['game_date'], date('Y-m-d')), 'game_id' => $gameId,
                ]);
                flash_set('success', 'Receita de bilheteira lançada nas Finanças: ' . pn_money($total) . '.');
            }
            panel_log($pdo, $teamId, 'bilheteira_lancada', 'vs ' . $jogo['opponent'] . ' · ' . number_format($total, 2, ',', ''));
            panel_redirect('bilhetes.php');

        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $novo = ['use' => 'used', 'cancel' => 'cancelled', 'restore' => 'confirmed'][$acao] ?? null;
            if ($novo === null) throw new RuntimeException('Ação desconhecida.');

            // a posse verifica-se pelo jogo: ticket_sales não tem team_id
            $stmt = $pdo->prepare('SELECT ts.status, ts.code FROM ticket_sales ts JOIN games g ON g.id = ts.game_id WHERE ts.id = :id AND g.team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            $atual = $stmt->fetch();
            if (!$atual) throw new RuntimeException('Bilhete não encontrado.');
            if ($acao === 'use' && $atual['status'] !== 'confirmed') {
                throw new RuntimeException($atual['status'] === 'used' ? 'Este bilhete já foi utilizado.' : 'Este bilhete está cancelado.');
            }
            $pdo->prepare('UPDATE ticket_sales SET status = :s WHERE id = :id')->execute(['s' => $novo, 'id' => $id]);
            panel_log($pdo, $teamId, 'bilhete_' . $novo, (string) $atual['code']);
            flash_set('success', ['used' => 'Entrada registada.', 'cancelled' => 'Bilhete cancelado.', 'confirmed' => 'Bilhete reposto como válido.'][$novo]);
            panel_redirect('bilhetes.php');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a ação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/bilhetes: ' . $e->getMessage());
        panel_redirect('bilhetes.php');
    }
}

$stmt = $pdo->prepare(
    'SELECT ts.*, g.opponent, g.game_date FROM ticket_sales ts JOIN games g ON g.id = ts.game_id
     WHERE g.team_id = :team_id ORDER BY ts.created_at DESC, ts.id DESC'
);
$stmt->execute(['team_id' => $teamId]);
$vendas = $stmt->fetchAll();

if (($_GET['exportar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bilhetes_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Codigo', 'Jogo', 'Data_jogo', 'Comprador', 'Email', 'Tipo', 'Quantidade', 'Total', 'Estado', 'Comprado_em'], ';');
    foreach ($vendas as $v) {
        fputcsv($out, [$v['code'], 'vs ' . $v['opponent'], $v['game_date'], $v['buyer_name'], $v['buyer_email'], $v['ticket_type'], $v['quantity'],
            number_format((float) $v['total'], 2, ',', ''), $estados[$v['status']][0] ?? $v['status'], $v['created_at']], ';');
    }
    exit;
}

$validas  = array_filter($vendas, fn($v) => $v['status'] !== 'cancelled');
$receita  = array_sum(array_map(fn($v) => (float) $v['total'], $validas));
$bilhetes = array_sum(array_map(fn($v) => (int) $v['quantity'], $validas));
$entradas = array_sum(array_map(fn($v) => $v['status'] === 'used' ? (int) $v['quantity'] : 0, $vendas));

// resumo por jogo
$porJogo = [];
foreach ($validas as $v) {
    $k = (int) $v['game_id'];
    $porJogo[$k] ??= ['opponent' => $v['opponent'], 'game_date' => $v['game_date'], 'bilhetes' => 0, 'total' => 0.0];
    $porJogo[$k]['bilhetes'] += (int) $v['quantity'];
    $porJogo[$k]['total']    += (float) $v['total'];
}
// o que já está lançado nas Finanças, por jogo (inclui jogos cujas vendas foram entretanto todas canceladas)
$stmt = $pdo->prepare(
    'SELECT f.ticket_game_id, f.amount, g.opponent, g.game_date FROM finances f JOIN games g ON g.id = f.ticket_game_id
     WHERE f.team_id = :team_id AND f.ticket_game_id IS NOT NULL'
);
$stmt->execute(['team_id' => $teamId]);
foreach ($stmt->fetchAll() as $f) {
    $k = (int) $f['ticket_game_id'];
    $porJogo[$k] ??= ['opponent' => $f['opponent'], 'game_date' => $f['game_date'], 'bilhetes' => 0, 'total' => 0.0];
    $porJogo[$k]['lancado'] = (float) $f['amount'];
}
uasort($porJogo, fn($a, $b) => strcmp($b['game_date'], $a['game_date']));
$porLancar = array_sum(array_map(fn($j) => abs($j['total'] - ($j['lancado'] ?? 0)) >= 0.005 ? 1 : 0, $porJogo));

$pageTitle = 'Bilhetes';
$activeNav = 'bilhetes';
require __DIR__ . '/../includes/layout/admin_top.php';
?>

<div class="lp pn">
    <?php panel_head('Bilhetes', count($vendas) . ' compras para os jogos de ' . $team['name'] . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="?exportar=csv"><i class="fa-solid fa-file-csv"></i> Exportar CSV</a>'
        . '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../visitante/bilhetes.php?equipa=' . $teamId . '"><i class="fa-solid fa-globe"></i> Página de venda</a>'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $bilhetes ?></b><span>Bilhetes vendidos</span></div>
        <div class="lp-card lp-kpi lp-kpi--up"><b style="font-size:28px;"><?= pn_money($receita) ?></b><span>Receita de bilheteira</span></div>
        <div class="lp-card lp-kpi"><b><?= $entradas ?></b><span>Entradas já registadas</span></div>
        <div class="lp-card lp-kpi"><b><?= count($vendas) - count($validas) ?></b><span>Compras canceladas</span></div>
    </section>

    <div class="pn-cols">
        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-qrcode"></i>Validar bilhete à entrada</h2></div>
                <form method="post" class="lp-pad" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <?= panel_csrf() ?><input type="hidden" name="action" value="check">
                    <div class="lp-field" style="flex:1 1 240px;"><label for="b-code">Código do bilhete</label>
                        <input id="b-code" name="code" type="text" maxlength="40" required autocomplete="off" placeholder="TICKET-XXXXXXXXXX" value="<?= pub_e($codigoProcurado) ?>" style="text-transform:uppercase;letter-spacing:1px;" autofocus></div>
                    <button type="submit" class="lp-btn lp-btn--primary" style="height:46px;"><i class="fa-solid fa-magnifying-glass"></i> Verificar</button>
                </form>

                <?php if ($verificado === false): ?>
                    <div class="lp-pad" style="padding-top:0;"><div class="lp-notice lp-notice--error" role="alert"><i class="fa-solid fa-circle-xmark"></i><div><b>Código não encontrado.</b> Não existe nenhum bilhete com esse código para os jogos desta equipa.</div></div></div>
                <?php elseif (is_array($verificado)): [$rotulo, $tipo] = $estados[$verificado['status']] ?? ['—', '']; $ok = $verificado['status'] === 'confirmed'; ?>
                    <div class="lp-pad" style="padding-top:0;">
                        <div class="lp-notice <?= $ok ? '' : 'lp-notice--error' ?>" role="status">
                            <i class="fa-solid fa-<?= $ok ? 'circle-check' : 'triangle-exclamation' ?>"></i>
                            <div style="flex:1;">
                                <b><?= $ok ? 'Bilhete válido' : ($verificado['status'] === 'used' ? 'Bilhete já utilizado' : 'Bilhete cancelado') ?></b>
                                — <?= (int) $verificado['quantity'] ?> entrada(s) <?= pub_e(ucfirst((string) $verificado['ticket_type'])) ?><br>
                                <?= pub_e($team['name']) ?> vs <?= pub_e($verificado['opponent']) ?> · <?= pub_e(pub_date($verificado['game_date'])) ?> <?= pub_e(pub_time($verificado['game_time'])) ?><br>
                                <span style="color:var(--text-secondary);">Comprador: <?= pub_e($verificado['buyer_name']) ?> (<?= pub_e($verificado['buyer_email']) ?>)</span>
                                <?php if ($ok): ?>
                                <form method="post" style="margin-top:12px;"><?= panel_csrf() ?><input type="hidden" name="action" value="use"><input type="hidden" name="id" value="<?= (int) $verificado['id'] ?>">
                                    <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-door-open"></i> Registar entrada</button></form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="lp-card" data-filter>
                <div class="pn-section-title">
                    <h2><i class="fa-solid fa-receipt"></i>Compras</h2>
                    <div class="lp-search" style="max-width:260px;"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Código, nome ou email..." aria-label="Procurar compra"></div>
                </div>
                <div class="lp-table-wrap"><table class="lp-table pn-table">
                    <thead><tr><th scope="col">Código</th><th scope="col">Comprador</th><th scope="col">Jogo</th><th scope="col" class="num">Qtd.</th><th scope="col" class="num">Total</th><th scope="col">Estado</th><th scope="col" class="end">Ações</th></tr></thead>
                    <tbody>
                        <?php foreach ($vendas as $v): [$rotulo, $tipo] = $estados[$v['status']] ?? [$v['status'], '']; ?>
                        <tr data-filter-item data-name="<?= pub_e(mb_strtolower($v['code'] . ' ' . $v['buyer_name'] . ' ' . $v['buyer_email'] . ' ' . $v['opponent'])) ?>">
                            <td><code style="color:var(--text);font-size:12px;"><?= pub_e($v['code']) ?></code><small><?= pub_e(pub_date($v['created_at'], 'd/m/Y H:i')) ?></small></td>
                            <td><?= pub_e($v['buyer_name']) ?><small><?= pub_e($v['buyer_email']) ?></small></td>
                            <td>vs <?= pub_e($v['opponent']) ?><small><?= pub_e(pub_date($v['game_date'])) ?> · <?= pub_e(ucfirst((string) $v['ticket_type'])) ?></small></td>
                            <td class="num"><?= (int) $v['quantity'] ?></td>
                            <td class="num" style="white-space:nowrap;"><?= pn_money($v['total']) ?></td>
                            <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= $rotulo ?></span></td>
                            <td class="end"><span class="pn-row-actions">
                                <?php if ($v['status'] === 'confirmed'): ?>
                                <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="use"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                    <button type="submit" class="pn-icon-btn" title="Registar entrada" aria-label="Registar entrada"><i class="fa-solid fa-door-open"></i></button></form>
                                <form method="post" data-confirm="Cancelar a compra <?= pub_e($v['code']) ?>?"><?= panel_csrf() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                    <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Cancelar" aria-label="Cancelar compra"><i class="fa-solid fa-ban"></i></button></form>
                                <?php else: ?>
                                <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                    <button type="submit" class="pn-icon-btn" title="Repor como válido" aria-label="Repor como válido"><i class="fa-solid fa-rotate-left"></i></button></form>
                                <?php endif; ?>
                            </span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="pn-empty" data-filter-empty <?= $vendas ? 'hidden' : '' ?>><i class="fa-solid fa-ticket"></i><?= $vendas ? 'Nenhuma compra corresponde à pesquisa.' : 'Ainda não foram vendidos bilhetes.' ?></p>
            </section>
        </div>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-trophy"></i>Por jogo</h2></div>
            <div class="pn-list">
                <?php foreach ($porJogo as $gameId => $j):
                    $lancado = $j['lancado'] ?? null;
                    $certo   = $lancado !== null && abs($j['total'] - $lancado) < 0.005;
                ?>
                <div style="flex-wrap:wrap;">
                    <div class="grow"><b>vs <?= pub_e($j['opponent']) ?></b><small><?= pub_e(pub_date($j['game_date'])) ?> · <?= $j['bilhetes'] ?> bilhetes</small></div>
                    <span class="pn-money"><?= pn_money($j['total']) ?></span>
                    <div style="flex:1 0 100%;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <?php if ($certo): ?>
                            <span class="lp-badge lp-badge--win"><i class="fa-solid fa-check"></i> Nas Finanças</span>
                        <?php else: ?>
                            <span class="lp-badge lp-badge--<?= $lancado === null ? '' : 'gold' ?>"><?= $lancado === null ? 'Por lançar' : 'Finanças têm ' . pn_money($lancado) ?></span>
                            <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="post"><input type="hidden" name="game_id" value="<?= (int) $gameId ?>">
                                <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-sack-dollar"></i> <?= $lancado === null ? 'Lançar nas Finanças' : 'Acertar' ?></button></form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$porJogo): ?><div class="pn-empty"><i class="fa-solid fa-ticket"></i>Sem vendas.</div><?php endif; ?>
            <div class="lp-pad" style="padding-top:0;"><p class="pn-help">Quando fechar a caixa de um jogo, lance a receita nas <a href="financas.php" style="color:var(--accent-text);">Finanças</a>. Fica um movimento por jogo; se depois houver vendas ou cancelamentos, use "Acertar".</p></div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout/admin_bottom.php'; ?>
