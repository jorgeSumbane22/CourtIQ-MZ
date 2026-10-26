<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Admin: Galeria
 * As fotografias que aparecem no site público da equipa.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require_once __DIR__ . '/../includes/upload_helper.php';

[$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);

$categorias = ['jogos' => 'Jogos', 'treinos' => 'Treinos', 'eventos' => 'Eventos', 'equipa' => 'Equipa', 'outros' => 'Outros'];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    try {
        if ($acao === 'upload') {
            $titulo    = trim((string) ($_POST['title'] ?? ''));
            $categoria = isset($categorias[$_POST['category'] ?? '']) ? (string) $_POST['category'] : 'outros';
            if ($titulo === '' || mb_strlen($titulo) > 200) throw new RuntimeException('Indique um título para a fotografia.');
            if (!upload_has('image')) throw new RuntimeException('Escolha a fotografia a enviar.');

            $caminho = upload_image('image', 'galeria', $teamId);
            $pdo->prepare('INSERT INTO gallery_photos (team_id, title, description, image_url, category) VALUES (:team_id, :title, :description, :image, :category)')
                ->execute(['team_id' => $teamId, 'title' => $titulo, 'description' => trim((string) ($_POST['description'] ?? '')) ?: null, 'image' => $caminho, 'category' => $categoria]);
            panel_log($pdo, $teamId, 'foto_adicionada', $titulo);
            flash_set('success', 'Fotografia adicionada à galeria.');

        } else {
            if (!team_owns('gallery_photos', $id)) throw new RuntimeException('Fotografia não encontrada.');

            if ($acao === 'update') {
                $titulo = trim((string) ($_POST['title'] ?? ''));
                if ($titulo === '' || mb_strlen($titulo) > 200) throw new RuntimeException('Indique um título para a fotografia.');
                $pdo->prepare('UPDATE gallery_photos SET title = :title, description = :description, category = :category WHERE id = :id AND team_id = :team_id')
                    ->execute([
                        'title' => $titulo, 'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                        'category' => isset($categorias[$_POST['category'] ?? '']) ? (string) $_POST['category'] : 'outros', 'id' => $id, 'team_id' => $teamId,
                    ]);
                flash_set('success', 'Fotografia atualizada.');

            } elseif ($acao === 'delete') {
                $stmt = $pdo->prepare('SELECT image_url FROM gallery_photos WHERE id = :id AND team_id = :team_id');
                $stmt->execute(['id' => $id, 'team_id' => $teamId]);
                $caminho = $stmt->fetchColumn();
                $pdo->prepare('DELETE FROM gallery_photos WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
                upload_delete($caminho ?: null, $teamId);
                flash_set('success', 'Fotografia removida.');
            }
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar a fotografia.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/galeria: ' . $e->getMessage());
    }
    panel_redirect('galeria.php');
}

$stmt = $pdo->prepare('SELECT * FROM gallery_photos WHERE team_id = :team_id ORDER BY created_at DESC, id DESC');
$stmt->execute(['team_id' => $teamId]);
$fotos = $stmt->fetchAll();
$porCategoria = array_count_values(array_column($fotos, 'category'));

$pageTitle = 'Galeria';
$activeNav = 'galeria';
require __DIR__ . '/../includes/layout/admin_top.php';
?>

<div class="lp pn">
    <?php panel_head('Galeria', count($fotos) . ' fotografias no site de ' . $team['name'] . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../visitante/galeria.php?equipa=' . $teamId . '"><i class="fa-solid fa-globe"></i> Ver no site</a>'
        . '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novaFoto" data-reset><i class="fa-solid fa-upload"></i> Enviar fotografia</button>'); ?>

    <section data-filter>
        <?php if (count($porCategoria) > 1): ?>
        <div class="lp-toolbar"><div class="lp-chips" role="group" aria-label="Filtrar por categoria">
            <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todas <small><?= count($fotos) ?></small></button>
            <?php foreach ($categorias as $k => $v): if (empty($porCategoria[$k])) continue; ?>
            <button type="button" class="lp-chip" data-filter-value="<?= $k ?>" aria-pressed="false"><?= $v ?> <small><?= (int) $porCategoria[$k] ?></small></button>
            <?php endforeach; ?>
        </div></div>
        <?php endif; ?>

        <div class="lp-photos">
            <?php foreach ($fotos as $f): $src = pub_img($f['image_url'], '../'); ?>
            <article class="lp-card" style="overflow:hidden;display:flex;flex-direction:column;" data-filter-item data-tags="<?= pub_e($f['category']) ?>">
                <div style="aspect-ratio:4/3;background:var(--surface2);">
                    <?php if ($src): ?><img src="<?= pub_e($src) ?>" alt="<?= pub_e($f['title']) ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?><div class="pn-empty" style="height:100%;display:flex;flex-direction:column;justify-content:center;"><i class="fa-solid fa-image"></i>Ficheiro em falta</div><?php endif; ?>
                </div>
                <div style="padding:12px 14px;display:flex;gap:10px;align-items:center;">
                    <div style="min-width:0;flex:1;"><b style="display:block;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= pub_e($f['title']) ?></b>
                        <small style="color:var(--muted);font-size:12px;"><?= pub_e($categorias[$f['category']] ?? $f['category']) ?></small></div>
                    <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar fotografia" data-modal="#editarFoto" data-values="<?= pn_values(['id' => (int) $f['id'], 'title' => $f['title'], 'description' => $f['description'], 'category' => $f['category']]) ?>"><i class="fa-solid fa-pen"></i></button>
                    <form method="post" data-confirm="Remover a fotografia '<?= pub_e($f['title']) ?>'?">
                        <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover fotografia"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
            <p class="lp-empty" data-filter-empty <?= $fotos ? 'hidden' : '' ?>><?= $fotos ? 'Não há fotografias nesta categoria.' : 'A galeria está vazia. Envie a primeira fotografia.' ?></p>
        </div>
    </section>
</div>

<dialog class="pn-modal" id="novaFoto">
    <form method="post" enctype="multipart/form-data">
        <div class="pn-modal-head"><h2>Enviar fotografia</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="upload">
            <div class="lp-field"><label for="f-image">Fotografia</label><input id="f-image" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required>
                <p class="pn-help">JPG, PNG, WEBP ou GIF até 4 MB.</p></div>
            <div class="lp-field"><label for="f-title">Título</label><input id="f-title" name="title" type="text" maxlength="200" required></div>
            <div class="lp-field"><label for="f-cat">Categoria</label>
                <select id="f-cat" name="category"><?php foreach ($categorias as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="lp-field"><label for="f-desc">Descrição (opcional)</label><input id="f-desc" name="description" type="text" maxlength="255"></div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-upload"></i> Enviar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="editarFoto">
    <form method="post">
        <div class="pn-modal-head"><h2>Editar fotografia</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id">
            <div class="lp-field"><label for="g-title">Título</label><input id="g-title" name="title" type="text" maxlength="200" required></div>
            <div class="lp-field"><label for="g-cat">Categoria</label>
                <select id="g-cat" name="category"><?php foreach ($categorias as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            <div class="lp-field"><label for="g-desc">Descrição</label><input id="g-desc" name="description" type="text" maxlength="255"></div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/../includes/layout/admin_bottom.php'; ?>
