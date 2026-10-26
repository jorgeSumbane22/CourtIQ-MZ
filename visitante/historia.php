<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: O clube
 * Ficha da equipa escolhida, construída só com dados que existem na base de dados
 * (fundação, cidade, pavilhão, treinador, capitão e números da temporada).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$pdo = Database::getConnection();

$stats = TeamHelper::getTeamStats($teamId);

$pessoa = function (string $sql) use ($pdo, $teamId): ?string {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['team_id' => $teamId]);
        $nome = $stmt->fetchColumn();
        return $nome !== false && $nome !== null ? (string) $nome : null;
    } catch (Throwable $e) {
        return null;
    }
};

$treinador = $pessoa('SELECT COALESCE(u.name, c.name) FROM coaches c LEFT JOIN users u ON u.id = c.user_id WHERE c.team_id = :team_id ORDER BY c.id LIMIT 1');
$capitao   = $pessoa('SELECT COALESCE(u.name, p.name) FROM players p LEFT JOIN users u ON u.id = p.user_id WHERE p.team_id = :team_id AND p.is_captain = 1 LIMIT 1');

$primeiroJogo = null;
try {
    $stmt = $pdo->prepare("SELECT opponent, game_date, our_score, opponent_score FROM games WHERE team_id = :team_id AND status = 'finished' ORDER BY game_date ASC LIMIT 1");
    $stmt->execute(['team_id' => $teamId]);
    $primeiroJogo = $stmt->fetch() ?: null;
} catch (Throwable $e) {
    error_log('[CourtIQ] Erro ao carregar o primeiro jogo: ' . $e->getMessage());
}

$fundacao = !empty($team['founded_year']) ? (int) $team['founded_year'] : null;
$anos     = $fundacao ? (int) date('Y') - $fundacao : null;
$logo     = pub_img($team['logo_url'] ?? null, $basePath);

$pageTitle = 'História';
$pageIcon  = 'fa-solid fa-book-open';
$activeNav = 'historia';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'O clube',
        $fundacao
            ? 'Fundado em ' . $fundacao . ($team['city'] ? ', em ' . $team['city'] : '') . '. ' . $anos . ' anos a jogar basquetebol.'
            : 'Conheça a equipa.', [
        [$fundacao ?: '—', 'Fundação'],
        [$anos !== null ? $anos : '—', 'Anos'],
    ]); ?>

    <div class="lp-grid lp-grid--2">
        <section class="lp-card lp-pad lp-reveal">
            <h2 class="lp-sub">Ficha do clube</h2>
            <ul class="lp-info">
                <li><span class="lp-info-icon"><i class="fa-solid fa-shield-halved"></i></span><div><small>Nome</small><b><?= pub_e($team['name']) ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-city"></i></span><div><small>Cidade</small><b><?= pub_e($team['city'] ?: '—') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-location-dot"></i></span><div><small>Pavilhão</small><b><?= pub_e($team['stadium'] ?: '—') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-trophy"></i></span><div><small>Competição</small><b><?= pub_e(($team['league'] ?? '') ?: 'Liga Nacional') ?> &bull; <?= pub_e(($team['category'] ?? '') ?: 'Sénior') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-chalkboard-user"></i></span><div><small>Treinador principal</small><b><?= pub_e($treinador ?: '—') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-star"></i></span><div><small>Capitão</small><b><?= pub_e($capitao ?: '—') ?></b></div></li>
            </ul>
        </section>

        <section class="lp-reveal" style="display:flex;flex-direction:column;gap:20px;">
            <?php if ($logo): ?>
            <div class="lp-card" style="overflow:hidden;aspect-ratio:16/9;">
                <img src="<?= pub_e($logo) ?>" alt="Emblema de <?= pub_e($team['name']) ?>" style="width:100%;height:100%;object-fit:cover;">
            </div>
            <?php endif; ?>

            <div class="lp-kpis">
                <div class="lp-card lp-kpi"><b><?= (int) ($stats['total_jogadores'] ?? 0) ?></b><span>Jogadores no plantel</span></div>
                <div class="lp-card lp-kpi"><b><?= (int) ($stats['total_jogos'] ?? 0) ?></b><span>Jogos nesta temporada</span></div>
                <div class="lp-card lp-kpi lp-kpi--up"><b><?= (int) ($stats['total_vitorias'] ?? 0) ?></b><span>Vitórias</span></div>
                <div class="lp-card lp-kpi lp-kpi--accent"><b><?= pub_e($stats['aproveitamento'] ?? 0) ?>%</b><span>Aproveitamento</span></div>
            </div>
        </section>
    </div>

    <section class="lp-reveal">
        <div class="lp-head"><div><span class="lp-eyebrow">Percurso</span><h2>Momentos</h2></div></div>
        <div class="lp-stack">
            <?php if ($fundacao): ?>
            <div class="lp-card lp-game">
                <div class="lp-date"><b style="font-size:20px;"><?= $fundacao ?></b><span>FUNDAÇÃO</span></div>
                <div>
                    <div class="lp-game-teams">Nasce o <?= pub_e($team['name']) ?></div>
                    <div class="lp-game-meta"><span><i class="fa-solid fa-city"></i><?= pub_e($team['city'] ?: 'Moçambique') ?></span></div>
                </div>
                <span class="lp-badge lp-badge--gold">Início</span>
            </div>
            <?php endif; ?>

            <?php if ($primeiroJogo): $ganhou = (int) $primeiroJogo['our_score'] > (int) $primeiroJogo['opponent_score']; ?>
            <div class="lp-card lp-game">
                <div class="lp-date"><b><?= pub_date($primeiroJogo['game_date'], 'd') ?></b><span><?= pub_month((int) pub_date($primeiroJogo['game_date'], 'n')) ?></span></div>
                <div>
                    <div class="lp-game-teams">Primeiro jogo da temporada <small>vs</small> <?= pub_e($primeiroJogo['opponent']) ?></div>
                    <div class="lp-game-meta"><span><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($primeiroJogo['game_date'])) ?></span></div>
                </div>
                <div class="lp-game-side">
                    <span class="lp-score"><?= (int) $primeiroJogo['our_score'] ?> &ndash; <?= (int) $primeiroJogo['opponent_score'] ?></span>
                    <span class="lp-badge lp-badge--<?= $ganhou ? 'win' : 'loss' ?>"><?= $ganhou ? 'Vitória' : 'Derrota' ?></span>
                </div>
            </div>
            <?php endif; ?>

            <div class="lp-card lp-game">
                <div class="lp-date"><b style="font-size:20px;"><?= date('Y') ?></b><span>HOJE</span></div>
                <div>
                    <div class="lp-game-teams"><?= (int) ($stats['total_vitorias'] ?? 0) ?> vitórias em <?= (int) ($stats['total_jogos'] ?? 0) ?> jogos</div>
                    <div class="lp-game-meta"><span><i class="fa-solid fa-chart-line"></i><?= (int) ($stats['pontos_marcados'] ?? 0) ?> pontos marcados nesta temporada</span></div>
                </div>
                <a class="lp-btn lp-btn--ghost" style="padding:9px 16px;font-size:13px;" href="jogos.php">Ver resultados</a>
            </div>
        </div>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
