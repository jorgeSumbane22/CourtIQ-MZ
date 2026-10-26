<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Meus recordes
 * As melhores marcas do jogador num só jogo e os totais da temporada.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);

$pageTitle = 'Meus Recordes';
$activeNav = 'meus-recordes';

if (!$player) {
    require panel_layout('top');
    panel_no_player();
    require panel_layout('bottom');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT g.game_date, g.opponent, g.our_score, g.opponent_score, s.*
     FROM game_player_stats s JOIN games g ON g.id = s.game_id
     WHERE s.player_id = :player_id AND g.team_id = :team_id
     ORDER BY g.game_date'
);
$stmt->execute(['player_id' => (int) $player['id'], 'team_id' => $teamId]);
$jogos = $stmt->fetchAll();

$marcas = [
    'points'         => ['Pontos', 'basketball'],
    'rebounds'       => ['Ressaltos', 'arrows-up-down'],
    'assists'        => ['Assistências', 'handshake-angle'],
    'steals'         => ['Roubos de bola', 'hand'],
    'blocks'         => ['Desarmes', 'shield-halved'],
    'minutes_played' => ['Minutos', 'clock'],
    'efficiency'     => ['Eficiência', 'bolt'],
];

$recordes = [];
$totais   = [];
foreach ($marcas as $campo => [$rotulo, $icone]) {
    $melhor = null;
    $soma   = 0;
    foreach ($jogos as $j) {
        $soma += (float) $j[$campo];
        if ($melhor === null || (float) $j[$campo] > (float) $melhor[$campo]) {   // em caso de igualdade fica o primeiro jogo
            $melhor = $j;
        }
    }
    $recordes[$campo] = ['rotulo' => $rotulo, 'icone' => $icone, 'jogo' => $melhor];
    $totais[$campo]   = $soma;
}

// Séries e marcos
$duplos = 0;      // duplo-duplo: 10 ou mais em duas categorias
$acima10 = 0;
$vitorias = 0;
foreach ($jogos as $j) {
    $dez = count(array_filter([(int) $j['points'], (int) $j['rebounds'], (int) $j['assists'], (int) $j['steals'], (int) $j['blocks']], fn($v) => $v >= 10));
    if ($dez >= 2) $duplos++;
    if ((int) $j['points'] >= 10) $acima10++;
    if ((int) $j['our_score'] > (int) $j['opponent_score']) $vitorias++;
}

require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Meus recordes', 'As suas melhores marcas num só jogo, em ' . count($jogos) . ' jogos.'); ?>

    <?php if (!$jogos): ?>
        <div class="lp-card"><div class="pn-empty"><i class="fa-solid fa-medal"></i>Ainda não tem jogos com estatísticas. Os recordes aparecem aqui depois do primeiro jogo.</div></div>
    <?php else: ?>

    <section class="lp-grid lp-grid--wide">
        <?php foreach ($recordes as $campo => $r): $j = $r['jogo']; ?>
        <article class="lp-card lp-pad" style="display:flex;gap:16px;align-items:center;">
            <span class="lp-info-icon" style="width:52px;height:52px;font-size:20px;"><i class="fa-solid fa-<?= $r['icone'] ?>"></i></span>
            <div style="min-width:0;flex:1;">
                <div class="lp-sub" style="margin-bottom:2px;"><?= $r['rotulo'] ?></div>
                <b style="font-family:var(--display);font-size:38px;font-weight:800;color:var(--accent-text);line-height:1;"><?= number_format((float) $j[$campo], 0) ?></b>
                <small style="display:block;font-size:13px;color:var(--muted);margin-top:4px;">vs <?= pub_e($j['opponent']) ?> · <?= pub_e(pub_date($j['game_date'])) ?></small>
            </div>
        </article>
        <?php endforeach; ?>
    </section>

    <div class="pn-cols pn-cols--even">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-calculator"></i>Totais da temporada</h2></div>
            <div class="pn-list">
                <?php foreach (['points' => 'Pontos', 'rebounds' => 'Ressaltos', 'assists' => 'Assistências', 'steals' => 'Roubos de bola', 'blocks' => 'Desarmes', 'minutes_played' => 'Minutos jogados'] as $campo => $rotulo): ?>
                <div><div class="grow"><b><?= $rotulo ?></b></div><b style="font-family:var(--display);font-size:22px;"><?= number_format($totais[$campo], 0, ',', '.') ?></b></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-award"></i>Marcos</h2></div>
            <div class="pn-list">
                <div><span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-basketball"></i></span><div class="grow"><b>Jogos disputados</b></div><b style="font-family:var(--display);font-size:22px;"><?= count($jogos) ?></b></div>
                <div><span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-trophy"></i></span><div class="grow"><b>Vitórias em campo</b></div><b style="font-family:var(--display);font-size:22px;"><?= $vitorias ?></b></div>
                <div><span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-fire"></i></span><div class="grow"><b>Jogos com 10 ou mais pontos</b></div><b style="font-family:var(--display);font-size:22px;"><?= $acima10 ?></b></div>
                <div><span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-star"></i></span><div class="grow"><b>Duplos-duplos</b><small>10 ou mais em duas categorias no mesmo jogo</small></div><b style="font-family:var(--display);font-size:22px;"><?= $duplos ?></b></div>
            </div>
        </section>
    </div>

    <?php endif; ?>
</div>

<?php require panel_layout('bottom'); ?>
