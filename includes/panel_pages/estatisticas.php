<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Estatísticas
 * Números da equipa do administrador: resumo, líderes e tabela completa do plantel.
 */


[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

// escalão em vista: um treinador só vê os seus; o administrador pode ver todos ou um de cada vez
$escalaoAtual = escalao_atual($team);
$jogos     = escalao_only((new GameRepository())->finished($teamId, 200), $escalaoAtual);
$registo   = pub_record($jogos);
$jogadores = escalao_only(panel_players($pdo, $teamId), $escalaoAtual);
$comJogos  = array_values(array_filter($jogadores, fn($j) => (int) $j['games_played'] > 0));

$ordenacoes = ['avg_pts' => 'Pontos', 'avg_reb' => 'Ressaltos', 'avg_ast' => 'Assistências', 'avg_stl' => 'Roubos', 'avg_blk' => 'Desarmes', 'avg_eff' => 'Eficiência'];
$ordem = isset($ordenacoes[$_GET['ordem'] ?? '']) ? (string) $_GET['ordem'] : 'avg_pts';
usort($jogadores, fn($a, $b) => (float) $b[$ordem] <=> (float) $a[$ordem]);

$lideres = function (string $campo) use ($comJogos): array {
    $lista = $comJogos;
    usort($lista, fn($a, $b) => (float) $b[$campo] <=> (float) $a[$campo]);
    return array_slice($lista, 0, 5);
};

// casa vs fora
$casa = pub_record(array_filter($jogos, fn($j) => ($j['home_away'] ?? 'home') !== 'away'));
$fora = pub_record(array_filter($jogos, fn($j) => ($j['home_away'] ?? 'home') === 'away'));

$porJogo   = array_reverse($jogos);
$maxPontos = 1;
foreach ($porJogo as $j) {
    $maxPontos = max($maxPontos, (int) $j['our_score'], (int) $j['opponent_score']);
}
$saldo = $registo['marcados'] - $registo['sofridos'];

$pageTitle = 'Estatísticas';
$activeNav = 'estatisticas';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Estatísticas', 'Médias por jogo de ' . $team['name'] . ', calculadas a partir de ' . $registo['jogos'] . ' jogos terminados.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="jogos.php"><i class="fa-solid fa-pen"></i> Lançar estatísticas de um jogo</a>'); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <?php if (!$jogos): ?>
        <div class="lp-card"><div class="pn-empty"><i class="fa-solid fa-chart-column"></i>Ainda não há jogos terminados. Registe um resultado em <a href="jogos.php" style="color:var(--accent-text);font-weight:600;">Jogos</a>.</div></div>
    <?php else: ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--up"><b><?= $registo['vitorias'] ?></b><span>Vitórias</span></div>
        <div class="lp-card lp-kpi lp-kpi--down"><b><?= $registo['derrotas'] ?></b><span>Derrotas</span></div>
        <div class="lp-card lp-kpi"><b><?= $registo['aproveitamento'] ?>%</b><span>Aproveitamento</span></div>
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $registo['media_marcados'] ?></b><span>Pontos marcados por jogo</span></div>
        <div class="lp-card lp-kpi"><b><?= $registo['media_sofridos'] ?></b><span>Pontos sofridos por jogo</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $saldo >= 0 ? 'up' : 'down' ?>"><b><?= ($saldo > 0 ? '+' : '') . $saldo ?></b><span>Saldo de pontos</span></div>
    </section>

    <section class="lp-grid lp-grid--wide">
        <?php foreach (['avg_pts' => ['Pontos', ''], 'avg_reb' => ['Ressaltos', 'lp-bar--blue'], 'avg_ast' => ['Assistências', 'lp-bar--green']] as $campo => [$titulo, $classe]):
            $top = $lideres($campo);
            $max = max(0.1, (float) ($top[0][$campo] ?? 0));
        ?>
        <div class="lp-card lp-pad">
            <h2 class="lp-sub"><?= $titulo ?> por jogo</h2>
            <div class="lp-bars">
                <?php foreach ($top as $j): ?>
                <div class="lp-bar-row">
                    <span title="<?= pub_e($j['name']) ?>"><?= pub_e($j['name']) ?></span>
                    <span class="lp-bar <?= $classe ?>"><i style="width:<?= round((float) $j[$campo] / $max * 100) ?>%"></i></span>
                    <b><?= number_format((float) $j[$campo], 1) ?></b>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <div class="pn-cols">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-chart-simple"></i>Jogo a jogo</h2><small>Marcados vs sofridos</small></div>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($porJogo as $j): ?>
                <div class="lp-bar-row" style="align-items:start;">
                    <span title="<?= pub_e($j['opponent']) ?>"><?= pub_e(pub_date($j['game_date'], 'd/m')) ?> · <?= pub_e($j['opponent']) ?></span>
                    <span style="display:grid;gap:4px;">
                        <span class="lp-bar"><i style="width:<?= round((int) $j['our_score'] / $maxPontos * 100) ?>%"></i></span>
                        <span class="lp-bar lp-bar--blue"><i style="width:<?= round((int) $j['opponent_score'] / $maxPontos * 100) ?>%"></i></span>
                    </span>
                    <b style="font-size:15px;line-height:1.2;"><?= (int) $j['our_score'] ?><br><span style="color:var(--muted);"><?= (int) $j['opponent_score'] ?></span></b>
                </div>
                <?php endforeach; ?>
            </div></div>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-house"></i>Casa e fora</h2></div>
            <div class="pn-list">
                <?php foreach (['Em casa' => $casa, 'Fora' => $fora] as $rotulo => $r): ?>
                <div>
                    <div class="grow"><b><?= $rotulo ?></b><small><?= $r['jogos'] ?> jogos · <?= $r['media_marcados'] ?> pts marcados · <?= $r['media_sofridos'] ?> sofridos</small></div>
                    <span class="lp-score"><?= $r['vitorias'] ?>–<?= $r['derrotas'] ?></span>
                    <span class="lp-badge"><?= $r['aproveitamento'] ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="lp-card">
        <div class="pn-section-title">
            <h2><i class="fa-solid fa-table"></i>Plantel completo</h2>
            <div class="lp-chips" role="group" aria-label="Ordenar por">
                <?php foreach ($ordenacoes as $campo => $rotulo): ?>
                <a class="lp-chip<?= $campo === $ordem ? ' is-active' : '' ?>" href="?ordem=<?= $campo ?>"><?= $rotulo ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr>
                <th scope="col">Jogador</th><th scope="col" class="num">J</th><th scope="col" class="num">MIN</th><th scope="col" class="num">PTS</th>
                <th scope="col" class="num">REB</th><th scope="col" class="num">AST</th><th scope="col" class="num">STL</th><th scope="col" class="num">BLK</th><th scope="col" class="num">EFF</th>
            </tr></thead>
            <tbody>
                <?php foreach ($jogadores as $j): ?>
                <tr>
                    <td><div class="pn-user"><?= pn_avatar($j['photo_url'], $j['name']) ?><div>
                        <b><a href="jogador-perfil.php?id=<?= (int) $j['id'] ?>">#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?></a></b><small><?= pub_e(pub_position($j['position'])) ?></small></div></div></td>
                    <td class="num"><?= (int) $j['games_played'] ?></td>
                    <td class="num"><?= number_format((float) $j['avg_min'], 1) ?></td>
                    <?php foreach (['avg_pts', 'avg_reb', 'avg_ast', 'avg_stl', 'avg_blk', 'avg_eff'] as $campo): ?>
                    <td class="num<?= $campo === $ordem ? ' lp-table-pts' : '' ?>"<?= $campo === $ordem ? ' style="color:var(--accent-text);"' : '' ?>><?= number_format((float) $j[$campo], 1) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <?php endif; ?>
</div>

<?php require panel_layout('bottom'); ?>
