<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Auditoria
 * Ações feitas em todas as equipas (quem alterou o quê). Os inícios de sessão ficam em Logs.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

$equipas = panel_all_teams($pdo);

// Filtros
$teamId = (int) ($_GET['equipa'] ?? 0);
$dias   = (int) ($_GET['dias'] ?? 30);
if (!in_array($dias, [1, 7, 30, 90, 365], true)) {
    $dias = 30;
}

// "acessos" mostra só os inícios de sessão; "acoes" (o habitual) mostra tudo o resto
$tipo   = ($_GET['tipo'] ?? '') === 'acessos' ? 'acessos' : 'acoes';
$where  = [$tipo === 'acessos' ? "a.action = 'login'" : "a.action <> 'login'", 'a.created_at >= DATE_SUB(NOW(), INTERVAL :dias DAY)'];
$params = ['dias' => $dias];
if ($teamId === -1) {
    $where[] = 'a.team_id IS NULL';
} elseif ($teamId > 0) {
    $where[] = 'a.team_id = :team_id';
    $params['team_id'] = $teamId;
}

$stmt = $pdo->prepare(
    'SELECT a.*, u.name AS user_name, u.role, t.name AS team_name
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN teams t ON t.id = a.team_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY a.created_at DESC, a.id DESC LIMIT 500'
);
$stmt->bindValue('dias', $dias, PDO::PARAM_INT);
if (isset($params['team_id'])) $stmt->bindValue('team_id', $params['team_id'], PDO::PARAM_INT);
$stmt->execute();
$registos = $stmt->fetchAll();

if (($_GET['exportar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="auditoria_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data', 'Utilizador', 'Equipa', 'Acao', 'Detalhe', 'IP'], ';');
    foreach ($registos as $r) {
        fputcsv($out, [$r['created_at'], $r['username'], $r['team_name'] ?? 'Global', $r['action'], $r['details'], $r['ip_address']], ';');
    }
    exit;
}

$porAcao   = array_count_values(array_column($registos, 'action'));
arsort($porAcao);
$porEquipa = array_count_values(array_map(fn($r) => $r['team_name'] ?? 'Global', $registos));
arsort($porEquipa);
$maxEquipa = max(1, ...array_values($porEquipa ?: [1]));
$perigosas = count(array_filter($registos, fn($r) => preg_match('/removid|apagad|delete|password/', (string) $r['action'])));

$qs = fn(array $extra) => '?' . http_build_query($extra + ['equipa' => $teamId, 'dias' => $dias]);

$pageTitle = 'Auditoria';
$activeNav = 'auditoria';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Auditoria', count($registos) . ' ações nos últimos ' . $dias . ' dia(s)' . ($teamId > 0 ? ' em ' . ($equipas[$teamId]['name'] ?? '') : '') . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="' . pub_e($qs(['exportar' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i> Exportar CSV</a>'); ?>

    <form method="get" class="lp-card lp-pad" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
        <div class="lp-field" style="flex:1 1 180px;"><label for="f-tipo">Mostrar</label>
            <select id="f-tipo" name="tipo">
                <option value="acoes"<?= $tipo === 'acoes' ? ' selected' : '' ?>>Ações</option>
                <option value="acessos"<?= $tipo === 'acessos' ? ' selected' : '' ?>>Inícios de sessão</option>
            </select>
        </div>
        <div class="lp-field" style="flex:1 1 220px;"><label for="f-equipa">Equipa</label>
            <select id="f-equipa" name="equipa">
                <option value="0">Todas as equipas</option>
                <option value="-1"<?= $teamId === -1 ? ' selected' : '' ?>>Ações globais (sem equipa)</option>
                <?php foreach ($equipas as $e): ?><option value="<?= (int) $e['id'] ?>"<?= (int) $e['id'] === $teamId ? ' selected' : '' ?>><?= pub_e($e['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="lp-field" style="flex:1 1 180px;"><label for="f-dias">Período</label>
            <select id="f-dias" name="dias">
                <?php foreach ([1 => 'Hoje', 7 => 'Últimos 7 dias', 30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias', 365 => 'Último ano'] as $d => $rotulo): ?>
                <option value="<?= $d ?>"<?= $d === $dias ? ' selected' : '' ?>><?= $rotulo ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm" style="height:46px;"><i class="fa-solid fa-filter"></i> Filtrar</button>
    </form>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi"><b><?= count($registos) ?></b><span>Ações</span></div>
        <div class="lp-card lp-kpi"><b><?= count(array_unique(array_filter(array_column($registos, 'username')))) ?></b><span>Utilizadores</span></div>
        <div class="lp-card lp-kpi"><b><?= count($porAcao) ?></b><span>Tipos de ação</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $perigosas ? 'down' : 'up' ?>"><b><?= $perigosas ?></b><span>Remoções e palavras-passe</span></div>
    </section>

    <div class="pn-cols">
        <section class="lp-card" data-filter>
            <div class="pn-section-title">
                <h2><i class="fa-solid fa-clipboard-list"></i>Registo</h2>
                <div class="lp-search" style="max-width:260px;"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Procurar..." aria-label="Procurar na auditoria"></div>
            </div>
            <div class="lp-table-wrap"><table class="lp-table pn-table">
                <thead><tr><th scope="col">Quando</th><th scope="col">Quem</th><th scope="col">Equipa</th><th scope="col">Ação</th><th scope="col">Detalhe</th></tr></thead>
                <tbody>
                    <?php foreach ($registos as $r):
                        $sensivel = (bool) preg_match('/removid|apagad|delete|password/', (string) $r['action']);
                        $rotulo = ucfirst(str_replace('_', ' ', (string) $r['action']));
                    ?>
                    <tr data-filter-item data-name="<?= pub_e(mb_strtolower($r['username'] . ' ' . $r['user_name'] . ' ' . $r['team_name'] . ' ' . $rotulo . ' ' . $r['details'])) ?>">
                        <td style="white-space:nowrap;"><b><?= pub_e(pub_date($r['created_at'], 'd/m/Y')) ?></b><small><?= pub_e(pub_date($r['created_at'], 'H:i:s')) ?></small></td>
                        <td><?= pub_e($r['user_name'] ?: '—') ?><small><?= pub_e($r['username'] ?: '') ?></small></td>
                        <td><?= $r['team_name'] ? pub_e($r['team_name']) : '<span class="lp-badge lp-badge--gold">Global</span>' ?></td>
                        <td><span class="lp-badge lp-badge--<?= $sensivel ? 'loss' : '' ?>"><?= pub_e($rotulo) ?></span></td>
                        <td style="color:var(--text-secondary);"><?= pub_e($r['details'] ?: '—') ?><small><?= pub_e($r['ip_address'] ?: '') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="pn-empty" data-filter-empty <?= $registos ? 'hidden' : '' ?>><i class="fa-solid fa-clipboard-list"></i><?= $registos ? 'Nada corresponde à pesquisa.' : 'Sem ações neste período.' ?></p>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-chart-simple"></i>Por equipa</h2></div>
            <?php if ($porEquipa): ?>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($porEquipa as $nome => $n): ?>
                <div class="lp-bar-row"><span title="<?= pub_e($nome) ?>"><?= pub_e($nome) ?></span><span class="lp-bar"><i style="width:<?= round($n / $maxEquipa * 100) ?>%"></i></span><b><?= $n ?></b></div>
                <?php endforeach; ?>
            </div></div>
            <?php else: ?><div class="pn-empty"><i class="fa-solid fa-chart-simple"></i>Sem dados.</div><?php endif; ?>
        </section>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
