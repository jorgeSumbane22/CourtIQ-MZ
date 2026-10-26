<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Admin: Notícias
 * As notícias que aparecem no site público da equipa (página inicial e /visitante/noticias.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require_once __DIR__ . '/../includes/upload_helper.php';

[$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);

$categorias = ['geral' => 'Geral', 'equipa' => 'Equipa', 'jogo' => 'Jogo', 'clube' => 'Clube', 'bilhetes' => 'Bilhetes', 'formacao' => 'Formação'];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    try {
        if ($id > 0 && !team_owns('news', $id)) {
            throw new RuntimeException('Notícia não encontrada.');
        }

        if ($acao === 'save') {
            $dados = [
                'title'        => trim((string) ($_POST['title'] ?? '')),
                'content'      => trim((string) ($_POST['content'] ?? '')),
                'category'     => isset($categorias[$_POST['category'] ?? '']) ? (string) $_POST['category'] : 'geral',
                'published_at' => trim((string) ($_POST['published_at'] ?? '')) ?: date('Y-m-d'),
            ];
            if ($dados['title'] === '' || mb_strlen($dados['title']) > 200) throw new RuntimeException('Indique o título (até 200 caracteres).');
            if (mb_strlen($dados['content']) < 10) throw new RuntimeException('Escreva o texto da notícia.');
            if (!strtotime($dados['published_at'])) throw new RuntimeException('Data de publicação inválida.');

            $imagemAntiga = null;
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT image_url FROM news WHERE id = :id AND team_id = :team_id');
                $stmt->execute(['id' => $id, 'team_id' => $teamId]);
                $imagemAntiga = $stmt->fetchColumn() ?: null;
            }
            $imagem = upload_has('image') ? upload_image('image', 'noticias', $teamId) : $imagemAntiga;

            if ($id > 0) {
                $pdo->prepare('UPDATE news SET title = :title, content = :content, category = :category, published_at = :published_at, image_url = :image WHERE id = :id AND team_id = :team_id')
                    ->execute($dados + ['image' => $imagem, 'id' => $id, 'team_id' => $teamId]);
                if ($imagem !== $imagemAntiga) upload_delete($imagemAntiga, $teamId);
                flash_set('success', 'Notícia atualizada.');
            } else {
                $pdo->prepare('INSERT INTO news (team_id, author_id, title, content, category, published_at, image_url) VALUES (:team_id, :author, :title, :content, :category, :published_at, :image)')
                    ->execute($dados + ['image' => $imagem, 'team_id' => $teamId, 'author' => (int) $user['id']]);
                panel_log($pdo, $teamId, 'noticia_publicada', $dados['title']);
                if ($dados['published_at'] <= date('Y-m-d')) {
                    notify_fans($pdo, $teamId, 'Nova notícia: ' . $dados['title'], mb_strimwidth($dados['content'], 0, 180, '…'));
                }
                flash_set('success', 'Notícia publicada no site.');
            }

        } elseif ($acao === 'delete') {
            $stmt = $pdo->prepare('SELECT image_url FROM news WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            $imagem = $stmt->fetchColumn();
            $pdo->prepare('DELETE FROM news WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
            upload_delete($imagem ?: null, $teamId);
            panel_log($pdo, $teamId, 'noticia_removida', '#' . $id);
            flash_set('success', 'Notícia removida.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar a notícia.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/noticias: ' . $e->getMessage());
    }
    panel_redirect('noticias.php');
}

$stmt = $pdo->prepare('SELECT n.*, u.name AS autor FROM news n LEFT JOIN users u ON u.id = n.author_id WHERE n.team_id = :team_id ORDER BY n.published_at DESC, n.id DESC');
$stmt->execute(['team_id' => $teamId]);
$noticias = $stmt->fetchAll();
$agendadas = count(array_filter($noticias, fn($n) => $n['published_at'] > date('Y-m-d')));

$pageTitle = 'Notícias';
$activeNav = 'noticias';
require __DIR__ . '/../includes/layout/admin_top.php';
?>

<div class="lp pn">
    <?php panel_head('Notícias', count($noticias) . ' notícias no site de ' . $team['name'] . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../visitante/noticias.php?equipa=' . $teamId . '"><i class="fa-solid fa-globe"></i> Ver no site</a>'
        . '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#noticiaModal" data-reset data-values="' . pn_values(['id' => 0, 'janela' => 'Nova notícia', 'published_at' => date('Y-m-d'), 'category' => 'geral']) . '"><i class="fa-solid fa-plus"></i> Nova notícia</button>'); ?>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Procurar notícia..." aria-label="Procurar notícia"></div>
        </div>

        <div class="lp-news">
            <?php foreach ($noticias as $n): ?>
            <article class="lp-card lp-news-card" data-filter-item data-name="<?= pub_e(mb_strtolower($n['title'] . ' ' . $n['content'])) ?>">
                <div class="lp-news-img"><img src="<?= pub_e(pub_img($n['image_url'], '../') ?: PUB_FALLBACK_IMG) ?>" alt="" loading="lazy"></div>
                <div class="lp-news-body">
                    <span class="lp-news-date"><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($n['published_at'])) ?> &bull; <?= pub_e($categorias[$n['category']] ?? ucfirst((string) $n['category'])) ?></span>
                    <h2 class="lp-news-title"><?= pub_e($n['title']) ?></h2>
                    <p class="lp-news-desc"><?= pub_e($n['content']) ?></p>
                    <div class="pn-row-actions" style="margin-top:auto;padding-top:10px;justify-content:flex-start;">
                        <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal="#noticiaModal" data-reset data-values="<?= pn_values([
                            'id' => (int) $n['id'], 'janela' => 'Editar notícia', 'title' => $n['title'], 'content' => $n['content'],
                            'category' => $n['category'], 'published_at' => $n['published_at'],
                        ]) ?>"><i class="fa-solid fa-pen"></i> Editar</button>
                        <a class="pn-icon-btn" href="../visitante/noticias.php?equipa=<?= $teamId ?>&amp;id=<?= (int) $n['id'] ?>" title="Ver no site" aria-label="Ver no site"><i class="fa-solid fa-eye"></i></a>
                        <form method="post" data-confirm="Remover a notícia '<?= pub_e($n['title']) ?>'?">
                            <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover notícia"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            <p class="lp-empty" data-filter-empty <?= $noticias ? 'hidden' : '' ?>><?= $noticias ? 'Nenhuma notícia corresponde à pesquisa.' : 'Ainda não há notícias. Publique a primeira.' ?></p>
        </div>
    </section>
</div>

<dialog class="pn-modal pn-modal--wide" id="noticiaModal">
    <form method="post" enctype="multipart/form-data">
        <div class="pn-modal-head"><h2 data-text="janela">Notícia</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
            <div class="pn-form-grid">
                <div class="lp-field full"><label for="n-title">Título</label><input id="n-title" name="title" type="text" maxlength="200" required></div>
                <div class="lp-field"><label for="n-cat">Categoria</label>
                    <select id="n-cat" name="category"><?php foreach ($categorias as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field"><label for="n-date">Data de publicação</label><input id="n-date" name="published_at" type="date" required></div>
                <div class="lp-field full"><label for="n-content">Texto</label><textarea id="n-content" name="content" required style="min-height:200px;"></textarea>
                    <p class="pn-help">Deixe uma linha em branco entre parágrafos.</p></div>
                <div class="lp-field full"><label for="n-image">Imagem de capa</label><input id="n-image" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                    <p class="pn-help">JPG, PNG, WEBP ou GIF até 4 MB. Ao editar, deixe vazio para manter a imagem atual.</p></div>
            </div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/../includes/layout/admin_bottom.php'; ?>
