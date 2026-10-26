<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Relatórios
 * Relatório da temporada da equipa (para imprimir) e exportações em CSV.
 */


[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

// escalão em vista: os números e as exportações desta página seguem-no
$escalaoAtual = escalao_atual($team);
$jogadores  = escalao_only(panel_players($pdo, $teamId), $escalaoAtual);
$terminados = escalao_only((new GameRepository())->finished($teamId, 500), $escalaoAtual);
$registo    = pub_record($terminados);

$valor = function (string $sql) use ($pdo, $teamId) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['team_id' => $teamId]);
    return $stmt->fetchColumn();
};

// ============================================================================
// EXPORTAÇÕES CSV (sempre só desta equipa)
// ============================================================================
$exportar = (string) ($_GET['exportar'] ?? '');
if ($exportar !== '') {
    $consultas = [
        'jogos' => [
            ['Data', 'Hora', 'Adversario', 'Local', 'Casa_Fora', 'Competicao', 'Estado', 'Pontos_Equipa', 'Pontos_Adversario'],
            "SELECT game_date, game_time, opponent, location, IF(home_away = 'away', 'Fora', 'Casa'), competition, status, our_score, opponent_score
             FROM games WHERE team_id = :team_id" . escalao_sql('escalao', $escalaoAtual) . " ORDER BY game_date",
        ],
        'treinos' => [
            ['Data', 'Inicio', 'Fim', 'Nome', 'Tipo', 'Local', 'Estado'],
            'SELECT training_date, start_time, end_time, name, type, location, status FROM trainings WHERE team_id = :team_id' . escalao_sql('escalao', $escalaoAtual) . ' ORDER BY training_date',
        ],
        'presencas' => [
            ['Jogador', 'Numero', 'Eventos', 'Presencas', 'Percentagem'],
            'SELECT COALESCE(u.name, p.name), p.jersey_number, COUNT(a.id), COALESCE(SUM(a.present), 0),
                    IF(COUNT(a.id) = 0, 0, ROUND(COALESCE(SUM(a.present), 0) / COUNT(a.id) * 100))
             FROM players p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN attendance a ON a.player_id = p.id
             WHERE p.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual) . ' GROUP BY p.id ORDER BY p.jersey_number',
        ],
        'estatisticas' => [
            ['Jogador', 'Numero', 'Posicao', 'Jogos', 'PTS', 'REB', 'AST', 'STL', 'BLK'],
            'SELECT COALESCE(u.name, p.name), p.jersey_number, p.position, COUNT(s.id), ROUND(COALESCE(AVG(s.points), 0), 1),
                    ROUND(COALESCE(AVG(s.rebounds), 0), 1), ROUND(COALESCE(AVG(s.assists), 0), 1), ROUND(COALESCE(AVG(s.steals), 0), 1), ROUND(COALESCE(AVG(s.blocks), 0), 1)
             FROM players p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN game_player_stats s ON s.player_id = p.id
             WHERE p.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual) . ' GROUP BY p.id ORDER BY 5 DESC',
        ],
    ];
    if (isset($consultas[$exportar])) {
        [$cabecalho, $sql] = $consultas[$exportar];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['team_id' => $teamId]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $exportar . '_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $cabecalho, ';');
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $linha) {
            fputcsv($out, $linha, ';');
        }
        panel_log($pdo, $teamId, 'exportacao', $exportar . '.csv');
        exit;
    }
}

$receitas = (float) $valor("SELECT COALESCE(SUM(amount), 0) FROM finances WHERE team_id = :team_id AND type = 'income'");
$despesas = (float) $valor("SELECT COALESCE(SUM(amount), 0) FROM finances WHERE team_id = :team_id AND type = 'expense'");
$treinosFeitos = (int) $valor("SELECT COUNT(*) FROM trainings WHERE team_id = :team_id AND status = 'finished'" . escalao_sql('escalao', $escalaoAtual));
$presenca = (float) $valor('SELECT COALESCE(AVG(a.present) * 100, 0) FROM attendance a JOIN players p ON p.id = a.player_id WHERE p.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual));

$marcadores = array_filter($jogadores, fn($j) => (int) $j['games_played'] > 0);
usort($marcadores, fn($a, $b) => (float) $b['avg_pts'] <=> (float) $a['avg_pts']);

$exportacoes = [
    [panel_url('jogadores.php') . '?exportar=csv', 'Plantel', 'Jogadores, posições e médias', 'user-group'],
    ['?exportar=jogos', 'Jogos', 'Calendário e resultados', 'trophy'],
    ['?exportar=estatisticas', 'Estatísticas', 'Médias por jogador', 'chart-column'],
    ['?exportar=treinos', 'Treinos', 'Todos os treinos', 'bullseye'],
    ['?exportar=presencas', 'Presenças', 'Assiduidade por jogador', 'clipboard-check'],
];
// As finanças são assunto da administração: o treinador não as vê
$comFinancas = panel_name() === 'admin';
if ($comFinancas) {
    $exportacoes[] = ['financas.php?exportar=csv', 'Finanças', 'Receitas e despesas', 'sack-dollar'];
}

$pageTitle = 'Relatórios';
$activeNav = 'relatorios';
require panel_layout('top');
?>

<style>
    @media print {
        .sidebar, .topbar, .dark-mode-toggle-global, .theme-label-global, .pn-head-actions, .no-print, .toast { display: none !important; }
        .main { margin-left: 0 !important; }
        html, body, .main, .content { background: #fff !important; color: #000 !important; }
    }
</style>

<div class="lp pn">
    <?php panel_head('Relatórios', 'Relatório da temporada de ' . $team['name'] . ' em ' . date('d/m/Y') . '.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir relatório</button>'); ?>

    <div class="no-print"><?= escalao_chips($team, $escalaoAtual, '?') ?></div>

    <section class="lp-card no-print">
        <div class="pn-section-title"><h2><i class="fa-solid fa-file-csv"></i>Exportar dados (CSV)</h2><small>Abre no Excel</small></div>
        <div class="lp-pad"><div class="lp-grid">
            <?php foreach ($exportacoes as [$href, $titulo, $descricao, $icone]): ?>
            <a class="lp-card lp-pad" href="<?= $href ?>" style="display:flex;gap:14px;align-items:center;padding:16px;">
                <span class="lp-info-icon"><i class="fa-solid fa-<?= $icone ?>"></i></span>
                <span><b style="display:block;color:var(--text);"><?= $titulo ?></b><small style="color:var(--muted);font-size:12px;"><?= $descricao ?></small></span>
            </a>
            <?php endforeach; ?>
        </div></div>
    </section>

    <section class="lp-kpis" aria-label="Resumo da temporada">
        <div class="lp-card lp-kpi"><b><?= $registo['jogos'] ?></b><span>Jogos disputados</span></div>
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $registo['vitorias'] ?>–<?= $registo['derrotas'] ?></b><span>Vitórias–Derrotas</span></div>
        <div class="lp-card lp-kpi"><b><?= $registo['aproveitamento'] ?>%</b><span>Aproveitamento</span></div>
        <div class="lp-card lp-kpi"><b><?= $treinosFeitos ?></b><span>Treinos realizados</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format($presenca, 0) ?>%</b><span>Presença média</span></div>
        <?php if ($comFinancas): ?>
        <div class="lp-card lp-kpi lp-kpi--<?= $receitas - $despesas >= 0 ? 'up' : 'down' ?>"><b style="font-size:24px;"><?= pn_money($receitas - $despesas) ?></b><span>Saldo financeiro</span></div>
        <?php endif; ?>
    </section>

    <div class="pn-cols pn-cols--even">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-trophy"></i>Resultados</h2><small><?= $registo['media_marcados'] ?> pts marcados · <?= $registo['media_sofridos'] ?> sofridos</small></div>
            <div class="lp-table-wrap"><table class="lp-table pn-table">
                <thead><tr><th scope="col">Data</th><th scope="col">Adversário</th><th scope="col">Local</th><th scope="col" class="num">Resultado</th></tr></thead>
                <tbody>
                    <?php foreach ($terminados as $j): $ganhou = (int) $j['our_score'] > (int) $j['opponent_score']; ?>
                    <tr>
                        <td><?= pub_e(pub_date($j['game_date'])) ?></td>
                        <td class="lp-table-team"><?= pub_e($j['opponent']) ?></td>
                        <td><?= ($j['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></td>
                        <td class="num"><span class="lp-badge lp-badge--<?= $ganhou ? 'win' : 'loss' ?>"><?= (int) $j['our_score'] ?>–<?= (int) $j['opponent_score'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (!$terminados): ?><div class="pn-empty"><i class="fa-solid fa-basketball"></i>Sem jogos terminados.</div><?php endif; ?>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-ranking-star"></i>Plantel</h2><small><?= count($jogadores) ?> jogadores</small></div>
            <div class="lp-table-wrap"><table class="lp-table pn-table">
                <thead><tr><th scope="col">Jogador</th><th scope="col" class="num">J</th><th scope="col" class="num">PTS</th><th scope="col" class="num">REB</th><th scope="col" class="num">AST</th></tr></thead>
                <tbody>
                    <?php foreach ($marcadores as $j): ?>
                    <tr>
                        <td class="lp-table-team">#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?></td>
                        <td class="num"><?= (int) $j['games_played'] ?></td>
                        <td class="num lp-table-pts"><?= number_format((float) $j['avg_pts'], 1) ?></td>
                        <td class="num"><?= number_format((float) $j['avg_reb'], 1) ?></td>
                        <td class="num"><?= number_format((float) $j['avg_ast'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (!$marcadores): ?><div class="pn-empty"><i class="fa-solid fa-chart-column"></i>Sem estatísticas registadas.</div><?php endif; ?>
        </section>
    </div>

    <?php if ($comFinancas): ?>
    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-sack-dollar"></i>Finanças</h2></div>
        <div class="pn-list">
            <div><div class="grow"><b>Receitas</b></div><span class="pn-money" style="color:var(--green);"><?= pn_money($receitas) ?></span></div>
            <div><div class="grow"><b>Despesas</b></div><span class="pn-money" style="color:var(--red);"><?= pn_money($despesas) ?></span></div>
            <div><div class="grow"><b>Saldo</b></div><span class="pn-money"><?= pn_money($receitas - $despesas) ?></span></div>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php require panel_layout('bottom'); ?>
