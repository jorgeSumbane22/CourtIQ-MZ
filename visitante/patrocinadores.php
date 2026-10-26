<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Patrocinadores
 * Patrocinadores da equipa escolhida, agrupados por nível.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';
require_once __DIR__ . '/../includes/repositories/SponsorRepository.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$todos = array_filter(
    (new SponsorRepository())->all($teamId),
    fn($s) => ($s['status'] ?? 'active') === 'active'
);

$niveis = [
    'ouro'   => ['Patrocinadores principais', 'gold'],
    'prata'  => ['Patrocinadores oficiais', ''],
    'bronze' => ['Parceiros', 'accent'],
];
$porNivel = [];
foreach ($todos as $s) {
    $porNivel[$s['tier'] ?? 'bronze'][] = $s;
}

$pageTitle = 'Patrocinadores';
$pageIcon  = 'fa-solid fa-handshake';
$activeNav = 'patrocinadores';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Patrocinadores', 'As empresas que tornam possível o projeto desportivo do clube.', [
        [count($todos), 'Parceiros'],
    ]); ?>

    <?php foreach ($niveis as $nivel => [$titulo, $badge]):
        if (empty($porNivel[$nivel])) continue; ?>
    <section class="lp-reveal">
        <div class="lp-head"><div><span class="lp-eyebrow">Nível <?= pub_e($nivel) ?></span><h2><?= pub_e($titulo) ?></h2></div></div>
        <div class="lp-grid lp-grid--wide">
            <?php foreach ($porNivel[$nivel] as $s):
                $logo = pub_img($s['logo_url'] ?? null, $basePath);
                $site = (string) ($s['website'] ?? '');
                $siteValido = (bool) preg_match('#^https?://#i', $site);
            ?>
            <article class="lp-card lp-sponsor-card">
                <div class="lp-sponsor-logo">
                    <?php if ($logo): ?><img src="<?= pub_e($logo) ?>" alt="<?= pub_e($s['name']) ?>" loading="lazy"><?php else: ?><?= pub_e($s['name']) ?><?php endif; ?>
                </div>
                <div class="lp-chips">
                    <span class="lp-badge lp-badge--<?= $badge ?>"><?= pub_e(ucfirst($nivel)) ?></span>
                    <?php if (!empty($s['sector'])): ?><span class="lp-badge"><?= pub_e($s['sector']) ?></span><?php endif; ?>
                </div>
                <h3><?= pub_e($s['name']) ?></h3>
                <?php if (!empty($s['description'])): ?><p><?= pub_e($s['description']) ?></p><?php endif; ?>
                <?php if (!empty($s['start_date'])): ?>
                    <div class="lp-game-meta"><span><i class="fa-solid fa-handshake"></i>Parceiro desde <?= pub_e(pub_date($s['start_date'], 'Y')) ?></span></div>
                <?php endif; ?>
                <?php if ($siteValido): ?>
                    <a class="lp-news-link" href="<?= pub_e($site) ?>" target="_blank" rel="noopener noreferrer">Visitar site <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <?php if (!$todos): ?><p class="lp-empty">Esta equipa ainda não tem patrocinadores registados.</p><?php endif; ?>

    <section class="lp-cta lp-reveal">
        <div>
            <h2>Quer apoiar o <em><?= pub_e($team['name']) ?></em>?</h2>
            <p>Fale connosco para conhecer as modalidades de patrocínio disponíveis.</p>
        </div>
        <div class="lp-cta-actions">
            <a href="contacto.php?assunto=<?= rawurlencode('Patrocínio') ?>" class="lp-btn lp-btn--primary"><i class="fa-solid fa-handshake"></i> Ser patrocinador</a>
        </div>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
