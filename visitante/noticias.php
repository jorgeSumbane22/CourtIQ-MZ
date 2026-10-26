<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Notícias
 * Lista as notícias da equipa escolhida; com ?id= mostra uma notícia completa.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';
require_once __DIR__ . '/../includes/repositories/NewsRepository.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$newsRepo = new NewsRepository();
$noticias = $newsRepo->latest(60, $teamId);

// Notícia aberta: tem de pertencer à equipa que o visitante está a ver
$artigo = null;
$artigoId = (int) ($_GET['id'] ?? 0);
if ($artigoId > 0) {
    foreach ($noticias as $n) {
        if ((int) $n['id'] === $artigoId) {
            $artigo = $n;
            break;
        }
    }
}

$categorias = [];
foreach ($noticias as $n) {
    $cat = trim((string) ($n['category'] ?? '')) ?: 'geral';
    $categorias[$cat] = ($categorias[$cat] ?? 0) + 1;
}

$pageTitle = 'Notícias';
$pageIcon  = 'fa-solid fa-newspaper';
$activeNav = 'noticias';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
<?php if ($artigo): ?>

    <article class="lp-article">
        <a class="lp-back" href="noticias.php"><i class="fa-solid fa-arrow-left"></i> Todas as notícias</a>
        <h1><?= pub_e($artigo['title']) ?></h1>
        <div class="lp-game-meta" style="margin-bottom:24px;">
            <span><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($artigo['published_at'])) ?></span>
            <?php if (!empty($artigo['author_name'])): ?><span><i class="fa-solid fa-pen-nib"></i><?= pub_e($artigo['author_name']) ?></span><?php endif; ?>
            <span><i class="fa-solid fa-basketball"></i><?= pub_e($team['name']) ?></span>
        </div>
        <div class="lp-article-cover">
            <img src="<?= pub_e(pub_img($artigo['image_url'] ?? null, $basePath) ?: PUB_FALLBACK_IMG) ?>" alt="">
        </div>
        <div class="lp-article-body">
            <?php foreach (preg_split('/\R{2,}/', trim((string) $artigo['content'])) as $paragrafo): ?>
                <p><?= nl2br(pub_e($paragrafo)) ?></p>
            <?php endforeach; ?>
        </div>
    </article>

<?php else: ?>

    <?php pub_page_head($team['name'], 'Notícias', 'Tudo o que acontece no clube, dentro e fora do campo.', [
        [count($noticias), 'Publicações'],
    ]); ?>

    <?php if ($artigoId > 0): ?>
        <div class="lp-notice lp-notice--error"><i class="fa-solid fa-circle-exclamation"></i><div>Essa notícia não existe ou pertence a outra equipa.</div></div>
    <?php endif; ?>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" data-search placeholder="Procurar notícia..." aria-label="Procurar notícia">
            </div>
            <?php if (count($categorias) > 1): ?>
            <div class="lp-chips" role="group" aria-label="Filtrar por categoria">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todas</button>
                <?php foreach ($categorias as $cat => $total): ?>
                <button type="button" class="lp-chip" data-filter-value="<?= pub_e($cat) ?>" aria-pressed="false"><?= pub_e(ucfirst($cat)) ?> <small><?= $total ?></small></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="lp-news">
            <?php foreach ($noticias as $n):
                $cat = trim((string) ($n['category'] ?? '')) ?: 'geral';
            ?>
            <a class="lp-card lp-news-card" href="noticias.php?id=<?= (int) $n['id'] ?>" data-filter-item data-tags="<?= pub_e($cat) ?>" data-name="<?= pub_e(mb_strtolower($n['title'] . ' ' . $n['content'])) ?>">
                <div class="lp-news-img">
                    <img src="<?= pub_e(pub_img($n['image_url'] ?? null, $basePath) ?: PUB_FALLBACK_IMG) ?>" alt="" loading="lazy">
                </div>
                <div class="lp-news-body">
                    <span class="lp-news-date"><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($n['published_at'])) ?> &bull; <?= pub_e(ucfirst($cat)) ?></span>
                    <h2 class="lp-news-title"><?= pub_e($n['title']) ?></h2>
                    <p class="lp-news-desc"><?= pub_e($n['content']) ?></p>
                    <span class="lp-news-link">Ler mais <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endforeach; ?>

            <p class="lp-empty" data-filter-empty <?= $noticias ? 'hidden' : '' ?>>
                <?= $noticias ? 'Nenhuma notícia corresponde à pesquisa.' : 'Esta equipa ainda não publicou notícias.' ?>
            </p>
        </div>
    </section>

<?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
