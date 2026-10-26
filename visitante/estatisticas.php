<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Estatísticas
 * Números da equipa escolhida: médias, líderes por categoria e tabela completa de jogadores.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$escalaoAtual = escalao_atual($team);   // escalão em vista (?escalao=…); por omissão mostram-se todos
$jogos   = escalao_only((new GameRepository())->finished($teamId, 200), $escalaoAtual);
$registo = pub_record($jogos);

// Médias por jogador (só jogadores desta equipa)
$jogadores = [];
try {
    $stmt = Database::getConnection()->prepare(
        'SELECT p.id, COALESCE(u.name, p.name) AS name, p.position, p.jersey_number,
                COUNT(s.id)               AS jogos,
                COALESCE(AVG(s.minutes_played), 0) AS min,
                COALESCE(AVG(s.points), 0)   AS pts,
                COALESCE(AVG(s.rebounds), 0) AS reb,
                COALESCE(AVG(s.assists), 0)  AS ast,
                COALESCE(AVG(s.steals), 0)   AS stl,
                COALESCE(AVG(s.blocks), 0)   AS blk,
                COALESCE(AVG(s.efficiency), 0) AS eff
         FROM players p
         LEFT JOIN users u ON u.id = p.user_id
         LEFT JOIN game_player_stats s ON s.player_id = p.id
         WHERE p.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual) . '
         GROUP BY p.id
         ORDER BY pts DESC'
    );
    $stmt->execute(['team_id' => $teamId]);
    $jogadores = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[CourtIQ] Erro ao carregar estatísticas: ' . $e->getMessage());
}

$comJogos = array_values(array_filter($jogadores, fn($j) => (int) $j['jogos'] > 0));

/** Os 5 melhores numa categoria. */
$lideres = function (string $campo) use ($comJogos): array {
    $lista = $comJogos;
    usort($lista, fn($a, $b) => (float) $b[$campo] <=> (float) $a[$campo]);
    return array_slice($lista, 0, 5);
};

$categorias = [
    'pts' => ['Pontos', ''],
    'reb' => ['Ressaltos', 'lp-bar--blue'],
    'ast' => ['Assistências', 'lp-bar--green'],
];

// Pontos por jogo (do mais antigo para o mais recente)
$porJogo  = array_reverse($jogos);
$maxPontos = 1;
foreach ($porJogo as $j) {
    $maxPontos = max($maxPontos, (int) $j['our_score'], (int) $j['opponent_score']);
}

$saldo = $registo['marcados'] - $registo['sofridos'];

$pageTitle = 'Estatísticas';
$pageIcon  = 'fa-solid fa-chart-column';
$activeNav = 'estatisticas';
require __DIR__ . '/../includes/layout/public_top.php';
if (pub_account_wall('as estatísticas detalhadas', $basePath)) { require __DIR__ . '/../includes/layout/public_bottom.php'; return; }
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Estatísticas', 'Médias por jogo calculadas a partir dos jogos terminados.', [
        [$registo['jogos'], 'Jogos'],
        [$registo['aproveitamento'] . '%', 'Aproveitamento'],
    ]); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <?php if (!$jogos): ?>
        <p class="lp-empty">Ainda não há jogos terminados para calcular estatísticas.</p>
    <?php else: ?>

    <section class="lp-kpis lp-reveal" aria-label="Resumo da equipa">
        <div class="lp-card lp-kpi lp-kpi--up"><b><?= $registo['vitorias'] ?></b><span>Vitórias</span></div>
        <div class="lp-card lp-kpi lp-kpi--down"><b><?= $registo['derrotas'] ?></b><span>Derrotas</span></div>
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $registo['media_marcados'] ?></b><span>Pontos marcados por jogo</span></div>
        <div class="lp-card lp-kpi"><b><?= $registo['media_sofridos'] ?></b><span>Pontos sofridos por jogo</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $saldo >= 0 ? 'up' : 'down' ?>"><b><?= ($saldo > 0 ? '+' : '') . $saldo ?></b><span>Saldo de pontos</span></div>
    </section>

    <section class="lp-reveal">
        <div class="lp-head"><div><span class="lp-eyebrow">Médias por jogo</span><h2>Líderes da equipa</h2></div></div>
        <div class="lp-grid lp-grid--wide">
            <?php foreach ($categorias as $campo => [$titulo, $classe]):
                $top = $lideres($campo);
                $max = max(0.1, (float) ($top[0][$campo] ?? 0));
            ?>
            <div class="lp-card lp-pad">
                <h3 class="lp-sub"><?= pub_e($titulo) ?></h3>
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
        </div>
    </section>

    <section class="lp-reveal">
        <div class="lp-head"><div><span class="lp-eyebrow">Jogo a jogo</span><h2>Pontos marcados e sofridos</h2></div></div>
        <div class="lp-card lp-pad">
            <div class="lp-bars">
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
            </div>
            <p style="margin-top:16px;font-size:13px;color:var(--muted);">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--accent);margin-right:6px;"></span><?= pub_e($team['name']) ?>
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--blue);margin:0 6px 0 16px;"></span>Adversário
            </p>
        </div>
    </section>

    <section class="lp-reveal">
        <div class="lp-head"><div><span class="lp-eyebrow">Plantel</span><h2>Todos os jogadores</h2></div></div>
        <div class="lp-card lp-table-wrap">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Jogador</th>
                        <th scope="col" title="Jogos">J</th>
                        <th scope="col" title="Minutos">MIN</th>
                        <th scope="col" title="Pontos">PTS</th>
                        <th scope="col" title="Ressaltos">REB</th>
                        <th scope="col" title="Assistências">AST</th>
                        <th scope="col" title="Roubos de bola">STL</th>
                        <th scope="col" title="Desarmes de lançamento">BLK</th>
                        <th scope="col" title="Eficiência">EFF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jogadores as $j): ?>
                    <tr>
                        <td><span class="lp-pos"><?= (int) $j['jersey_number'] ?></span></td>
                        <td class="lp-table-team"><?= pub_e($j['name']) ?> <small style="color:var(--muted);font-weight:500;">· <?= pub_e(pub_position($j['position'])) ?></small></td>
                        <td><?= (int) $j['jogos'] ?></td>
                        <td><?= number_format((float) $j['min'], 1) ?></td>
                        <td class="lp-table-pts"><?= number_format((float) $j['pts'], 1) ?></td>
                        <td><?= number_format((float) $j['reb'], 1) ?></td>
                        <td><?= number_format((float) $j['ast'], 1) ?></td>
                        <td><?= number_format((float) $j['stl'], 1) ?></td>
                        <td><?= number_format((float) $j['blk'], 1) ?></td>
                        <td><?= number_format((float) $j['eff'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
