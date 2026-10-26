<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Jogos
 * Todos os jogos da equipa escolhida: primeiro os que faltam disputar, depois os terminados.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$gameRepo   = new GameRepository();
$escalaoAtual = escalao_atual($team);              // escalão em vista (?escalao=…); por omissão mostram-se todos
$proximos   = escalao_only($gameRepo->upcoming($teamId, 100), $escalaoAtual);   // do mais próximo para o mais distante
$terminados = escalao_only($gameRepo->finished($teamId, 200), $escalaoAtual);   // do mais recente para o mais antigo
$registo    = pub_record($terminados);

$pageTitle = 'Jogos';
$pageIcon  = 'fa-solid fa-trophy';
$activeNav = 'jogos';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Jogos', 'Próximos desafios e resultados da temporada.', [
        [count($proximos), 'Por disputar'],
        [$registo['vitorias'] . '–' . $registo['derrotas'], 'Vitórias–Derrotas'],
        [$registo['aproveitamento'] . '%', 'Aproveitamento'],
    ]); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" data-search placeholder="Procurar adversário..." aria-label="Procurar adversário">
            </div>
            <div class="lp-chips" role="group" aria-label="Filtrar jogos">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos</button>
                <button type="button" class="lp-chip" data-filter-value="upcoming" aria-pressed="false">Próximos</button>
                <button type="button" class="lp-chip" data-filter-value="finished" aria-pressed="false">Terminados</button>
                <button type="button" class="lp-chip" data-filter-value="home" aria-pressed="false">Em casa</button>
                <button type="button" class="lp-chip" data-filter-value="away" aria-pressed="false">Fora</button>
            </div>
        </div>

        <div class="lp-stack">
            <?php if ($proximos): ?>
            <div data-filter-group class="lp-stack">
                <h2 class="lp-month">Próximos jogos</h2>
                <?php foreach ($proximos as $jogo) pub_game_row($jogo, $team['name'], $basePath, ['tickets' => true]); ?>
            </div>
            <?php endif; ?>

            <?php if ($terminados): ?>
            <div data-filter-group class="lp-stack">
                <h2 class="lp-month">Resultados</h2>
                <?php foreach ($terminados as $jogo) pub_game_row($jogo, $team['name'], $basePath); ?>
            </div>
            <?php endif; ?>

            <p class="lp-empty" data-filter-empty <?= ($proximos || $terminados) ? 'hidden' : '' ?>>
                <?= ($proximos || $terminados) ? 'Nenhum jogo corresponde ao filtro.' : 'Esta equipa ainda não tem jogos registados.' ?>
            </p>
        </div>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
