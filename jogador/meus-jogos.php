<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Meus jogos
 * Os jogos da equipa vistos pelo jogador: convocatória nos que faltam, a sua linha de
 * estatísticas nos que já se jogaram.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);

$pageTitle = 'Meus Jogos';
$activeNav = 'meus-jogos';

if (!$player) {
    require panel_layout('top');
    panel_no_player();
    require panel_layout('bottom');
    exit;
}
$playerId = (int) $player['id'];

$stmt = $pdo->prepare(
    'SELECT g.*, s.points, s.rebounds, s.assists, s.steals, s.blocks, s.minutes_played, s.id AS stat_id,
            e.id AS conv_id, e.is_starter, e.is_titular, e.is_captain
     FROM games g
     LEFT JOIN game_player_stats s ON s.game_id = g.id AND s.player_id = :p1
     LEFT JOIN escalacao e ON e.game_id = g.id AND e.player_id = :p2
     WHERE g.team_id = :team_id' . escalao_sql('g.escalao', (string) ($player['escalao'] ?? 'seniores')) . '
     ORDER BY g.game_date DESC, g.id DESC'
);
$stmt->execute(['p1' => $playerId, 'p2' => $playerId, 'team_id' => $teamId]);
$jogos = $stmt->fetchAll();

$porJogar   = array_reverse(array_values(array_filter($jogos, fn($j) => $j['status'] !== 'finished')));
$terminados = array_values(array_filter($jogos, fn($j) => $j['status'] === 'finished'));
$jogados    = count(array_filter($terminados, fn($j) => $j['stat_id'] !== null));
$vitorias   = count(array_filter($terminados, fn($j) => $j['stat_id'] !== null && (int) $j['our_score'] > (int) $j['opponent_score']));

require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Meus jogos', $jogados . ' jogos disputados · ' . count($porJogar) . ' por disputar.'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi"><b><?= $jogados ?></b><span>Jogos em que joguei</span></div>
        <div class="lp-card lp-kpi lp-kpi--up"><b><?= $vitorias ?></b><span>Vitórias comigo em campo</span></div>
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= number_format((float) $player['avg_pts'], 1) ?></b><span>Pontos por jogo</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format((float) $player['avg_min'], 0) ?></b><span>Minutos por jogo</span></div>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-calendar-days"></i>Por disputar</h2><small><?= count($porJogar) ?> jogos</small></div>
        <?php if ($porJogar): ?>
        <div class="pn-list">
            <?php foreach ($porJogar as $j):
                $ts = strtotime($j['game_date']);
                $confirmada = ($j['escalacao_status'] ?? '') === 'confirmada';
            ?>
            <div>
                <div class="lp-date" style="width:46px;"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                <div class="grow"><b>vs <?= pub_e($j['opponent']) ?></b>
                    <small><?= pub_e(pub_time($j['game_time'])) ?> · <?= pub_e($j['location'] ?: 'Local a definir') ?> · <?= ($j['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></small></div>
                <?php if ($confirmada && $j['conv_id']): ?>
                    <span class="lp-badge lp-badge--win"><i class="fa-solid fa-check"></i> Convocado<?= ($j['is_starter'] || $j['is_titular']) ? ' · Titular' : '' ?></span>
                <?php elseif ($confirmada): ?>
                    <span class="lp-badge lp-badge--loss">Não convocado</span>
                <?php else: ?>
                    <span class="lp-badge">Convocatória por sair</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><div class="pn-empty"><i class="fa-solid fa-calendar-xmark"></i>Não há jogos agendados.</div><?php endif; ?>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-check-double"></i>Jogos terminados</h2><small>A minha linha em cada jogo</small></div>
        <?php if ($terminados): ?>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr>
                <th scope="col">Data</th><th scope="col">Adversário</th><th scope="col">Resultado</th>
                <th scope="col" class="num">MIN</th><th scope="col" class="num">PTS</th><th scope="col" class="num">REB</th>
                <th scope="col" class="num">AST</th><th scope="col" class="num">STL</th><th scope="col" class="num">BLK</th>
            </tr></thead>
            <tbody>
                <?php foreach ($terminados as $j): $ganhou = (int) $j['our_score'] > (int) $j['opponent_score']; $joguei = $j['stat_id'] !== null; ?>
                <tr<?= $joguei ? '' : ' style="opacity:0.6;"' ?>>
                    <td><?= pub_e(pub_date($j['game_date'])) ?></td>
                    <td class="lp-table-team">vs <?= pub_e($j['opponent']) ?><small><?= ($j['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></small></td>
                    <td><span class="lp-badge lp-badge--<?= $ganhou ? 'win' : 'loss' ?>"><?= (int) $j['our_score'] ?>–<?= (int) $j['opponent_score'] ?></span></td>
                    <?php if ($joguei): ?>
                    <td class="num"><?= (int) $j['minutes_played'] ?></td>
                    <td class="num lp-table-pts"><?= (int) $j['points'] ?></td>
                    <td class="num"><?= (int) $j['rebounds'] ?></td>
                    <td class="num"><?= (int) $j['assists'] ?></td>
                    <td class="num"><?= (int) $j['steals'] ?></td>
                    <td class="num"><?= (int) $j['blocks'] ?></td>
                    <?php else: ?>
                    <td colspan="6" style="color:var(--muted);text-align:right;">Não jogou</td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><div class="pn-empty"><i class="fa-solid fa-basketball"></i>A equipa ainda não terminou nenhum jogo.</div><?php endif; ?>
    </section>
</div>

<?php require panel_layout('bottom'); ?>
