<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Plantel
 * Mostra os jogadores da equipa escolhida pelo visitante.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$plantel = (new PlayerRepository())->listByTeam($teamId);

// escalão em vista (?escalao=…); por omissão mostram-se todos
$escalaoAtual = escalao_atual($team);
if ($escalaoAtual !== '') {
    $plantel = array_values(array_filter($plantel, fn($p) => ($p['escalao'] ?? 'seniores') === $escalaoAtual));
}

// Idade e altura médias
$alturas = array_filter(array_map(fn($p) => (float) ($p['height'] ?? 0), $plantel));
$idades  = [];
foreach ($plantel as $p) {
    if (!empty($p['birth_date'])) {
        $idades[] = (int) date_diff(date_create($p['birth_date']), date_create('today'))->y;
    }
}
$alturaMedia = $alturas ? number_format(array_sum($alturas) / count($alturas), 2, ',', '') . ' m' : '—';
$idadeMedia  = $idades ? (string) round(array_sum($idades) / count($idades)) : '—';

$posicoes = ['PG', 'SG', 'SF', 'PF', 'C'];
$porPosicao = array_count_values(array_column($plantel, 'position'));

$pageTitle = 'Jogadores';
$pageIcon  = 'fa-solid fa-user-group';
$activeNav = 'jogadores';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Plantel', 'Conheça os atletas que representam a equipa nesta temporada.', [
        [count($plantel), 'Jogadores'],
        [$alturaMedia, 'Altura média'],
        [$idadeMedia, 'Idade média'],
    ]); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" data-search placeholder="Procurar jogador..." aria-label="Procurar jogador">
            </div>
            <div class="lp-chips" role="group" aria-label="Filtrar por posição">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos <small><?= count($plantel) ?></small></button>
                <?php foreach ($posicoes as $pos): if (empty($porPosicao[$pos])) continue; ?>
                <button type="button" class="lp-chip" data-filter-value="<?= $pos ?>" aria-pressed="false"><?= pub_e(pub_position($pos)) ?> <small><?= (int) $porPosicao[$pos] ?></small></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="lp-grid">
            <?php foreach ($plantel as $p):
                $foto = pub_img($p['photo_url'] ?? null, $basePath);
                [$estado, $estadoTipo] = pub_player_status($p['status'] ?? 'active');
                $idade = !empty($p['birth_date']) ? (int) date_diff(date_create($p['birth_date']), date_create('today'))->y : null;
            ?>
            <article class="lp-card lp-player" data-filter-item data-tags="<?= pub_e($p['position']) ?>" data-name="<?= pub_e(mb_strtolower((string) $p['name'])) ?>">
                <div class="lp-player-photo">
                    <?php if ($foto): ?>
                        <img src="<?= pub_e($foto) ?>" alt="<?= pub_e($p['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="lp-initial"><?= pub_initial($p['name']) ?></div>
                    <?php endif; ?>
                    <span class="lp-player-num">#<?= (int) $p['jersey_number'] ?></span>
                    <?php if (!empty($p['is_captain'])): ?>
                        <span class="lp-badge lp-badge--gold"><i class="fa-solid fa-star"></i> Capitão</span>
                    <?php elseif (($p['status'] ?? 'active') !== 'active'): ?>
                        <span class="lp-badge lp-badge--<?= $estadoTipo ?>"><?= pub_e($estado) ?></span>
                    <?php endif; ?>
                </div>
                <div class="lp-player-body">
                    <h2 class="lp-player-name"><?= pub_e($p['name']) ?></h2>
                    <div class="lp-player-pos"><?= pub_e(pub_position($p['position'])) ?></div>
                    <div class="lp-player-meta">
                        <?php if (!empty($p['height'])): ?><span><i class="fa-solid fa-ruler-vertical"></i><?= number_format((float) $p['height'], 2, ',', '') ?> m</span><?php endif; ?>
                        <?php if ($idade !== null): ?><span><i class="fa-solid fa-cake-candles"></i><?= $idade ?> anos</span><?php endif; ?>
                    </div>
                    <div class="lp-mini-stats">
                        <div><b><?= number_format((float) $p['avg_pts'], 1) ?></b><span>PTS</span></div>
                        <div><b><?= number_format((float) $p['avg_reb'], 1) ?></b><span>REB</span></div>
                        <div><b><?= number_format((float) $p['avg_ast'], 1) ?></b><span>AST</span></div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>

            <p class="lp-empty" data-filter-empty <?= $plantel ? 'hidden' : '' ?>>
                <?= $plantel ? 'Nenhum jogador corresponde à pesquisa.' : 'Esta equipa ainda não tem jogadores registados.' ?>
            </p>
        </div>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
