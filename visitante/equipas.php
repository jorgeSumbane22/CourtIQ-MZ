<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Equipas
 * As equipas da liga. Escolher uma equipa muda o site público inteiro para ela.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team(false);   // página da liga: não precisa de clube escolhido
$basePath = '../';

$equipas = Database::getConnection()->query(
    "SELECT t.id, t.name, t.city, t.stadium, t.founded_year, t.logo_url,
            (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
            (SELECT COALESCE(u.name, c.name) FROM coaches c LEFT JOIN users u ON u.id = c.user_id
              WHERE c.team_id = t.id ORDER BY c.id LIMIT 1) AS treinador,
            COALESCE(SUM(g.our_score > g.opponent_score), 0) AS vitorias,
            COALESCE(SUM(g.our_score < g.opponent_score), 0) AS derrotas
     FROM teams t
     LEFT JOIN games g ON g.team_id = t.id AND g.status = 'finished'
     WHERE t.status = 'active'
     GROUP BY t.id
     ORDER BY t.name"
)->fetchAll();

$totalJogadores = array_sum(array_map('intval', array_column($equipas, 'jogadores')));

$pageTitle = 'Equipas';
$pageIcon  = 'fa-solid fa-building-shield';
$activeNav = 'equipas';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head('Liga Nacional · ' . date('Y'), 'Equipas', 'Escolha uma equipa para ver o plantel, os jogos e as notícias dela.', [
        [count($equipas), 'Equipas'],
        [$totalJogadores, 'Atletas'],
    ]); ?>

    <section class="lp-grid lp-grid--wide">
        <?php foreach ($equipas as $eq):
            $atual = (int) $eq['id'] === $teamId;
            $logo  = pub_img($eq['logo_url'], $basePath);
        ?>
        <article class="lp-card lp-team-card<?= $atual ? ' is-current' : '' ?>">
            <div class="lp-team-top">
                <div class="lp-crest">
                    <?php if ($logo): ?><img src="<?= pub_e($logo) ?>" alt=""><?php else: ?><?= pub_initial($eq['name']) ?><?php endif; ?>
                </div>
                <div>
                    <h2 style="font-family:var(--display);font-size:24px;font-weight:800;color:var(--text);line-height:1.1;"><?= pub_e($eq['name']) ?></h2>
                    <small><?= pub_e($eq['city']) ?><?= $eq['founded_year'] ? ' · desde ' . pub_e($eq['founded_year']) : '' ?></small>
                </div>
                <?php if ($atual): ?><span class="lp-badge lp-badge--accent" style="margin-left:auto;">A ver</span><?php endif; ?>
            </div>

            <div class="lp-mini-stats" style="margin-top:0;">
                <div><b><?= (int) $eq['vitorias'] ?>–<?= (int) $eq['derrotas'] ?></b><span>V–D</span></div>
                <div><b><?= (int) $eq['jogadores'] ?></b><span>ATLETAS</span></div>
                <div><b><?= $eq['founded_year'] ? (int) date('Y') - (int) $eq['founded_year'] : '—' ?></b><span>ANOS</span></div>
            </div>

            <ul class="lp-info" style="gap:10px;">
                <li><span class="lp-info-icon"><i class="fa-solid fa-location-dot"></i></span><div><small>Pavilhão</small><b><?= pub_e($eq['stadium'] ?: '—') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-chalkboard-user"></i></span><div><small>Treinador principal</small><b><?= pub_e($eq['treinador'] ?: '—') ?></b></div></li>
            </ul>

            <a class="lp-btn <?= $atual ? 'lp-btn--ghost' : 'lp-btn--primary' ?> lp-btn--block" href="<?= pub_e($basePath) ?>index.php?equipa=<?= (int) $eq['id'] ?>">
                <?= $atual ? 'Ir para a página inicial' : 'Ver esta equipa' ?> <i class="fa-solid fa-arrow-right"></i>
            </a>
        </article>
        <?php endforeach; ?>

        <?php if (!$equipas): ?><p class="lp-empty">Ainda não há equipas registadas.</p><?php endif; ?>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
