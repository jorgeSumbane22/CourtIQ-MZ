<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Dashboard
 * O essencial para o jogador: próximo jogo e se foi convocado, próximos treinos,
 * as suas médias, a última avaliação e os avisos do treinador.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

if (!$player) {
    require panel_layout('top');
    panel_no_player();
    require panel_layout('bottom');
    exit;
}
$playerId   = (int) $player['id'];
$meuEscalao = (string) ($player['escalao'] ?? 'seniores');   // o jogador só vê jogos e treinos do seu escalão

$gameRepo = new GameRepository();
$proximos = array_slice(escalao_only($gameRepo->upcoming($teamId, 60), $meuEscalao), 0, 3);
$proximo  = $proximos[0] ?? null;

// Fui convocado para o próximo jogo?
$convocatoria = null;
if ($proximo) {
    $stmt = $pdo->prepare('SELECT * FROM escalacao WHERE game_id = :game_id AND player_id = :player_id');
    $stmt->execute(['game_id' => (int) $proximo['id'], 'player_id' => $playerId]);
    $convocatoria = $stmt->fetch() ?: null;
}
$confirmada = $proximo && ($proximo['escalacao_status'] ?? '') === 'confirmada';

$stmt = $pdo->prepare(
    "SELECT * FROM trainings WHERE team_id = :team_id AND training_date >= CURDATE() AND status = 'scheduled'" . escalao_sql('escalao', $meuEscalao) . "
     ORDER BY training_date, start_time LIMIT 4"
);
$stmt->execute(['team_id' => $teamId]);
$treinos = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(present), 0) AS presentes FROM attendance WHERE player_id = :player_id');
$stmt->execute(['player_id' => $playerId]);
$pres = $stmt->fetch();
$taxaPresenca = (int) $pres['total'] ? round((int) $pres['presentes'] / (int) $pres['total'] * 100) : null;

$stmt = $pdo->prepare(
    'SELECT g.game_date, g.opponent, g.our_score, g.opponent_score, s.points, s.rebounds, s.assists
     FROM game_player_stats s JOIN games g ON g.id = s.game_id
     WHERE s.player_id = :player_id AND g.team_id = :team_id
     ORDER BY g.game_date DESC LIMIT 5'
);
$stmt->execute(['player_id' => $playerId, 'team_id' => $teamId]);
$ultimosJogos = $stmt->fetchAll();
$maxPts = max(1, ...array_map(fn($j) => (int) $j['points'], $ultimosJogos ?: [['points' => 1]]));

$stmt = $pdo->prepare('SELECT * FROM avaliacoes WHERE player_id = :player_id AND team_id = :team_id ORDER BY data_avaliacao DESC, id DESC LIMIT 1');
$stmt->execute(['player_id' => $playerId, 'team_id' => $teamId]);
$avaliacao = $stmt->fetch() ?: null;

$stmt = $pdo->prepare("SELECT * FROM comunicados WHERE team_id = :team_id AND status = 'publicado' ORDER BY fixado DESC, COALESCE(data_publicacao, created_at) DESC LIMIT 3");
$stmt->execute(['team_id' => $teamId]);
$comunicados = $stmt->fetchAll();

[$estado, $estadoTipo] = pub_player_status($player['status']);

require panel_layout('top');
?>

<div class="lp pn">
    <section class="lp-page-head">
        <div style="display:flex;align-items:center;gap:22px;flex-wrap:wrap;">
            <?= pn_avatar($player['photo_url'], $player['name'], '../', 'lg') ?>
            <div>
                <span class="lp-hero-tag">#<?= (int) $player['jersey_number'] ?> &bull; <?= pub_e(pub_position($player['position'])) ?> &bull; <?= pub_e($team['name']) ?></span>
                <h1>Olá, <?= pub_e(explode(' ', (string) $player['name'])[0]) ?></h1>
                <div class="lp-chips">
                    <span class="lp-badge lp-badge--<?= $estadoTipo ?>"><?= pub_e($estado) ?></span>
                    <?php if ($player['is_captain']): ?><span class="lp-badge lp-badge--gold"><i class="fa-solid fa-star"></i> Capitão</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="lp-hero-record">
            <div><strong><?= number_format((float) $player['avg_pts'], 1) ?></strong><span>Pontos</span></div>
            <div><strong><?= number_format((float) $player['avg_reb'], 1) ?></strong><span>Ressaltos</span></div>
            <div><strong><?= number_format((float) $player['avg_ast'], 1) ?></strong><span>Assistências</span></div>
            <div><strong><?= $taxaPresenca !== null ? $taxaPresenca . '%' : '—' ?></strong><span>Presenças</span></div>
        </div>
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
                    <?php if ($confirmada && $convocatoria): ?>
                        <span class="lp-badge lp-badge--win"><i class="fa-solid fa-check"></i> Convocado<?= ($convocatoria['is_starter'] || $convocatoria['is_titular']) ? ' · Cinco inicial' : '' ?></span>
                    <?php elseif ($confirmada): ?>
                        <span class="lp-badge lp-badge--loss">Não convocado</span>
                    <?php else: ?>
                        <span class="lp-badge">Convocatória por sair</span>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-dumbbell"></i>Próximos treinos</h2><a href="meus-treinos.php">Ver todos</a></div>
                <?php if ($treinos): ?>
                <div class="pn-list">
                    <?php foreach ($treinos as $t): $ts = strtotime($t['training_date']); ?>
                    <div>
                        <div class="lp-date" style="width:46px;"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                        <div class="grow"><b><?= pub_e($t['name']) ?></b><small><?= pub_e(pub_time($t['start_time'] ?: $t['training_time'])) ?> · <?= pub_e($t['location'] ?: '—') ?><?= $t['objective'] ? ' · ' . pub_e($t['objective']) : '' ?></small></div>
                        <span class="lp-badge lp-badge--accent"><?= pub_e(pn_training_type($t['type'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-dumbbell"></i>Não há treinos agendados.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-chart-column"></i>Os meus últimos jogos</h2><a href="minhas-estatisticas.php">Estatísticas</a></div>
                <?php if ($ultimosJogos): ?>
                <div class="lp-pad"><div class="lp-bars">
                    <?php foreach ($ultimosJogos as $j): ?>
                    <div class="lp-bar-row">
                        <span title="<?= pub_e($j['opponent']) ?>"><?= pub_e(pub_date($j['game_date'], 'd/m')) ?> · <?= pub_e($j['opponent']) ?></span>
                        <span class="lp-bar"><i style="width:<?= round((int) $j['points'] / $maxPts * 100) ?>%"></i></span>
                        <b><?= (int) $j['points'] ?></b>
                    </div>
                    <?php endforeach; ?>
                </div><p class="pn-help">Pontos marcados em cada jogo.</p></div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-basketball"></i>Ainda não tem estatísticas de jogo.</div><?php endif; ?>
            </section>
        </div>

        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card lp-pad">
                <h2 class="lp-sub">Última avaliação do treinador</h2>
                <?php if ($avaliacao): ?>
                    <div style="display:flex;align-items:baseline;gap:10px;">
                        <b style="font-family:var(--display);font-size:44px;font-weight:800;color:var(--accent-text);line-height:1;"><?= number_format((float) $avaliacao['nota_geral'], 0) ?></b>
                        <span style="color:var(--muted);font-size:13px;">/ 100 &bull; <?= pub_e(pub_date($avaliacao['data_avaliacao'])) ?></span>
                    </div>
                    <?php if (!empty($avaliacao['feedback']) || !empty($avaliacao['comentario'])): ?>
                        <p style="margin-top:12px;font-size:14px;color:var(--text-secondary);line-height:1.6;"><?= pub_e($avaliacao['feedback'] ?: $avaliacao['comentario']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($avaliacao['objetivos'])): ?>
                        <p style="margin-top:10px;font-size:14px;color:var(--text-secondary);line-height:1.6;"><b style="color:var(--text);">A trabalhar:</b> <?= pub_e($avaliacao['objetivos']) ?></p>
                    <?php endif; ?>
                    <a class="lp-news-link" href="meu-perfil.php#avaliacoes" style="display:inline-block;margin-top:12px;">Ver detalhe <i class="fa-solid fa-arrow-right"></i></a>
                <?php else: ?>
                    <p style="color:var(--muted);font-size:14px;">O treinador ainda não registou nenhuma avaliação.</p>
                <?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-bullhorn"></i>Comunicados</h2><a href="notificacoes.php#comunicados">Ver todos</a></div>
                <?php if ($comunicados): ?>
                <div class="pn-list">
                    <?php foreach ($comunicados as $c): ?>
                    <a href="notificacoes.php#comunicados" style="align-items:flex-start;">
                        <div class="grow">
                            <b style="white-space:normal;"><?= $c['fixado'] ? '<i class="fa-solid fa-thumbtack" style="color:var(--accent);font-size:11px;margin-right:6px;"></i>' : '' ?><?= pub_e($c['titulo']) ?></b>
                            <small><?= pub_e(pub_date($c['data_publicacao'] ?: $c['created_at'])) ?> · <?= pub_e(ucfirst((string) $c['categoria'])) ?></small>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-bullhorn"></i>Sem comunicados.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-trophy"></i>Jogos seguintes</h2><a href="calendario.php">Calendário</a></div>
                <?php if (count($proximos) > 1): ?>
                <div class="pn-list">
                    <?php foreach (array_slice($proximos, 1) as $jogo): ?>
                    <div><div class="grow"><b>vs <?= pub_e($jogo['opponent']) ?></b><small><?= pub_e(pub_date($jogo['game_date'])) ?> <?= pub_e(pub_time($jogo['game_time'])) ?> · <?= ($jogo['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></small></div></div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-calendar"></i>Sem mais jogos agendados.</div><?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
