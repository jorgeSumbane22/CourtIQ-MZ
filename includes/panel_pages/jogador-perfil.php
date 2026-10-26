<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Ficha do jogador
 * Só abre jogadores da equipa do administrador.
 */


[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$playerId = (int) ($_GET['id'] ?? 0);
$jogador  = null;
foreach (panel_players($pdo, $teamId) as $candidato) {   // a lista já só tem jogadores desta equipa
    if ((int) $candidato['id'] === $playerId) {
        $jogador = $candidato;
        break;
    }
}
if ($jogador && !escalao_allowed($team, (string) ($jogador['escalao'] ?? 'seniores'))) {
    $jogador = null;   // é de um escalão que este treinador não treina
}
if (!$jogador) {
    flash_set('error', 'Jogador não encontrado.');
    panel_redirect(panel_url('jogadores.php'));
}

// Jogo a jogo
$stmt = $pdo->prepare(
    'SELECT g.game_date, g.opponent, g.our_score, g.opponent_score, s.*
     FROM game_player_stats s
     JOIN games g ON g.id = s.game_id
     WHERE s.player_id = :player_id AND g.team_id = :team_id
     ORDER BY g.game_date DESC'
);
$stmt->execute(['player_id' => $playerId, 'team_id' => $teamId]);
$jogos = $stmt->fetchAll();

// Presenças
$stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(present), 0) AS presentes FROM attendance WHERE player_id = :player_id');
$stmt->execute(['player_id' => $playerId]);
$presencas = $stmt->fetch();
$taxaPresenca = (int) $presencas['total'] ? round((int) $presencas['presentes'] / (int) $presencas['total'] * 100) : null;

// Última avaliação do treinador
$stmt = $pdo->prepare('SELECT * FROM avaliacoes WHERE player_id = :player_id AND team_id = :team_id ORDER BY data_avaliacao DESC, id DESC LIMIT 1');
$stmt->execute(['player_id' => $playerId, 'team_id' => $teamId]);
$avaliacao = $stmt->fetch() ?: null;

$idade = !empty($jogador['birth_date']) ? (int) date_diff(date_create($jogador['birth_date']), date_create('today'))->y : null;
[$estado, $estadoTipo] = pub_player_status($jogador['status']);
$melhorJogo = $jogos ? max(array_map(fn($j) => (int) $j['points'], $jogos)) : 0;

$pageTitle = 'Ficha do jogador';
$activeNav = panel_name() === 'treinador' ? 'plantel' : 'jogadores';
require panel_layout('top');
?>

<div class="lp pn">
    <a class="lp-back" href="<?= panel_url('jogadores.php') ?>"><i class="fa-solid fa-arrow-left"></i> Voltar ao plantel</a>

    <section class="lp-page-head">
        <div style="display:flex;align-items:center;gap:22px;flex-wrap:wrap;">
            <?= pn_avatar($jogador['photo_url'], $jogador['name'], '../', 'lg') ?>
            <div>
                <span class="lp-hero-tag">#<?= (int) $jogador['jersey_number'] ?> &bull; <?= pub_e(pub_position($jogador['position'])) ?></span>
                <h1><?= pub_e($jogador['name']) ?></h1>
                <div class="lp-chips">
                    <span class="lp-badge lp-badge--<?= $estadoTipo ?>"><?= pub_e($estado) ?></span>
                    <?php if ($jogador['is_captain']): ?><span class="lp-badge lp-badge--gold"><i class="fa-solid fa-star"></i> Capitão</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="lp-hero-record">
            <div><strong><?= (int) $jogador['games_played'] ?></strong><span>Jogos</span></div>
            <div><strong><?= number_format((float) $jogador['avg_pts'], 1) ?></strong><span>Pontos por jogo</span></div>
            <div><strong><?= $taxaPresenca !== null ? $taxaPresenca . '%' : '—' ?></strong><span>Presenças</span></div>
        </div>
    </section>

    <div class="pn-cols">
        <div class="lp-stack" style="gap:24px;">
            <section class="lp-kpis" aria-label="Médias por jogo">
                <div class="lp-card lp-kpi lp-kpi--accent"><b><?= number_format((float) $jogador['avg_pts'], 1) ?></b><span>Pontos</span></div>
                <div class="lp-card lp-kpi"><b><?= number_format((float) $jogador['avg_reb'], 1) ?></b><span>Ressaltos</span></div>
                <div class="lp-card lp-kpi"><b><?= number_format((float) $jogador['avg_ast'], 1) ?></b><span>Assistências</span></div>
                <div class="lp-card lp-kpi"><b><?= number_format((float) $jogador['avg_stl'], 1) ?></b><span>Roubos de bola</span></div>
                <div class="lp-card lp-kpi"><b><?= number_format((float) $jogador['avg_min'], 0) ?></b><span>Minutos</span></div>
                <div class="lp-card lp-kpi"><b><?= $melhorJogo ?></b><span>Melhor jogo (pts)</span></div>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-list-ol"></i>Jogo a jogo</h2><small><?= count($jogos) ?> jogos</small></div>
                <?php if ($jogos): ?>
                <div class="lp-table-wrap">
                    <table class="lp-table pn-table">
                        <thead><tr>
                            <th scope="col">Data</th><th scope="col">Adversário</th><th scope="col">Resultado</th>
                            <th scope="col" class="num">MIN</th><th scope="col" class="num">PTS</th><th scope="col" class="num">REB</th>
                            <th scope="col" class="num">AST</th><th scope="col" class="num">STL</th><th scope="col" class="num">BLK</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($jogos as $j): $ganhou = (int) $j['our_score'] > (int) $j['opponent_score']; ?>
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
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="pn-empty"><i class="fa-solid fa-basketball"></i>Este jogador ainda não tem estatísticas de jogo.</div>
                <?php endif; ?>
            </section>
        </div>

        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card lp-pad">
                <h2 class="lp-sub">Dados pessoais</h2>
                <ul class="lp-info">
                    <li><span class="lp-info-icon"><i class="fa-solid fa-cake-candles"></i></span><div><small>Nascimento</small><b><?= pub_e(pub_date($jogador['birth_date'])) ?><?= $idade !== null ? ' (' . $idade . ' anos)' : '' ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-ruler-vertical"></i></span><div><small>Altura e peso</small><b><?= $jogador['height'] ? number_format((float) $jogador['height'], 2, ',', '') . ' m' : '—' ?> &bull; <?= $jogador['weight'] ? number_format((float) $jogador['weight'], 0) . ' kg' : '—' ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-user-lock"></i></span><div><small>Conta de acesso</small><b><?= pub_e($jogador['username'] ?: 'Sem conta') ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-envelope"></i></span><div><small>Email</small><b><?= pub_e($jogador['email'] ?: '—') ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-phone"></i></span><div><small>Telefone</small><b><?= pub_e($jogador['phone'] ?: '—') ?></b></div></li>
                </ul>
            </section>

            <section class="lp-card lp-pad">
                <h2 class="lp-sub">Última avaliação do treinador</h2>
                <?php if ($avaliacao): ?>
                    <div style="display:flex;align-items:baseline;gap:10px;">
                        <b style="font-family:var(--display);font-size:40px;font-weight:800;color:var(--accent-text);line-height:1;"><?= number_format((float) $avaliacao['nota_geral'], 0) ?></b>
                        <span style="color:var(--muted);font-size:13px;">/ 100 &bull; <?= pub_e(pub_date($avaliacao['data_avaliacao'])) ?></span>
                    </div>
                    <div class="lp-bars" style="margin-top:16px;">
                        <?php foreach (['passe' => 'Passe', 'drible' => 'Drible', 'lancamento' => 'Lançamento', 'resistencia' => 'Resistência', 'atitude' => 'Atitude'] as $campo => $rotulo):
                            if ((float) $avaliacao[$campo] <= 0) continue; ?>
                        <div class="lp-bar-row"><span><?= $rotulo ?></span><span class="lp-bar"><i style="width:<?= min(100, (int) $avaliacao[$campo]) ?>%"></i></span><b><?= (int) $avaliacao[$campo] ?></b></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($avaliacao['feedback']) || !empty($avaliacao['comentario'])): ?>
                        <p style="margin-top:16px;font-size:14px;color:var(--text-secondary);line-height:1.6;"><?= pub_e($avaliacao['feedback'] ?: $avaliacao['comentario']) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color:var(--muted);font-size:14px;">O treinador ainda não avaliou este jogador.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
