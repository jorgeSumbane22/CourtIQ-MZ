<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Admin: Finanças
 * Receitas e despesas da equipa do administrador.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);

$categorias = [
    'patrocinio'     => 'Patrocínio',
    'bilhetes'       => 'Bilhetes',
    'infraestrutura' => 'Infraestrutura',
    'material'       => 'Material',
    'viagens'        => 'Viagens',
    'salarios'       => 'Salários',
    'outros'         => 'Outros',
];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['finance_id'] ?? 0);

    try {
        if ($acao !== 'create' && !team_owns('finances', $id)) {
            throw new RuntimeException('Movimento não encontrado.');
        }

        if ($acao === 'create' || $acao === 'update') {
            $dados = [
                'description' => trim((string) ($_POST['description'] ?? '')),
                'amount'      => (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0')),
                'type'        => ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income',
                'category'    => (string) ($_POST['category'] ?? 'outros'),
                'date'        => trim((string) ($_POST['date'] ?? '')),
            ];
            if ($dados['description'] === '' || mb_strlen($dados['description']) > 255) throw new RuntimeException('Indique a descrição do movimento.');
            if ($dados['amount'] <= 0 || $dados['amount'] > 99999999) throw new RuntimeException('O valor tem de ser maior que zero.');
            if (!isset($categorias[$dados['category']])) $dados['category'] = 'outros';
            if (!strtotime($dados['date'])) throw new RuntimeException('Indique a data do movimento.');

            if ($acao === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO finances (team_id, description, amount, type, category, date) VALUES (:team_id, :description, :amount, :type, :category, :date)'
                );
                $stmt->execute($dados + ['team_id' => $teamId]);
                panel_log($pdo, $teamId, 'movimento_criado', $dados['description'] . ' (' . pn_money($dados['amount']) . ')');
                flash_set('success', 'Movimento registado.');
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE finances SET description = :description, amount = :amount, type = :type, category = :category, date = :date
                     WHERE id = :id AND team_id = :team_id'
                );
                $stmt->execute($dados + ['id' => $id, 'team_id' => $teamId]);
                flash_set('success', 'Movimento atualizado.');
            }

        } elseif ($acao === 'delete') {
            $pdo->prepare('DELETE FROM finances WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
            panel_log($pdo, $teamId, 'movimento_removido', '#' . $id);
            flash_set('success', 'Movimento removido.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/financas: ' . $e->getMessage());
    }
    panel_redirect('financas.php');
}

$stmt = $pdo->prepare('SELECT * FROM finances WHERE team_id = :team_id ORDER BY date DESC, id DESC');
$stmt->execute(['team_id' => $teamId]);
$movimentos = $stmt->fetchAll();

if (($_GET['exportar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="financas_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data', 'Descricao', 'Tipo', 'Categoria', 'Valor'], ';');
    foreach ($movimentos as $m) {
        fputcsv($out, [$m['date'], $m['description'], $m['type'] === 'income' ? 'Receita' : 'Despesa',
            $categorias[$m['category']] ?? $m['category'], number_format((float) $m['amount'], 2, ',', '')], ';');
    }
    exit;
}

$receitas = $despesas = 0.0;
$porCategoria = [];
foreach ($movimentos as $m) {
    if ($m['type'] === 'income') {
        $receitas += (float) $m['amount'];
    } else {
        $despesas += (float) $m['amount'];
        $porCategoria[$m['category']] = ($porCategoria[$m['category']] ?? 0) + (float) $m['amount'];
    }
}
arsort($porCategoria);
$saldo  = $receitas - $despesas;
$maxCat = max(1, ...array_values($porCategoria ?: [1]));

$pageTitle = 'Finanças';
$activeNav = 'financas';
require __DIR__ . '/../includes/layout/admin_top.php';

$campos = function (string $p) use ($categorias): void { ?>
    <div class="pn-form-grid">
        <div class="lp-field full"><label for="<?= $p ?>desc">Descrição</label><input id="<?= $p ?>desc" name="description" type="text" maxlength="255" required></div>
        <div class="lp-field"><label for="<?= $p ?>type">Tipo</label>
            <select id="<?= $p ?>type" name="type"><option value="income">Receita</option><option value="expense">Despesa</option></select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>cat">Categoria</label>
            <select id="<?= $p ?>cat" name="category"><?php foreach ($categorias as $k => $v): ?><option value="<?= $k ?>"><?= pub_e($v) ?></option><?php endforeach; ?></select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>amount">Valor (MT)</label><input id="<?= $p ?>amount" name="amount" type="number" step="0.01" min="0.01" required></div>
        <div class="lp-field"><label for="<?= $p ?>date">Data</label><input id="<?= $p ?>date" name="date" type="date" required value="<?= date('Y-m-d') ?>"></div>
    </div>
<?php }; ?>

<div class="lp pn">
    <?php panel_head('Finanças', count($movimentos) . ' movimentos registados.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="?exportar=csv"><i class="fa-solid fa-file-csv"></i> Exportar CSV</a>'
        . '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novoMovimento"><i class="fa-solid fa-plus"></i> Novo movimento</button>'); ?>

    <section class="lp-kpis" aria-label="Resumo financeiro">
        <div class="lp-card lp-kpi lp-kpi--up"><b style="font-size:28px;"><?= pn_money($receitas) ?></b><span>Receitas</span></div>
        <div class="lp-card lp-kpi lp-kpi--down"><b style="font-size:28px;"><?= pn_money($despesas) ?></b><span>Despesas</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $saldo >= 0 ? 'up' : 'down' ?>"><b style="font-size:28px;"><?= pn_money($saldo) ?></b><span>Saldo</span></div>
    </section>

    <div class="pn-cols">
        <section class="lp-card" data-filter>
            <div class="pn-section-title">
                <h2><i class="fa-solid fa-list"></i>Movimentos</h2>
                <div class="lp-chips" role="group" aria-label="Filtrar movimentos">
                    <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos</button>
                    <button type="button" class="lp-chip" data-filter-value="income" aria-pressed="false">Receitas</button>
                    <button type="button" class="lp-chip" data-filter-value="expense" aria-pressed="false">Despesas</button>
                </div>
            </div>
            <div class="lp-table-wrap"><table class="lp-table pn-table">
                <thead><tr><th scope="col">Data</th><th scope="col">Descrição</th><th scope="col">Categoria</th><th scope="col" class="num">Valor</th><th scope="col" class="end">Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($movimentos as $m): $receita = $m['type'] === 'income'; ?>
                    <tr data-filter-item data-tags="<?= pub_e($m['type']) ?>">
                        <td><?= pub_e(pub_date($m['date'])) ?></td>
                        <td class="lp-table-team" style="white-space:normal;"><?= pub_e($m['description']) ?></td>
                        <td><span class="lp-badge"><?= pub_e($categorias[$m['category']] ?? ucfirst((string) $m['category'])) ?></span></td>
                        <td class="num"><span class="pn-money" style="color:var(--<?= $receita ? 'green' : 'red' ?>);"><?= $receita ? '+' : '−' ?> <?= pn_money($m['amount']) ?></span></td>
                        <td class="end"><span class="pn-row-actions">
                            <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar movimento" data-modal="#editarMovimento" data-values="<?= pn_values([
                                'finance_id' => (int) $m['id'], 'description' => $m['description'], 'amount' => $m['amount'], 'type' => $m['type'],
                                'category' => $m['category'], 'date' => $m['date'],
                            ]) ?>"><i class="fa-solid fa-pen"></i></button>
                            <form method="post" data-confirm="Remover o movimento '<?= pub_e($m['description']) ?>'?">
                                <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="finance_id" value="<?= (int) $m['id'] ?>">
                                <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover movimento"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="pn-empty" data-filter-empty <?= $movimentos ? 'hidden' : '' ?>><i class="fa-solid fa-sack-dollar"></i><?= $movimentos ? 'Nenhum movimento neste filtro.' : 'Ainda não há movimentos registados.' ?></p>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-chart-pie"></i>Despesas por categoria</h2></div>
            <?php if ($porCategoria): ?>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($porCategoria as $cat => $total): ?>
                <div class="lp-bar-row" style="grid-template-columns:minmax(0,110px) minmax(0,1fr) auto;">
                    <span><?= pub_e($categorias[$cat] ?? ucfirst((string) $cat)) ?></span>
                    <span class="lp-bar"><i style="width:<?= round($total / $maxCat * 100) ?>%"></i></span>
                    <b style="font-size:14px;"><?= $despesas > 0 ? round($total / $despesas * 100) : 0 ?>%</b>
                </div>
                <?php endforeach; ?>
            </div></div>
            <?php else: ?><div class="pn-empty"><i class="fa-solid fa-chart-pie"></i>Sem despesas registadas.</div><?php endif; ?>
        </section>
    </div>
</div>

<dialog class="pn-modal" id="novoMovimento">
    <form method="post">
        <div class="pn-modal-head"><h2>Novo movimento</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form"><?= panel_csrf() ?><input type="hidden" name="action" value="create"><?php $campos('n-'); ?></div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Registar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="editarMovimento">
    <form method="post">
        <div class="pn-modal-head"><h2>Editar movimento</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form"><?= panel_csrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="finance_id"><?php $campos('e-'); ?></div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/../includes/layout/admin_bottom.php'; ?>
