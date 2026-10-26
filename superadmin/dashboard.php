<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Dashboard
 * Visão global do sistema: todas as equipas, contas e atividade recente.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

$total = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

$nEquipas    = $total("SELECT COUNT(*) FROM teams WHERE status = 'active'");
$nContas     = $total('SELECT COUNT(*) FROM users');
$nJogadores  = $total('SELECT COUNT(*) FROM players');
$nJogos      = $total("SELECT COUNT(DISTINCT jornada, game_date) FROM games WHERE status = 'finished'");
$nTickets    = $total("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'");
$semEquipa   = $total("SELECT COUNT(*) FROM users WHERE team_id IS NULL AND role <> 'superadmin'");
$manutencao  = TeamHelper::maintenanceOn();

$equipas = $pdo->query(
    "SELECT t.id, t.name, t.city, t.status,
            (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id) AS contas,
            (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
            (SELECT COUNT(*) FROM games g WHERE g.team_id = t.id AND g.status = 'finished' AND g.our_score > g.opponent_score) AS vitorias,
            (SELECT COUNT(*) FROM games g WHERE g.team_id = t.id AND g.status = 'finished' AND g.our_score < g.opponent_score) AS derrotas,
            (SELECT MAX(a.created_at) FROM audit_logs a WHERE a.team_id = t.id) AS ultima_atividade
     FROM teams t
     ORDER BY t.name"
)->fetchAll();

$porPapel = $pdo->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);
$papeis   = ['superadmin' => 'Superadmin', 'admin' => 'Administradores', 'treinador' => 'Treinadores', 'jogador' => 'Jogadores'];
$maxPapel = max(1, ...array_values(array_map('intval', $porPapel)));

$atividade = $pdo->query(
    'SELECT a.*, u.name AS user_name, t.name AS team_name
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN teams t ON t.id = a.team_id
     ORDER BY a.created_at DESC, a.id DESC LIMIT 8'
)->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Visão global', 'Todas as equipas do CourtIQMZ em ' . date('d/m/Y') . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="utilizadores.php"><i class="fa-solid fa-user-plus"></i> Nova conta</a>'
        . '<a class="lp-btn lp-btn--primary lp-btn--sm" href="equipas.php"><i class="fa-solid fa-plus"></i> Nova equipa</a>'); ?>

    <?php if ($manutencao): ?>
        <div class="lp-notice lp-notice--error" role="status"><i class="fa-solid fa-screwdriver-wrench"></i><div><b>Modo de manutenção ligado.</b> Só o superadmin consegue entrar. <a href="manutencao.php" style="color:var(--accent-text);font-weight:600;">Desligar</a></div></div>
    <?php endif; ?>

    <section class="lp-kpis" aria-label="Indicadores globais">
        <a class="lp-card lp-kpi lp-kpi--accent" href="equipas.php"><b><?= $nEquipas ?></b><span>Equipas ativas</span></a>
        <a class="lp-card lp-kpi" href="utilizadores.php"><b><?= $nContas ?></b><span>Contas</span></a>
        <div class="lp-card lp-kpi"><b><?= $nJogadores ?></b><span>Jogadores</span></div>
        <a class="lp-card lp-kpi" href="relatorios-globais.php"><b><?= $nJogos ?></b><span>Jogos disputados</span></a>
        <a class="lp-card lp-kpi lp-kpi--<?= $nTickets ? 'down' : 'up' ?>" href="tickets.php"><b><?= $nTickets ?></b><span>Pedidos de suporte abertos</span></a>
        <a class="lp-card lp-kpi lp-kpi--<?= $semEquipa ? 'down' : 'up' ?>" href="utilizadores.php"><b><?= $semEquipa ?></b><span>Contas sem equipa</span></a>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-building-shield"></i>Equipas</h2><a href="equipas.php">Gerir equipas</a></div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Equipa</th><th scope="col">Estado</th><th scope="col" class="num">Contas</th><th scope="col" class="num">Jogadores</th><th scope="col" class="num">V–D</th><th scope="col">Última atividade</th><th scope="col" class="end">Painel</th></tr></thead>
            <tbody>
                <?php foreach ($equipas as $e): ?>
                <tr>
                    <td class="lp-table-team"><?= pub_e($e['name']) ?><small><?= pub_e($e['city'] ?: '—') ?></small></td>
                    <td><span class="lp-badge lp-badge--<?= $e['status'] === 'active' ? 'win' : 'loss' ?>"><?= $e['status'] === 'active' ? 'Ativa' : 'Inativa' ?></span></td>
                    <td class="num"><?= (int) $e['contas'] ?></td>
                    <td class="num"><?= (int) $e['jogadores'] ?></td>
                    <td class="num lp-table-pts"><?= (int) $e['vitorias'] ?>–<?= (int) $e['derrotas'] ?></td>
                    <td><?= $e['ultima_atividade'] ? pub_e(pub_date($e['ultima_atividade'], 'd/m/Y H:i')) : '<span style="color:var(--muted);">—</span>' ?></td>
                    <td class="end"><a class="lp-btn lp-btn--ghost lp-btn--sm" href="../admin/dashboard.php?equipa=<?= (int) $e['id'] ?>"><i class="fa-solid fa-right-to-bracket"></i> Entrar</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (!$equipas): ?><div class="pn-empty"><i class="fa-solid fa-building-shield"></i>Ainda não há equipas.</div><?php endif; ?>
    </section>

    <div class="pn-cols">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-clipboard-list"></i>Atividade recente</h2><a href="auditoria.php">Auditoria</a></div>
            <div class="pn-list">
                <?php foreach ($atividade as $a): ?>
                <div>
                    <div class="grow"><b><?= pub_e($a['user_name'] ?: ($a['username'] ?: 'Sistema')) ?> · <?= pub_e(ucfirst(str_replace('_', ' ', (string) $a['action']))) ?></b>
                        <small><?= pub_e(pub_date($a['created_at'], 'd/m/Y H:i')) ?><?= $a['team_name'] ? ' · ' . pub_e($a['team_name']) : '' ?><?= $a['details'] ? ' · ' . pub_e($a['details']) : '' ?></small></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$atividade): ?><div class="pn-empty"><i class="fa-solid fa-clipboard-list"></i>Sem atividade registada.</div><?php endif; ?>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-users"></i>Contas por papel</h2><a href="utilizadores.php">Utilizadores</a></div>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($papeis as $papel => $rotulo): $n = (int) ($porPapel[$papel] ?? 0); ?>
                <div class="lp-bar-row"><span><?= $rotulo ?></span><span class="lp-bar"><i style="width:<?= round($n / $maxPapel * 100) ?>%"></i></span><b><?= $n ?></b></div>
                <?php endforeach; ?>
            </div></div>
        </section>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
