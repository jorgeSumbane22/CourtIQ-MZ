<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Galeria
 * Fotografias da equipa escolhida, com filtro por categoria e visualizador.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$fotos = [];
try {
    $stmt = Database::getConnection()->prepare(
        'SELECT id, title, description, image_url, category, created_at
         FROM gallery_photos WHERE team_id = :team_id ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['team_id' => $teamId]);
    foreach ($stmt->fetchAll() as $foto) {
        $foto['src'] = pub_img($foto['image_url'], $basePath);
        if ($foto['src'] !== '') {          // ignora registos cujo ficheiro já não existe
            $fotos[] = $foto;
        }
    }
} catch (Throwable $e) {
    error_log('[CourtIQ] Erro ao carregar galeria: ' . $e->getMessage());
}

$rotulos = ['jogos' => 'Jogos', 'treinos' => 'Treinos', 'eventos' => 'Eventos', 'equipa' => 'Equipa', 'outros' => 'Outros'];
$porCategoria = array_count_values(array_column($fotos, 'category'));

$pageTitle = 'Galeria';
$pageIcon  = 'fa-solid fa-images';
$activeNav = 'galeria';
require __DIR__ . '/../includes/layout/public_top.php';
if (pub_account_wall('as fotografias da galeria', $basePath)) { require __DIR__ . '/../includes/layout/public_bottom.php'; return; }
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Galeria', 'Os melhores momentos da temporada em imagens.', [
        [count($fotos), 'Fotografias'],
    ]); ?>

    <section data-filter>
        <?php if (count($porCategoria) > 1): ?>
        <div class="lp-toolbar">
            <div class="lp-chips" role="group" aria-label="Filtrar por categoria">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todas <small><?= count($fotos) ?></small></button>
                <?php foreach ($rotulos as $chave => $rotulo): if (empty($porCategoria[$chave])) continue; ?>
                <button type="button" class="lp-chip" data-filter-value="<?= $chave ?>" aria-pressed="false"><?= $rotulo ?> <small><?= (int) $porCategoria[$chave] ?></small></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="lp-photos">
            <?php foreach ($fotos as $foto): ?>
            <button type="button" class="lp-photo" data-filter-item data-tags="<?= pub_e($foto['category']) ?>"
                    data-full="<?= pub_e($foto['src']) ?>" data-caption="<?= pub_e($foto['title']) ?>"
                    aria-label="Ampliar: <?= pub_e($foto['title']) ?>">
                <img src="<?= pub_e($foto['src']) ?>" alt="<?= pub_e($foto['title']) ?>" loading="lazy">
                <span><i class="fa-solid fa-camera" style="margin-right:6px;"></i><?= pub_e($foto['title']) ?></span>
            </button>
            <?php endforeach; ?>

            <p class="lp-empty" data-filter-empty <?= $fotos ? 'hidden' : '' ?>>
                <?= $fotos ? 'Não há fotografias nesta categoria.' : 'Esta equipa ainda não publicou fotografias.' ?>
            </p>
        </div>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
