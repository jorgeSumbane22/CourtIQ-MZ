<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Relatórios globais
 * A liga inteira: classificação, comparação entre equipas, melhores jogadores e exportações.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

// ============================================================================
// EXPORTAÇÕES CSV (todas as equipas)
// ============================================================================
$exportacoes = [
    'equipas' => ['Equipas', ['Equipa', 'Cidade', 'Pavilhao', 'Fundacao', 'Estado'],
        'SELECT name, city, stadium, founded_year, status FROM teams ORDER BY name'],
    'contas' => ['Contas (sem palavras-passe)', ['Nome', 'Utilizador', 'Email', 'Papel', 'Equipa', 'Estado', 'Ultimo_acesso'],
        'SELECT u.name, u.username, u.email, u.role, t.name, u.status, u.last_login FROM users u LEFT JOIN teams t ON t.id = u.team_id ORDER BY t.name, u.role, u.name'],
    'jogadores' => ['Jogadores', ['Equipa', 'Numero', 'Nome', 'Posicao', 'Estado', 'Jogos', 'PTS', 'REB', 'AST'],
        'SELECT t.name, p.jersey_number, COALESCE(u.name, p.name), p.position, p.status, COUNT(s.id), ROUND(COALESCE(AVG(s.points), 0), 1),
                ROUND(COALESCE(AVG(s.rebounds), 0), 1), ROUND(COALESCE(AVG(s.assists), 0), 1)
         FROM players p JOIN teams t ON t.id = p.team_id LEFT JOIN users u ON u.id = p.user_id LEFT JOIN game_player_stats s ON s.player_id = p.id
         GROUP BY p.id ORDER BY t.name, p.jersey_number'],
    'jogos' => ['Jogos', ['Equipa', 'Data', 'Adversario', 'Casa_Fora', 'Estado', 'Pontos_Equipa', 'Pontos_Adversario'],
        "SELECT t.name, g.game_date, g.opponent, IF(g.home_away = 'away', 'Fora', 'Casa'), g.status, g.our_score, g.opponent_score
         FROM games g JOIN teams t ON t.id = g.team_id ORDER BY g.game_date, t.name"],
];
$exportar = (string) ($_GET['exportar'] ?? '');
if (isset($exportacoes[$exportar])) {
    [, $cabecalho, $sql] = $exportacoes[$exportar];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="liga_' . $exportar . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $cabecalho, ';');
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $linha) {
        fputcsv($out, $linha, ';');
    }
    panel_log_global($pdo, 'exportacao_global', $exportar . '.csv');
    exit;
}

// ============================================================================
// DADOS
// ============================================================================
$tabela = $pdo->query(
    "SELECT t.id, t.name,
            COUNT(g.id) AS jogos,
            COALESCE(SUM(g.our_score > g.opponent_score), 0) AS vitorias,
            COALESCE(SUM(g.our_score < g.opponent_score), 0) AS derrotas,
            COALESCE(SUM(g.our_score), 0) AS marcados, COALESCE(SUM(g.opponent_score), 0) AS sofridos,
            COALESCE(SUM(IF(g.our_score > g.opponent_score, 2, 1)), 0) AS pontos,
            (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
            (SELECT COALESCE(AVG(a.present) * 100, 0) FROM attendance a JOIN players p ON p.id = a.player_id WHERE p.team_id = t.id) AS presenca,
            (SELECT COALESCE(SUM(IF(f.type = 'income', f.amount, -f.amount)), 0) FROM finances f WHERE f.team_id = t.id) AS saldo
     FROM teams t
     LEFT JOIN games g ON g.team_id = t.id AND g.status = 'finished'
     WHERE t.status = 'active'
     GROUP BY t.id
     ORDER BY pontos DESC, (COALESCE(SUM(g.our_score), 0) - COALESCE(SUM(g.opponent_score), 0)) DESC, t.name"
)->fetchAll();

$marcadores = $pdo->query(
    'SELECT COALESCE(u.name, p.name) AS nome, t.name AS equipa, COUNT(s.id) AS jogos, AVG(s.points) AS pts, AVG(s.rebounds) AS reb, AVG(s.assists) AS ast
     FROM game_player_stats s JOIN players p ON p.id = s.player_id JOIN teams t ON t.id = p.team_id LEFT JOIN users u ON u.id = p.user_id
     GROUP BY p.id ORDER BY pts DESC LIMIT 10'
)->fetchAll();

$jogosDisputados = (int) $pdo->query("SELECT COUNT(DISTINCT jornada, game_date) FROM games WHERE status = 'finished'")->fetchColumn();
$jogosPorJogar   = (int) $pdo->query("SELECT COUNT(DISTINCT jornada, game_date) FROM games WHERE status = 'scheduled'")->fetchColumn();
$mediaPontos     = (float) $pdo->query("SELECT COALESCE(AVG(our_score), 0) FROM games WHERE status = 'finished'")->fetchColumn();
$saldoLiga       = array_sum(array_map(fn($t) => (float) $t['saldo'], $tabela));

$pageTitle = 'Relatórios';
$activeNav = 'relatorios-globais';
require panel_layout('top');
?>

<style>
    @media print {
        .sidebar, .topbar, .pn-head-actions, .no-print, .toast, .dark-mode-toggle-wrapper { display: none !important; }
        .main { margin-left: 0 !important; }
        html, body, .main, .content { background: #fff !important; color: #000 !important; }
    }
</style>

<div class="lp pn">
    <?php panel_head('Relatórios globais', 'A liga em ' . date('d/m/Y') . ': ' . count($tabela) . ' equipas.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir</button>'); ?>

    <section class="lp-kpis" aria-label="Resumo da liga">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $jogosDisputados ?></b><span>Jogos disputados</span></div>
        <div class="lp-card lp-kpi"><b><?= $jogosPorJogar ?></b><span>Jogos por disputar</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format($mediaPontos, 1) ?></b><span>Pontos por equipa por jogo</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $saldoLiga >= 0 ? 'up' : 'down' ?>"><b style="font-size:24px;"><?= pn_money($saldoLiga) ?></b><span>Saldo somado das equipas</span></div>
    </section>

    <section class="lp-card no-print">
        <div class="pn-section-title"><h2><i class="fa-solid fa-file-csv"></i>Exportar (todas as equipas)</h2><small>CSV, abre no Excel</small></div>
        <div class="lp-pad"><div class="lp-chips">
            <?php foreach ($exportacoes as $chave => [$rotulo]): ?>
            <a class="lp-btn lp-btn--ghost lp-btn--sm" href="?exportar=<?= $chave ?>"><i class="fa-solid fa-download"></i> <?= pub_e($rotulo) ?></a>
            <?php endforeach; ?>
        </div></div>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-ranking-star"></i>Classificação e comparação</h2></div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr>
                <th scope="col">#</th><th scope="col">Equipa</th><th scope="col" class="num">J</th><th scope="col" class="num">V</th><th scope="col" class="num">D</th>
                <th scope="col" class="num">Saldo pts</th><th scope="col" class="num">Pts</th><th scope="col" class="num">Jogadores</th>
                <th scope="col" class="num">Presença</th><th scope="col" class="num">Saldo financeiro</th>
            </tr></thead>
            <tbody>
                <?php foreach ($tabela as $i => $t): $saldoPts = (int) $t['marcados'] - (int) $t['sofridos']; ?>
                <tr>
                    <td><span class="lp-pos<?= $i < 3 ? ' lp-pos--' . ($i + 1) : '' ?>"><?= $i + 1 ?></span></td>
                    <td class="lp-table-team"><?= pub_e($t['name']) ?></td>
                    <td class="num"><?= (int) $t['jogos'] ?></td>
                    <td class="num"><?= (int) $t['vitorias'] ?></td>
                    <td class="num"><?= (int) $t['derrotas'] ?></td>
                    <td class="num <?= $saldoPts >= 0 ? 'lp-up' : 'lp-down' ?>"><?= ($saldoPts > 0 ? '+' : '') . $saldoPts ?></td>
                    <td class="num lp-table-pts"><?= (int) $t['pontos'] ?></td>
                    <td class="num"><?= (int) $t['jogadores'] ?></td>
                    <td class="num"><?= number_format((float) $t['presenca'], 0) ?>%</td>
                    <td class="num" style="white-space:nowrap;color:var(--<?= (float) $t['saldo'] >= 0 ? 'green' : 'red' ?>);"><?= pn_money($t['saldo']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-medal"></i>Melhores marcadores da liga</h2><small>Média de pontos por jogo</small></div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">#</th><th scope="col">Jogador</th><th scope="col">Equipa</th><th scope="col" class="num">J</th><th scope="col" class="num">PTS</th><th scope="col" class="num">REB</th><th scope="col" class="num">AST</th></tr></thead>
            <tbody>
                <?php foreach ($marcadores as $i => $m): ?>
                <tr>
                    <td><span class="lp-pos<?= $i < 3 ? ' lp-pos--' . ($i + 1) : '' ?>"><?= $i + 1 ?></span></td>
                    <td class="lp-table-team"><?= pub_e($m['nome']) ?></td>
                    <td><?= pub_e($m['equipa']) ?></td>
                    <td class="num"><?= (int) $m['jogos'] ?></td>
                    <td class="num lp-table-pts"><?= number_format((float) $m['pts'], 1) ?></td>
                    <td class="num"><?= number_format((float) $m['reb'], 1) ?></td>
                    <td class="num"><?= number_format((float) $m['ast'], 1) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (!$marcadores): ?><div class="pn-empty"><i class="fa-solid fa-medal"></i>Ainda não há estatísticas de jogo.</div><?php endif; ?>
    </section>
</div>

<?php require panel_layout('bottom'); ?>
