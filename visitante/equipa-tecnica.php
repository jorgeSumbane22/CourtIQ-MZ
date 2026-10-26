<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Equipa técnica
 * Treinadores da equipa escolhida.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$tecnicos = [];
try {
    $stmt = Database::getConnection()->prepare(
        'SELECT c.*, COALESCE(u.name, c.name) AS display_name, u.avatar_url
         FROM coaches c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.team_id = :team_id
         ORDER BY c.id'
    );
    $stmt->execute(['team_id' => $teamId]);
    $tecnicos = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[CourtIQ] Erro ao carregar equipa técnica: ' . $e->getMessage());
}

$experiencia = array_sum(array_map(fn($t) => (int) ($t['experience_years'] ?? 0), $tecnicos));

$pageTitle = 'Equipa Técnica';
$pageIcon  = 'fa-solid fa-chalkboard-user';
$activeNav = 'equipa-tecnica';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Equipa técnica', 'As pessoas que preparam a equipa todos os dias.', [
        [count($tecnicos), 'Elementos'],
        [$experiencia, 'Anos de experiência'],
    ]); ?>

    <section class="lp-grid lp-grid--2">
        <?php foreach ($tecnicos as $t): ?>
        <article class="lp-card lp-person lp-reveal">
            <?php $fotoTecnico = pub_img($t['avatar_url'] ?? null, '../'); ?>
            <?php if ($fotoTecnico): ?><img class="lp-avatar" src="<?= pub_e($fotoTecnico) ?>" alt="" loading="lazy" style="object-fit:cover;">
            <?php else: ?><div class="lp-avatar"><?= pub_initial($t['display_name']) ?></div><?php endif; ?>
            <div style="min-width:0;">
                <h2 style="font-size:19px;font-weight:700;color:var(--text);line-height:1.2;"><?= pub_e($t['display_name'] ?: 'Treinador') ?></h2>
                <div class="lp-role"><?= pub_e($t['role_title'] ?: 'Treinador') ?></div>
                <?php if (!empty($t['bio'])): ?><p><?= pub_e($t['bio']) ?></p><?php endif; ?>
                <div class="lp-chips" style="margin-top:14px;">
                    <?php if ((int) ($t['experience_years'] ?? 0) > 0): ?>
                        <span class="lp-badge"><i class="fa-solid fa-clock-rotate-left"></i> <?= (int) $t['experience_years'] ?> anos</span>
                    <?php endif; ?>
                    <?php if (!empty($t['certification'])): ?>
                        <span class="lp-badge lp-badge--accent"><i class="fa-solid fa-certificate"></i> <?= pub_e($t['certification']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($t['specialization'])): ?>
                        <span class="lp-badge lp-badge--info"><?= pub_e($t['specialization']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php endforeach; ?>

        <?php if (!$tecnicos): ?><p class="lp-empty">Esta equipa ainda não registou a equipa técnica.</p><?php endif; ?>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
