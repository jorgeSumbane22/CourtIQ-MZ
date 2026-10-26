<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Calendário
 * Jogos e treinos da equipa, mês a mês, por ordem cronológica.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

// só os jogos e treinos do escalão do jogador (sem ficha de jogador, vê os de todos)
$player     = panel_player($pdo, $teamId, (int) $user['id']);
$meuEscalao = (string) ($player['escalao'] ?? '');

$stmt = $pdo->prepare(
    "SELECT 'game' AS tipo, id, CONCAT('Jogo vs ', opponent) AS titulo, game_date AS data, game_time AS hora, location, status,
            IF(home_away = 'away', 'Fora', 'Casa') AS detalhe, our_score, opponent_score
     FROM games WHERE team_id = :t1" . escalao_sql('escalao', $meuEscalao) . "
     UNION ALL
     SELECT 'training', id, name, training_date, COALESCE(start_time, training_time), location, status, type, NULL, NULL
     FROM trainings WHERE team_id = :t2" . escalao_sql('escalao', $meuEscalao) . "
     ORDER BY data, hora"
);
$stmt->execute(['t1' => $teamId, 't2' => $teamId]);
$eventos = $stmt->fetchAll();

$hoje  = date('Y-m-d');
$meses = [];
foreach ($eventos as $e) {
    $meses[substr((string) $e['data'], 0, 7)][] = $e;
}
$futuros = array_filter($eventos, fn($e) => $e['data'] >= $hoje && $e['status'] === 'scheduled');
$proximo = $futuros ? reset($futuros) : null;

$pageTitle = 'Calendário';
$activeNav = 'calendario';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Calendário', $proximo
        ? 'A seguir: ' . $proximo['titulo'] . ' em ' . pub_date($proximo['data']) . ' ' . pub_time($proximo['hora']) . '.'
        : 'Jogos e treinos de ' . $team['name'] . '.'); ?>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-chips" role="group" aria-label="Filtrar calendário">
                <button type="button" class="lp-chip is-active" data-filter-value="futuro" aria-pressed="true">A seguir <small><?= count($futuros) ?></small></button>
                <button type="button" class="lp-chip" data-filter-value="" aria-pressed="false">Tudo</button>
                <button type="button" class="lp-chip" data-filter-value="game" aria-pressed="false">Jogos</button>
                <button type="button" class="lp-chip" data-filter-value="training" aria-pressed="false">Treinos</button>
            </div>
        </div>

        <div class="lp-stack">
            <?php foreach ($meses as $anoMes => $lista): [$ano, $mes] = explode('-', $anoMes); ?>
            <div data-filter-group class="lp-stack">
                <h2 class="lp-month"><?= pub_e(pub_month((int) $mes, false)) ?> <?= pub_e($ano) ?></h2>
                <?php foreach ($lista as $e):
                    $ts      = strtotime($e['data']);
                    $jogo    = $e['tipo'] === 'game';
                    $futuro  = $e['data'] >= $hoje && $e['status'] === 'scheduled';
                    $acabado = $e['status'] === 'finished';
                    [$rotulo, $tipo] = pn_event_status($e['status']);
                ?>
                <article class="lp-card lp-game" data-filter-item data-tags="<?= $e['tipo'] ?><?= $futuro ? ' futuro' : '' ?>"<?= $e['data'] === $hoje ? ' style="border-color:var(--accent);"' : '' ?>>
                    <div class="lp-date"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                    <div style="min-width:0;">
                        <div class="lp-game-teams"><i class="fa-solid fa-<?= $jogo ? 'trophy' : 'dumbbell' ?>" style="color:var(--accent);font-size:14px;"></i><?= pub_e($e['titulo']) ?></div>
                        <div class="lp-game-meta">
                            <?php if ($e['hora']): ?><span><i class="fa-solid fa-clock"></i><?= pub_e(pub_time($e['hora'])) ?></span><?php endif; ?>
                            <span><i class="fa-solid fa-location-dot"></i><?= pub_e($e['location'] ?: 'Local a definir') ?></span>
                            <span><i class="fa-solid fa-tag"></i><?= pub_e($jogo ? $e['detalhe'] : pn_training_type($e['detalhe'])) ?></span>
                        </div>
                    </div>
                    <div class="lp-game-side">
                        <?php if ($jogo && $acabado && $e['our_score'] !== null): $ganhou = (int) $e['our_score'] > (int) $e['opponent_score']; ?>
                            <span class="lp-score"><?= (int) $e['our_score'] ?> &ndash; <?= (int) $e['opponent_score'] ?></span>
                            <span class="lp-pill lp-pill--<?= $ganhou ? 'win' : 'loss' ?>"><?= $ganhou ? 'V' : 'D' ?></span>
                        <?php elseif ($e['data'] === $hoje && $futuro): ?>
                            <span class="lp-badge lp-badge--accent">Hoje</span>
                        <?php else: ?>
                            <span class="lp-badge lp-badge--<?= $tipo ?>"><?= $rotulo ?></span>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <p class="lp-empty" data-filter-empty <?= $eventos ? 'hidden' : '' ?>><?= $eventos ? 'Não há eventos neste filtro.' : 'Ainda não há jogos nem treinos no calendário.' ?></p>
        </div>
    </section>
</div>

<?php require panel_layout('bottom'); ?>
