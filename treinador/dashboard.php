<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Treinador: Dashboard
 * O dia a dia da equipa do treinador: próximo jogo, plantel disponível, treinos e avisos.
 */

$panel = 'treinador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

// escalão em vista: o treinador vê o resumo dos seus escalões, um de cada vez
$escalaoAtual = escalao_atual($team);
$gameRepo   = new GameRepository();
$terminados = escalao_only($gameRepo->finished($teamId, 200), $escalaoAtual);
$proximos   = array_slice(escalao_only($gameRepo->upcoming($teamId, 40), $escalaoAtual), 0, 4);
$registo    = pub_record($terminados);
$jogadores  = escalao_only(panel_players($pdo, $teamId), $escalaoAtual);

$disponiveis   = array_filter($jogadores, fn($j) => $j['status'] === 'active');
$indisponiveis = array_filter($jogadores, fn($j) => $j['status'] !== 'active');

$valor = function (string $sql, array $extra = []) use ($pdo, $teamId) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['team_id' => $teamId] + $extra);
    return $stmt->fetchColumn();
};

$presenca   = (float) $valor('SELECT COALESCE(AVG(a.present) * 100, 0) FROM attendance a JOIN players p ON p.id = a.player_id WHERE p.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual));
$notaMedia  = (float) $valor('SELECT COALESCE(AVG(a.nota_geral), 0) FROM avaliacoes a JOIN players p ON p.id = a.player_id WHERE a.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual));

$proximo = $proximos[0] ?? null;
$convocados = 0;
if ($proximo) {
    $convocados = (int) $valor(
        'SELECT COUNT(*) FROM escalacao e JOIN games g ON g.id = e.game_id WHERE g.team_id = :team_id AND e.game_id = :game_id',
        ['game_id' => (int) $proximo['id']]
    );
}

$stmt = $pdo->prepare(
    "SELECT * FROM trainings WHERE team_id = :team_id AND training_date >= CURDATE() AND status = 'scheduled'" . escalao_sql('escalao', $escalaoAtual) . "
     ORDER BY training_date, start_time LIMIT 4"
);
$stmt->execute(['team_id' => $teamId]);
$treinos = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM comunicados WHERE team_id = :team_id ORDER BY fixado DESC, created_at DESC LIMIT 4");
$stmt->execute(['team_id' => $teamId]);
$comunicados = $stmt->fetchAll();

$marcadores = array_filter($jogadores, fn($j) => (int) $j['games_played'] > 0);
usort($marcadores, fn($a, $b) => (float) $b['avg_pts'] <=> (float) $a['avg_pts']);
$marcadores = array_slice($marcadores, 0, 5);
$maxPts     = max(0.1, (float) ($marcadores[0]['avg_pts'] ?? 0));

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Olá, ' . ($user['name'] ?? 'Treinador'), $team['name'] . ' · ' . date('d/m/Y'),
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="treinos.php"><i class="fa-solid fa-dumbbell"></i> Agendar treino</a>'
        . '<a class="lp-btn lp-btn--primary lp-btn--sm" href="escalacao.php' . ($proximo ? '?game_id=' . (int) $proximo['id'] : '') . '"><i class="fa-solid fa-list-ol"></i> Fazer convocatória</a>'); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section class="lp-kpis" aria-label="Indicadores">
        <a class="lp-card lp-kpi lp-kpi--up" href="plantel.php"><b><?= count($disponiveis) ?><small style="font-size:18px;color:var(--muted);"> / <?= count($jogadores) ?></small></b><span>Jogadores disponíveis</span></a>
        <a class="lp-card lp-kpi lp-kpi--accent" href="jogos.php"><b><?= $registo['vitorias'] ?>–<?= $registo['derrotas'] ?></b><span>Vitórias–Derrotas</span></a>
        <a class="lp-card lp-kpi" href="estatisticas.php"><b><?= $registo['media_marcados'] ?></b><span>Pontos por jogo</span></a>
        <a class="lp-card lp-kpi" href="presencas.php"><b><?= number_format($presenca, 0) ?>%</b><span>Presença nos treinos</span></a>
        <a class="lp-card lp-kpi" href="avaliacoes.php"><b><?= $notaMedia > 0 ? number_format($notaMedia, 0) : '—' ?></b><span>Avaliação média (0–100)</span></a>
    </section>

    <div class="pn-cols">
        <div class="lp-stack" style="gap:24px;">
            <?php if ($proximo): ?>
            <section class="lp-card lp-pad" style="border-color:var(--accent);">
                <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <span class="lp-eyebrow">Próximo jogo</span>
                        <div style="font-family:var(--display);font-size:32px;font-weight:800;color:var(--text);line-height:1.1;">vs <?= pub_e($proximo['opponent']) ?></div>
                        <div class="lp-game-meta" style="margin-top:10px;">
                            <span><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($proximo['game_date'])) ?> <?= pub_e(pub_time($proximo['game_time'])) ?></span>
                            <span><i class="fa-solid fa-location-dot"></i><?= pub_e($proximo['location'] ?: 'Local a definir') ?></span>
                            <span><i class="fa-solid fa-<?= ($proximo['home_away'] ?? 'home') === 'away' ? 'plane' : 'house' ?>"></i><?= ($proximo['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></span>
                        </div>
                    </div>
                    <span class="lp-badge lp-badge--<?= ($proximo['escalacao_status'] ?? '') === 'confirmada' ? 'win' : ($convocados ? 'gold' : 'loss') ?>">
                        <?= ($proximo['escalacao_status'] ?? '') === 'confirmada' ? 'Convocatória confirmada' : ($convocados ? 'Convocatória em rascunho' : 'Sem convocatória') ?>
                    </span>
                </div>
                <div class="lp-chips" style="margin-top:18px;">
                    <a class="lp-btn lp-btn--primary lp-btn--sm" href="escalacao.php?game_id=<?= (int) $proximo['id'] ?>"><i class="fa-solid fa-list-ol"></i> Convocatória (<?= $convocados ?>)</a>
                    <a class="lp-btn lp-btn--ghost lp-btn--sm" href="presencas.php?jogo_id=<?= (int) $proximo['id'] ?>"><i class="fa-solid fa-clipboard-check"></i> Presenças</a>
                </div>
            </section>
            <?php endif; ?>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-dumbbell"></i>Próximos treinos</h2><a href="treinos.php">Gerir treinos</a></div>
                <?php if ($treinos): ?>
                <div class="pn-list">
                    <?php foreach ($treinos as $t): $ts = strtotime($t['training_date']); ?>
                    <a href="presencas.php?treino_id=<?= (int) $t['id'] ?>">
                        <div class="lp-date" style="width:46px;"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                        <div class="grow"><b><?= pub_e($t['name']) ?></b><small><?= pub_e(pub_time($t['start_time'] ?: $t['training_time'])) ?> · <?= pub_e($t['objective'] ?: ($t['location'] ?: '—')) ?></small></div>
                        <span class="lp-badge lp-badge--accent"><?= pub_e(pn_training_type($t['type'])) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-dumbbell"></i>Não há treinos agendados.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-check-double"></i>Últimos resultados</h2><a href="jogos.php">Ver todos</a></div>
                <?php if ($terminados): ?>
                <div class="pn-list">
                    <?php foreach (array_slice($terminados, 0, 4) as $jogo):
                        $ts = strtotime($jogo['game_date']);
                        $ganhou = (int) $jogo['our_score'] > (int) $jogo['opponent_score'];
                    ?>
                    <a href="jogos.php?estatisticas=<?= (int) $jogo['id'] ?>">
                        <div class="lp-date" style="width:46px;"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                        <div class="grow"><b>vs <?= pub_e($jogo['opponent']) ?></b><small><?= ($jogo['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></small></div>
                        <span class="lp-score"><?= (int) $jogo['our_score'] ?> &ndash; <?= (int) $jogo['opponent_score'] ?></span>
                        <span class="lp-pill lp-pill--<?= $ganhou ? 'win' : 'loss' ?>"><?= $ganhou ? 'V' : 'D' ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-basketball"></i>Ainda não há jogos terminados.</div><?php endif; ?>
            </section>
        </div>

        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-kit-medical"></i>Indisponíveis</h2><a href="plantel.php">Plantel</a></div>
                <?php if ($indisponiveis): ?>
                <div class="pn-list">
                    <?php foreach ($indisponiveis as $j): [$estado, $tipo] = pub_player_status($j['status']); ?>
                    <div>
                        <?= pn_avatar($j['photo_url'], $j['name']) ?>
                        <div class="grow"><b>#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?></b><small><?= pub_e(pub_position($j['position'])) ?></small></div>
                        <span class="lp-badge lp-badge--<?= $tipo ?>"><?= pub_e($estado) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-circle-check" style="color:var(--green);"></i>Todo o plantel está disponível.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-ranking-star"></i>Melhores marcadores</h2><a href="estatisticas.php">Estatísticas</a></div>
                <?php if ($marcadores): ?>
                <div class="lp-pad"><div class="lp-bars">
                    <?php foreach ($marcadores as $j): ?>
                    <div class="lp-bar-row">
                        <span title="<?= pub_e($j['name']) ?>"><?= pub_e($j['name']) ?></span>
                        <span class="lp-bar"><i style="width:<?= round((float) $j['avg_pts'] / $maxPts * 100) ?>%"></i></span>
                        <b><?= number_format((float) $j['avg_pts'], 1) ?></b>
                    </div>
                    <?php endforeach; ?>
                </div></div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-chart-column"></i>Sem estatísticas registadas.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-bullhorn"></i>Comunicados</h2><a href="comunicados.php">Gerir</a></div>
                <?php if ($comunicados): ?>
                <div class="pn-list">
                    <?php foreach ($comunicados as $c): ?>
                    <div>
                        <div class="grow"><b><?= $c['fixado'] ? '<i class="fa-solid fa-thumbtack" style="color:var(--accent);font-size:11px;margin-right:6px;"></i>' : '' ?><?= pub_e($c['titulo']) ?></b>
                            <small><?= pub_e(pub_date($c['created_at'])) ?> · <?= pub_e(ucfirst((string) $c['categoria'])) ?></small></div>
                        <?php if ($c['status'] !== 'publicado'): ?><span class="lp-badge">Rascunho</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-bullhorn"></i>Ainda não há comunicados.</div><?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
