<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: entrada da liga
 * É aqui que o visitante chega: vê a liga (clubes, classificação, resultados e próximos jogos)
 * e escolhe o clube cujos dados quer ver. Enquanto não escolher, as páginas de clube trazem-no de volta aqui.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team(false);
$basePath = '../';
$pdo = Database::getConnection();

$clubes = $pdo->query(
    "SELECT t.id, t.name, t.city, t.stadium, t.founded_year, t.logo_url,
            (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
            (SELECT COUNT(*) FROM games g WHERE g.team_id = t.id AND g.status = 'finished') AS jogos,
            (SELECT COALESCE(SUM(g.our_score > g.opponent_score), 0) FROM games g WHERE g.team_id = t.id AND g.status = 'finished' AND g.escalao = 'seniores') AS vitorias,
            (SELECT COALESCE(SUM(g.our_score < g.opponent_score), 0) FROM games g WHERE g.team_id = t.id AND g.status = 'finished' AND g.escalao = 'seniores') AS derrotas
     FROM teams t
     WHERE t.status = 'active'
     ORDER BY t.name"
)->fetchAll();

// os jogos estão guardados duas vezes (um registo por clube): aqui conta-se cada jogo uma vez, pelo registo da equipa da casa
$jogosDaLiga = function (string $estado, string $ordem, int $limite) use ($pdo): array {
    $data = $estado === 'finished' ? '' : ' AND g.game_date >= CURDATE()';
    return $pdo->query(
        "SELECT g.*, t.name AS casa
         FROM games g JOIN teams t ON t.id = g.team_id AND t.status = 'active'
         WHERE g.home_away = 'home' AND g.status = '$estado'$data
         ORDER BY g.game_date $ordem, g.game_time $ordem LIMIT $limite"
    )->fetchAll();
};
$resultados = $jogosDaLiga('finished', 'DESC', 6);
$proximos   = $jogosDaLiga('scheduled', 'ASC', 6);
$tabela     = array_slice((new TeamRepository())->standings('seniores'), 0, 5);

$totalAtletas = array_sum(array_map('intval', array_column($clubes, 'jogadores')));
$totalJogos   = (int) $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'finished' AND home_away = 'home'")->fetchColumn();

/** Uma linha de jogo da liga: casa contra fora, com o resultado ou a hora. */
$linhaJogo = function (array $g): void {
    $ts = strtotime((string) $g['game_date']);
    $terminado = $g['status'] === 'finished' && $g['our_score'] !== null;
    $casaGanhou = $terminado && (int) $g['our_score'] > (int) $g['opponent_score'];
    ?>
    <article class="lp-card lp-game">
        <div class="lp-date"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
        <div style="min-width:0;">
            <div class="lp-game-teams">
                <span<?= $casaGanhou ? ' class="is-us"' : '' ?>><?= pub_e($g['casa']) ?></span><small>vs</small><span<?= $terminado && !$casaGanhou ? ' class="is-us"' : '' ?>><?= pub_e($g['opponent']) ?></span>
            </div>
            <div class="lp-game-meta">
                <?php if (!$terminado && !empty($g['game_time'])): ?><span><i class="fa-solid fa-clock"></i><?= pub_e(pub_time($g['game_time'])) ?></span><?php endif; ?>
                <span><i class="fa-solid fa-location-dot"></i><?= pub_e($g['location'] ?: 'Local a definir') ?></span>
                <span><i class="fa-solid fa-layer-group"></i><?= pub_e(escalao_label($g['escalao'])) ?></span>
            </div>
        </div>
        <div class="lp-game-side">
            <?php if ($terminado): ?><span class="lp-score"><?= (int) $g['our_score'] ?> &ndash; <?= (int) $g['opponent_score'] ?></span>
            <?php else: ?><span class="lp-badge lp-badge--info">Agendado</span><?php endif; ?>
        </div>
    </article>
    <?php
};

$pageTitle = 'Liga';
$pageIcon  = 'fa-solid fa-basketball';
$activeNav = 'liga-inicio';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head('Liga Nacional · ' . date('Y'), 'Escolha o seu clube', 'Bem-vindo ao CourtIQMZ. Escolha um clube para ver o plantel, os jogos, as estatísticas e as notícias dele.', [
        [count($clubes), 'Clubes'],
        [$totalAtletas, 'Atletas'],
        [$totalJogos, 'Jogos realizados'],
    ]); ?>

    <section id="clubes" aria-label="Clubes da liga">
        <div class="lp-head">
            <div><span class="lp-eyebrow">Clubes da liga</span><h2>Que clube quer ver?</h2></div>
        </div>
        <div class="lp-grid lp-grid--wide lp-clubs">
            <?php foreach ($clubes as $c): $logo = pub_img($c['logo_url'], $basePath); $atual = (int) $c['id'] === $teamId; ?>
            <a class="lp-card lp-team-card lp-club-pick<?= $atual ? ' is-current' : '' ?>" href="<?= pub_e($basePath) ?>index.php?equipa=<?= (int) $c['id'] ?>">
                <div class="lp-team-top">
                    <div class="lp-crest"><?php if ($logo): ?><img src="<?= pub_e($logo) ?>" alt=""><?php else: ?><?= pub_initial($c['name']) ?><?php endif; ?></div>
                    <div style="min-width:0;">
                        <h3 style="font-family:var(--display);font-size:24px;font-weight:800;color:var(--text);line-height:1.1;text-transform:uppercase;"><?= pub_e($c['name']) ?></h3>
                        <small><?= pub_e($c['city'] ?: '—') ?><?= $c['founded_year'] ? ' · desde ' . pub_e($c['founded_year']) : '' ?></small>
                    </div>
                    <?php if ($atual): ?><span class="lp-badge lp-badge--accent" style="margin-left:auto;">A ver</span><?php endif; ?>
                </div>
                <div class="lp-mini-stats" style="margin-top:0;">
                    <div><b><?= (int) $c['jogadores'] ?></b><span>ATLETAS</span></div>
                    <div><b><?= (int) $c['jogos'] ?></b><span>JOGOS</span></div>
                    <div><b><?= (int) $c['vitorias'] ?>–<?= (int) $c['derrotas'] ?></b><span>V–D SENIORES</span></div>
                </div>
                <span class="lp-btn lp-btn--primary lp-btn--block">Ver este clube <i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <?php endforeach; ?>
            <?php if (!$clubes): ?><p class="lp-empty">Ainda não há clubes registados.</p><?php endif; ?>
        </div>
    </section>

    <div class="lp-grid lp-grid--2" style="gap:28px;align-items:start;">
        <section aria-label="Últimos resultados">
            <div class="lp-head">
                <div><span class="lp-eyebrow">Toda a liga</span><h2>Últimos resultados</h2></div>
            </div>
            <div class="lp-stack">
                <?php foreach ($resultados as $g) $linhaJogo($g); ?>
                <?php if (!$resultados): ?><p class="lp-empty">Ainda não há jogos terminados.</p><?php endif; ?>
            </div>
        </section>
        <section aria-label="Próximos jogos">
            <div class="lp-head">
                <div><span class="lp-eyebrow">Toda a liga</span><h2>Próximos jogos</h2></div>
            </div>
            <div class="lp-stack">
                <?php foreach ($proximos as $g) $linhaJogo($g); ?>
                <?php if (!$proximos): ?><p class="lp-empty">Não há jogos marcados.</p><?php endif; ?>
            </div>
        </section>
    </div>

    <section aria-label="Classificação">
        <div class="lp-head">
            <div><span class="lp-eyebrow">Seniores</span><h2>Classificação</h2></div>
            <a class="lp-more" href="<?= pub_e($basePath) ?>visitante/classificacao.php">Tabela completa <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="lp-card lp-table-wrap">
            <table class="lp-table">
                <thead><tr><th scope="col">#</th><th scope="col">Clube</th><th scope="col">J</th><th scope="col">V</th><th scope="col">D</th><th scope="col">Saldo</th><th scope="col">Pts</th></tr></thead>
                <tbody>
                    <?php foreach ($tabela as $i => $l): ?>
                    <tr<?= (int) $l['id'] === $teamId ? ' class="is-us"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td><a href="<?= pub_e($basePath) ?>index.php?equipa=<?= (int) $l['id'] ?>" style="font-weight:700;"><?= pub_e($l['name']) ?></a></td>
                        <td><?= (int) $l['jogos'] ?></td><td><?= (int) $l['vitorias'] ?></td><td><?= (int) $l['derrotas'] ?></td>
                        <td><?= (int) $l['saldo'] > 0 ? '+' : '' ?><?= (int) $l['saldo'] ?></td>
                        <td><b><?= (int) $l['pontos'] ?></b></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
