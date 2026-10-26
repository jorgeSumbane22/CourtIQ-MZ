<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Treinador: Comunicados
 * Avisos internos para a equipa do treinador. Ao publicar pode-se notificar todos os membros.
 */

$panel = 'treinador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$categorias  = ['geral' => 'Geral', 'jogo' => 'Jogo', 'treino' => 'Treino', 'disciplina' => 'Disciplina', 'logistica' => 'Logística'];
$prioridades = ['normal' => ['Normal', ''], 'alta' => ['Alta', 'gold'], 'urgente' => ['Urgente', 'loss']];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    try {
        if ($id > 0 && !team_owns('comunicados', $id)) {
            throw new RuntimeException('Comunicado não encontrado.');
        }

        if ($acao === 'save') {
            $dados = [
                'titulo'     => trim((string) ($_POST['titulo'] ?? '')),
                'conteudo'   => trim((string) ($_POST['conteudo'] ?? '')),
                'categoria'  => isset($categorias[$_POST['categoria'] ?? '']) ? (string) $_POST['categoria'] : 'geral',
                'prioridade' => isset($prioridades[$_POST['prioridade'] ?? '']) ? (string) $_POST['prioridade'] : 'normal',
                'status'     => ($_POST['status'] ?? '') === 'rascunho' ? 'rascunho' : 'publicado',
                'fixado'     => !empty($_POST['fixado']) ? 1 : 0,
            ];
            if ($dados['titulo'] === '' || mb_strlen($dados['titulo']) > 255) throw new RuntimeException('Indique o título do comunicado.');
            if ($dados['conteudo'] === '') throw new RuntimeException('Escreva o conteúdo do comunicado.');

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE comunicados SET titulo = :titulo, conteudo = :conteudo, categoria = :categoria, prioridade = :prioridade,
                            status = :status, fixado = :fixado,
                            data_publicacao = IF(:status2 = "publicado" AND data_publicacao IS NULL, NOW(), data_publicacao)
                     WHERE id = :id AND team_id = :team_id'
                );
                $stmt->execute($dados + ['status2' => $dados['status'], 'id' => $id, 'team_id' => $teamId]);
                flash_set('success', 'Comunicado atualizado.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO comunicados (team_id, titulo, conteudo, categoria, prioridade, status, fixado, autor_id, data_envio, data_publicacao)
                     VALUES (:team_id, :titulo, :conteudo, :categoria, :prioridade, :status, :fixado, :autor_id, CURDATE(), IF(:status2 = "publicado", NOW(), NULL))'
                );
                $stmt->execute($dados + ['status2' => $dados['status'], 'team_id' => $teamId, 'autor_id' => (int) $user['id']]);
                panel_log($pdo, $teamId, 'comunicado_criado', $dados['titulo']);
                flash_set('success', $dados['status'] === 'publicado' ? 'Comunicado publicado.' : 'Rascunho guardado.');
            }

            // avisar a equipa (só membros desta equipa, menos o autor)
            if ($dados['status'] === 'publicado' && !empty($_POST['notificar'])) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE team_id = :team_id AND status = 'active' AND role <> 'adepto' AND id <> :me");
                $stmt->execute(['team_id' => $teamId, 'me' => (int) $user['id']]);
                $insert = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, :type)');
                $n = 0;
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $destinatario) {
                    $insert->execute([
                        'user_id' => (int) $destinatario,
                        'title'   => 'Comunicado: ' . mb_substr($dados['titulo'], 0, 180),
                        'message' => mb_substr($dados['conteudo'], 0, 500),
                        'type'    => $dados['prioridade'] === 'urgente' ? 'warning' : 'info',
                    ]);
                    $n++;
                }
                flash_set('success', 'Comunicado publicado e ' . $n . ' pessoa(s) notificada(s).');
            }

        } elseif ($acao === 'delete') {
            $pdo->prepare('DELETE FROM comunicados WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
            flash_set('success', 'Comunicado removido.');

        } elseif ($acao === 'pin') {
            $pdo->prepare('UPDATE comunicados SET fixado = 1 - fixado WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] treinador/comunicados: ' . $e->getMessage());
    }
    panel_redirect('comunicados.php');
}

$stmt = $pdo->prepare(
    'SELECT c.*, u.name AS autor FROM comunicados c LEFT JOIN users u ON u.id = c.autor_id
     WHERE c.team_id = :team_id ORDER BY c.fixado DESC, c.created_at DESC, c.id DESC'
);
$stmt->execute(['team_id' => $teamId]);
$comunicados = $stmt->fetchAll();

$publicados = count(array_filter($comunicados, fn($c) => $c['status'] === 'publicado'));

$pageTitle = 'Comunicados';
$activeNav = 'comunicados';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Comunicados', $publicados . ' publicados · ' . (count($comunicados) - $publicados) . ' rascunhos.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#comunicadoModal" data-reset data-values="' . pn_values(['id' => 0, 'janela' => 'Novo comunicado', 'status' => 'publicado', 'categoria' => 'geral', 'prioridade' => 'normal', 'notificar' => 1]) . '"><i class="fa-solid fa-plus"></i> Novo comunicado</button>'); ?>

    <section class="lp-stack" data-filter>
        <div class="lp-toolbar">
            <div class="lp-chips" role="group" aria-label="Filtrar comunicados">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos</button>
                <button type="button" class="lp-chip" data-filter-value="publicado" aria-pressed="false">Publicados</button>
                <button type="button" class="lp-chip" data-filter-value="rascunho" aria-pressed="false">Rascunhos</button>
            </div>
        </div>

        <?php foreach ($comunicados as $c):
            [$prioRotulo, $prioTipo] = $prioridades[$c['prioridade']] ?? $prioridades['normal'];
        ?>
        <article class="lp-card lp-pad" data-filter-item data-tags="<?= pub_e($c['status']) ?>" <?= $c['fixado'] ? 'style="border-color:var(--accent);"' : '' ?>>
            <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                <div style="min-width:0;flex:1;">
                    <div class="lp-chips" style="margin-bottom:10px;">
                        <?php if ($c['fixado']): ?><span class="lp-badge lp-badge--accent"><i class="fa-solid fa-thumbtack"></i> Fixado</span><?php endif; ?>
                        <span class="lp-badge lp-badge--info"><?= pub_e($categorias[$c['categoria']] ?? ucfirst((string) $c['categoria'])) ?></span>
                        <?php if ($prioTipo !== ''): ?><span class="lp-badge lp-badge--<?= $prioTipo ?>"><?= $prioRotulo ?></span><?php endif; ?>
                        <?php if ($c['status'] !== 'publicado'): ?><span class="lp-badge">Rascunho</span><?php endif; ?>
                    </div>
                    <h2 style="font-size:19px;font-weight:700;color:var(--text);line-height:1.3;"><?= pub_e($c['titulo']) ?></h2>
                    <p style="margin-top:8px;font-size:15px;line-height:1.65;color:var(--text-secondary);"><?= nl2br(pub_e($c['conteudo'])) ?></p>
                    <div class="lp-game-meta" style="margin-top:12px;">
                        <span><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($c['data_publicacao'] ?: $c['created_at'], 'd/m/Y H:i')) ?></span>
                        <?php if (!empty($c['autor'])): ?><span><i class="fa-solid fa-pen-nib"></i><?= pub_e($c['autor']) ?></span><?php endif; ?>
                    </div>
                </div>
                <span class="pn-row-actions">
                    <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="pin"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="pn-icon-btn" title="<?= $c['fixado'] ? 'Desafixar' : 'Fixar no topo' ?>" aria-label="<?= $c['fixado'] ? 'Desafixar' : 'Fixar no topo' ?>"><i class="fa-solid fa-thumbtack"></i></button></form>
                    <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar comunicado" data-modal="#comunicadoModal" data-values="<?= pn_values([
                        'id' => (int) $c['id'], 'janela' => 'Editar comunicado', 'titulo' => $c['titulo'], 'conteudo' => $c['conteudo'], 'categoria' => $c['categoria'],
                        'prioridade' => $c['prioridade'], 'status' => $c['status'] === 'publicado' ? 'publicado' : 'rascunho', 'fixado' => (int) $c['fixado'], 'notificar' => 0,
                    ]) ?>"><i class="fa-solid fa-pen"></i></button>
                    <form method="post" data-confirm="Remover o comunicado '<?= pub_e($c['titulo']) ?>'?"><?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover comunicado"><i class="fa-solid fa-trash"></i></button></form>
                </span>
            </div>
        </article>
        <?php endforeach; ?>

        <p class="lp-empty" data-filter-empty <?= $comunicados ? 'hidden' : '' ?>><?= $comunicados ? 'Nenhum comunicado neste filtro.' : 'Ainda não há comunicados. Escreva o primeiro.' ?></p>
    </section>
</div>

<dialog class="pn-modal" id="comunicadoModal">
    <form method="post">
        <div class="pn-modal-head"><h2 data-text="janela">Comunicado</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
            <div class="pn-form-grid">
                <div class="lp-field full"><label for="c-titulo">Título</label><input id="c-titulo" name="titulo" type="text" maxlength="255" required></div>
                <div class="lp-field full"><label for="c-conteudo">Conteúdo</label><textarea id="c-conteudo" name="conteudo" required></textarea></div>
                <div class="lp-field"><label for="c-cat">Categoria</label>
                    <select id="c-cat" name="categoria"><?php foreach ($categorias as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field"><label for="c-prio">Prioridade</label>
                    <select id="c-prio" name="prioridade"><?php foreach ($prioridades as $k => [$v]): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field full"><label for="c-status">Estado</label>
                    <select id="c-status" name="status"><option value="publicado">Publicar agora</option><option value="rascunho">Guardar como rascunho</option></select>
                </div>
                <label class="lp-check"><input type="checkbox" name="fixado" value="1"><span>Fixar no topo</span></label>
                <label class="lp-check"><input type="checkbox" name="notificar" value="1"><span>Notificar a equipa ao publicar</span></label>
            </div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
