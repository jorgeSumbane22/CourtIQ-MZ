<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Minhas estatísticas
 * Médias do jogador, comparação com a média da equipa e evolução jogo a jogo.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);

$pageTitle = 'Minhas Estatísticas';
$activeNav = 'minhas-estatisticas';

if (!$player) {
    require panel_layout('top');
    panel_no_player();
    require panel_layout('bottom');
    exit;
}
$playerId = (int) $player['id'];

$stmt = $pdo->prepare(
    'SELECT g.game_date, g.opponent, g.our_score, g.opponent_score, s.*
     FROM game_player_stats s JOIN games g ON g.id = s.game_id
     WHERE s.player_id = :player_id AND g.team_id = :team_id
     ORDER BY g.game_date'
);
$stmt->execute(['player_id' => $playerId, 'team_id' => $teamId]);
$jogos = $stmt->fetchAll();

// Média da equipa e posição do jogador em cada categoria (entre colegas do mesmo escalão com jogos)
$colegas = array_filter(escalao_only(panel_players($pdo, $teamId), (string) ($player['escalao'] ?? 'seniores')), fn($j) => (int) $j['games_played'] > 0);
$categorias = [
    'avg_pts' => ['Pontos', ''],
    'avg_reb' => ['Ressaltos', 'lp-bar--blue'],
    'avg_ast' => ['Assistências', 'lp-bar--green'],
    'avg_stl' => ['Roubos de bola', ''],
    'avg_blk' => ['Desarmes', 'lp-bar--blue'],
    'avg_min' => ['Minutos', 'lp-bar--green'],
];
$comparacao = [];
foreach ($categorias as $campo => [$rotulo, $classe]) {
    $valores = array_map(fn($j) => (float) $j[$campo], $colegas);
    $meu     = (float) $player[$campo];
    $comparacao[$campo] = [
        'rotulo'  => $rotulo,
        'classe'  => $classe,
        'meu'     => $meu,
        'equipa'  => $valores ? array_sum($valores) / count($valores) : 0,
        'max'     => max(0.1, ...($valores ?: [0.1])),
        'posicao' => (int) $player['games_played'] > 0 ? count(array_filter($valores, fn($v) => $v > $meu)) + 1 : null,
    ];
}

$totais = ['points' => 0, 'rebounds' => 0, 'assists' => 0];
foreach ($jogos as $j) {
    foreach ($totais as $campo => $_) $totais[$campo] += (int) $j[$campo];
}
$maxPts = max(1, ...array_map(fn($j) => (int) $j['points'], $jogos ?: [['points' => 1]]));

require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Minhas estatísticas', (int) $player['games_played'] . ' jogos nesta temporada por ' . $team['name'] . '.'); ?>

    <?php if (!$jogos): ?>
        <div class="lp-card"><div class="pn-empty"><i class="fa-solid fa-chart-column"></i>Ainda não tem estatísticas de jogo. Aparecem aqui depois de o treinador as lançar.</div></div>
    <?php else: ?>

    <section class="lp-kpis" aria-label="Médias por jogo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= number_format((float) $player['avg_pts'], 1) ?></b><span>Pontos por jogo</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format((float) $player['avg_reb'], 1) ?></b><span>Ressaltos</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format((float) $player['avg_ast'], 1) ?></b><span>Assistências</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format((float) $player['avg_stl'], 1) ?></b><span>Roubos de bola</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format((float) $player['avg_min'], 0) ?></b><span>Minutos</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format((float) $player['avg_eff'], 1) ?></b><span>Eficiência</span></div>
    </section>

    <div class="pn-cols pn-cols--even">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-scale-balanced"></i>Eu e a equipa</h2><small>Média por jogo</small></div>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($comparacao as $c): ?>
                <div class="lp-bar-row" style="align-items:start;">
                    <span><?= $c['rotulo'] ?><?php if ($c['posicao']): ?><small style="display:block;font-weight:500;color:var(--muted);font-size:12px;"><?= $c['posicao'] ?>º da equipa</small><?php endif; ?></span>
                    <span style="display:grid;gap:4px;">
                        <span class="lp-bar"><i style="width:<?= round($c['meu'] / $c['max'] * 100) ?>%"></i></span>
                        <span class="lp-bar" style="height:6px;"><i style="width:<?= round($c['equipa'] / $c['max'] * 100) ?>%;background:var(--muted);"></i></span>
                    </span>
                    <b style="font-size:15px;line-height:1.2;"><?= number_format($c['meu'], 1) ?><br><span style="color:var(--muted);font-size:13px;"><?= number_format($c['equipa'], 1) ?></span></b>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="pn-help" style="margin-top:14px;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--accent);margin-right:6px;"></span>Eu
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--muted);margin:0 6px 0 16px;"></span>Média da equipa
            </p></div>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-chart-line"></i>Pontos jogo a jogo</h2><small>Total: <?= $totais['points'] ?> pts</small></div>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($jogos as $j): ?>
                <div class="lp-bar-row">
                    <span title="<?= pub_e($j['opponent']) ?>"><?= pub_e(pub_date($j['game_date'], 'd/m')) ?> · <?= pub_e($j['opponent']) ?></span>
                    <span class="lp-bar"><i style="width:<?= round((int) $j['points'] / $maxPts * 100) ?>%"></i></span>
                    <b><?= (int) $j['points'] ?></b>
                </div>
                <?php endforeach; ?>
            </div></div>
        </section>
    </div>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-table"></i>Todos os jogos</h2><small><?= $totais['points'] ?> pts · <?= $totais['rebounds'] ?> reb · <?= $totais['assists'] ?> ast no total</small></div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr>
                <th scope="col">Data</th><th scope="col">Adversário</th><th scope="col">Resultado</th>
                <th scope="col" class="num">MIN</th><th scope="col" class="num">PTS</th><th scope="col" class="num">REB</th><th scope="col" class="num">AST</th>
                <th scope="col" class="num">STL</th><th scope="col" class="num">BLK</th><th scope="col" class="num">FAL</th><th scope="col" class="num">EFF</th>
            </tr></thead>
            <tbody>
                <?php foreach (array_reverse($jogos) as $j): $ganhou = (int) $j['our_score'] > (int) $j['opponent_score']; ?>
                <tr>
                    <td><?= pub_e(pub_date($j['game_date'])) ?></td>
                    <td class="lp-table-team">vs <?= pub_e($j['opponent']) ?></td>
                    <td><span class="lp-badge lp-badge--<?= $ganhou ? 'win' : 'loss' ?>"><?= (int) $j['our_score'] ?>–<?= (int) $j['opponent_score'] ?></span></td>
                    <td class="num"><?= (int) $j['minutes_played'] ?></td>
                    <td class="num lp-table-pts"><?= (int) $j['points'] ?></td>
                    <td class="num"><?= (int) $j['rebounds'] ?></td>
                    <td class="num"><?= (int) $j['assists'] ?></td>
                    <td class="num"><?= (int) $j['steals'] ?></td>
                    <td class="num"><?= (int) $j['blocks'] ?></td>
                    <td class="num"><?= (int) $j['fouls'] ?></td>
                    <td class="num"><?= number_format((float) $j['efficiency'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php endif; ?>
</div>

<?php require panel_layout('bottom'); ?>
