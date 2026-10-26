<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Treinador: Táticas
 * Caderno tático da equipa: sistemas e jogadas, por categoria e prioridade.
 */

$panel = 'treinador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$categorias  = ['ofensiva' => 'Ofensiva', 'defensiva' => 'Defensiva', 'transicao' => 'Transição', 'especial' => 'Situações especiais'];
$prioridades = [1 => ['Base', ''], 2 => ['Importante', 'accent'], 3 => ['Prioritária', 'loss']];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    try {
        // as táticas não têm entrada em team_owns(): o dono confirma-se sempre com team_id na própria consulta
        if ($acao === 'save') {
            $dados = [
                'nome'       => trim((string) ($_POST['nome'] ?? '')),
                'categoria'  => isset($categorias[$_POST['categoria'] ?? '']) ? (string) $_POST['categoria'] : 'ofensiva',
                'prioridade' => isset($prioridades[(int) ($_POST['prioridade'] ?? 1)]) ? (int) $_POST['prioridade'] : 1,
                'objetivo'   => trim((string) ($_POST['objetivo'] ?? '')) ?: null,
                'descricao'  => trim((string) ($_POST['descricao'] ?? '')) ?: null,
            ];
            if ($dados['nome'] === '' || mb_strlen($dados['nome']) > 100) throw new RuntimeException('Indique o nome da tática.');

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE taticas SET nome = :nome, categoria = :categoria, prioridade = :prioridade, objetivo = :objetivo, descricao = :descricao
                     WHERE id = :id AND team_id = :team_id'
                );
                $stmt->execute($dados + ['id' => $id, 'team_id' => $teamId]);
                if ($stmt->rowCount() === 0) {
                    $existe = $pdo->prepare('SELECT 1 FROM taticas WHERE id = :id AND team_id = :team_id');
                    $existe->execute(['id' => $id, 'team_id' => $teamId]);
                    if (!$existe->fetch()) throw new RuntimeException('Tática não encontrada.');
                }
                flash_set('success', 'Tática atualizada.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO taticas (team_id, nome, categoria, prioridade, objetivo, descricao)
                     VALUES (:team_id, :nome, :categoria, :prioridade, :objetivo, :descricao)'
                );
                $stmt->execute($dados + ['team_id' => $teamId]);
                panel_log($pdo, $teamId, 'tatica_criada', $dados['nome']);
                flash_set('success', 'Tática adicionada ao caderno.');
            }

        } elseif ($acao === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM taticas WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            if ($stmt->rowCount() === 0) throw new RuntimeException('Tática não encontrada.');
            flash_set('success', 'Tática removida.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] treinador/taticas: ' . $e->getMessage());
    }
    panel_redirect('taticas.php');
}

$stmt = $pdo->prepare('SELECT * FROM taticas WHERE team_id = :team_id ORDER BY prioridade DESC, nome');
$stmt->execute(['team_id' => $teamId]);
$taticas = $stmt->fetchAll();
$porCategoria = array_count_values(array_column($taticas, 'categoria'));

$pageTitle = 'Táticas';
$activeNav = 'taticas';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Táticas', count($taticas) . ' sistemas e jogadas no caderno de ' . $team['name'] . '.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#taticaModal" data-reset data-values="' . pn_values(['id' => 0, 'titulo' => 'Nova tática', 'prioridade' => 1, 'categoria' => 'ofensiva']) . '"><i class="fa-solid fa-plus"></i> Nova tática</button>'); ?>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Procurar tática..." aria-label="Procurar tática"></div>
            <div class="lp-chips" role="group" aria-label="Filtrar por categoria">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todas <small><?= count($taticas) ?></small></button>
                <?php foreach ($categorias as $chave => $rotulo): if (empty($porCategoria[$chave])) continue; ?>
                <button type="button" class="lp-chip" data-filter-value="<?= $chave ?>" aria-pressed="false"><?= pub_e($rotulo) ?> <small><?= (int) $porCategoria[$chave] ?></small></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="lp-grid lp-grid--wide">
            <?php foreach ($taticas as $t):
                [$prioRotulo, $prioTipo] = $prioridades[(int) $t['prioridade']] ?? $prioridades[1];
            ?>
            <article class="lp-card lp-sponsor-card" data-filter-item data-tags="<?= pub_e($t['categoria']) ?>" data-name="<?= pub_e(mb_strtolower($t['nome'] . ' ' . $t['objetivo'] . ' ' . $t['descricao'])) ?>">
                <div class="lp-chips">
                    <span class="lp-badge lp-badge--info"><?= pub_e($categorias[$t['categoria']] ?? ucfirst((string) $t['categoria'])) ?></span>
                    <span class="lp-badge lp-badge--<?= $prioTipo ?>"><?= $prioRotulo ?></span>
                </div>
                <h2 style="font-size:19px;font-weight:700;color:var(--text);"><?= pub_e($t['nome']) ?></h2>
                <?php if (!empty($t['objetivo'])): ?><p><b style="color:var(--text);">Objetivo:</b> <?= pub_e($t['objetivo']) ?></p><?php endif; ?>
                <?php if (!empty($t['descricao'])): ?><p><?= nl2br(pub_e($t['descricao'])) ?></p><?php endif; ?>
                <div class="pn-row-actions" style="margin-top:auto;justify-content:flex-start;">
                    <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal="#taticaModal" data-values="<?= pn_values([
                        'id' => (int) $t['id'], 'titulo' => 'Editar tática', 'nome' => $t['nome'], 'categoria' => $t['categoria'],
                        'prioridade' => (int) $t['prioridade'], 'objetivo' => $t['objetivo'], 'descricao' => $t['descricao'],
                    ]) ?>"><i class="fa-solid fa-pen"></i> Editar</button>
                    <form method="post" data-confirm="Remover a tática '<?= pub_e($t['nome']) ?>'?">
                        <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover tática"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>

            <p class="lp-empty" data-filter-empty <?= $taticas ? 'hidden' : '' ?>><?= $taticas ? 'Nenhuma tática corresponde à pesquisa.' : 'O caderno tático está vazio. Adicione a primeira tática.' ?></p>
        </div>
    </section>
</div>

<dialog class="pn-modal" id="taticaModal">
    <form method="post">
        <div class="pn-modal-head"><h2 data-text="titulo">Tática</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
            <div class="pn-form-grid">
                <div class="lp-field full"><label for="t-nome">Nome</label><input id="t-nome" name="nome" type="text" maxlength="100" required placeholder="Pick and roll central"></div>
                <div class="lp-field"><label for="t-cat">Categoria</label>
                    <select id="t-cat" name="categoria"><?php foreach ($categorias as $k => $v): ?><option value="<?= $k ?>"><?= pub_e($v) ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field"><label for="t-prio">Prioridade</label>
                    <select id="t-prio" name="prioridade"><?php foreach ($prioridades as $k => [$v]): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field full"><label for="t-obj">Objetivo</label><input id="t-obj" name="objetivo" type="text" maxlength="255" placeholder="Criar lançamento livre para o extremo"></div>
                <div class="lp-field full"><label for="t-desc">Descrição / movimentações</label><textarea id="t-desc" name="descricao"></textarea></div>
            </div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
