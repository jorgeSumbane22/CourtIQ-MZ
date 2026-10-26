<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Admin: Patrocinadores
 * Os patrocinadores que aparecem no site público da equipa.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require_once __DIR__ . '/../includes/upload_helper.php';

[$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);

$niveis = ['ouro' => ['Ouro (principal)', 'gold'], 'prata' => ['Prata (oficial)', ''], 'bronze' => ['Bronze (parceiro)', 'accent']];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    try {
        if ($id > 0 && !team_owns('sponsors', $id)) {
            throw new RuntimeException('Patrocinador não encontrado.');
        }

        if ($acao === 'save') {
            $site = trim((string) ($_POST['website'] ?? ''));
            $dados = [
                'name'        => trim((string) ($_POST['name'] ?? '')),
                'tier'        => isset($niveis[$_POST['tier'] ?? '']) ? (string) $_POST['tier'] : 'bronze',
                'sector'      => trim((string) ($_POST['sector'] ?? '')) ?: null,
                'contact'     => trim((string) ($_POST['contact'] ?? '')) ?: null,
                'website'     => $site ?: null,
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'start_date'  => trim((string) ($_POST['start_date'] ?? '')) ?: null,
                'end_date'    => trim((string) ($_POST['end_date'] ?? '')) ?: null,
                'status'      => ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active',
            ];
            if ($dados['name'] === '' || mb_strlen($dados['name']) > 100) throw new RuntimeException('Indique o nome do patrocinador.');
            if ($site !== '' && !preg_match('#^https?://#i', $site)) throw new RuntimeException('O site tem de começar por http:// ou https://');
            if ($dados['start_date'] && $dados['end_date'] && $dados['end_date'] < $dados['start_date']) throw new RuntimeException('A data de fim tem de ser depois da data de início.');

            $logoAntigo = null;
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT logo_url FROM sponsors WHERE id = :id AND team_id = :team_id');
                $stmt->execute(['id' => $id, 'team_id' => $teamId]);
                $logoAntigo = $stmt->fetchColumn() ?: null;
            }
            $logo = upload_has('logo') ? upload_image('logo', 'patrocinadores', $teamId) : $logoAntigo;

            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE sponsors SET name = :name, tier = :tier, sector = :sector, contact = :contact, website = :website, description = :description,
                            start_date = :start_date, end_date = :end_date, status = :status, logo_url = :logo WHERE id = :id AND team_id = :team_id'
                )->execute($dados + ['logo' => $logo, 'id' => $id, 'team_id' => $teamId]);
                if ($logo !== $logoAntigo) upload_delete($logoAntigo, $teamId);
                flash_set('success', 'Patrocinador atualizado.');
            } else {
                $pdo->prepare(
                    'INSERT INTO sponsors (team_id, name, tier, sector, contact, website, description, start_date, end_date, status, logo_url)
                     VALUES (:team_id, :name, :tier, :sector, :contact, :website, :description, :start_date, :end_date, :status, :logo)'
                )->execute($dados + ['logo' => $logo, 'team_id' => $teamId]);
                panel_log($pdo, $teamId, 'patrocinador_adicionado', $dados['name']);
                flash_set('success', 'Patrocinador adicionado.');
            }

        } elseif ($acao === 'delete') {
            $stmt = $pdo->prepare('SELECT logo_url FROM sponsors WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            $logo = $stmt->fetchColumn();
            $pdo->prepare('DELETE FROM sponsors WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
            upload_delete($logo ?: null, $teamId);
            panel_log($pdo, $teamId, 'patrocinador_removido', '#' . $id);
            flash_set('success', 'Patrocinador removido.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar o patrocinador.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/patrocinadores: ' . $e->getMessage());
    }
    panel_redirect('patrocinadores.php');
}

$stmt = $pdo->prepare("SELECT * FROM sponsors WHERE team_id = :team_id ORDER BY FIELD(tier, 'ouro', 'prata', 'bronze'), name");
$stmt->execute(['team_id' => $teamId]);
$patrocinadores = $stmt->fetchAll();
$ativos   = count(array_filter($patrocinadores, fn($s) => $s['status'] === 'active'));
$aExpirar = count(array_filter($patrocinadores, fn($s) => $s['status'] === 'active' && $s['end_date'] && $s['end_date'] <= date('Y-m-d', strtotime('+60 days'))));

$pageTitle = 'Patrocinadores';
$activeNav = 'patrocinadores';
require __DIR__ . '/../includes/layout/admin_top.php';
?>

<div class="lp pn">
    <?php panel_head('Patrocinadores', $ativos . ' ativos' . ($aExpirar ? ' · ' . $aExpirar . ' com contrato a terminar em 60 dias' : '') . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../visitante/patrocinadores.php?equipa=' . $teamId . '"><i class="fa-solid fa-globe"></i> Ver no site</a>'
        . '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#patrocinadorModal" data-reset data-values="' . pn_values(['id' => 0, 'janela' => 'Novo patrocinador', 'tier' => 'bronze', 'status' => 'active']) . '"><i class="fa-solid fa-plus"></i> Novo patrocinador</button>'); ?>

    <section class="lp-card">
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Patrocinador</th><th scope="col">Nível</th><th scope="col">Contrato</th><th scope="col">Estado</th><th scope="col" class="end">Ações</th></tr></thead>
            <tbody>
                <?php foreach ($patrocinadores as $s):
                    [$nivel, $tipo] = $niveis[$s['tier']] ?? $niveis['bronze'];
                    $logo = pub_img($s['logo_url'], '../');
                    $fim  = $s['end_date'] && $s['end_date'] <= date('Y-m-d', strtotime('+60 days'));
                ?>
                <tr<?= $s['status'] === 'active' ? '' : ' style="opacity:0.6;"' ?>>
                    <td><div class="pn-user">
                        <?php if ($logo): ?><img class="lp-avatar lp-avatar--sm" src="<?= pub_e($logo) ?>" alt="" style="border-radius:8px;object-fit:contain;background:#fff;">
                        <?php else: ?><span class="lp-avatar lp-avatar--sm" style="border-radius:8px;"><?= pub_initial($s['name']) ?></span><?php endif; ?>
                        <div><b><?= pub_e($s['name']) ?></b><small><?= pub_e($s['sector'] ?: '—') ?><?= $s['contact'] ? ' · ' . pub_e($s['contact']) : '' ?></small></div></div></td>
                    <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= pub_e(ucfirst($s['tier'])) ?></span></td>
                    <td><?= $s['start_date'] ? pub_e(pub_date($s['start_date'])) : '—' ?> → <?= $s['end_date'] ? pub_e(pub_date($s['end_date'])) : 'sem fim' ?>
                        <?= $fim && $s['status'] === 'active' ? '<small style="color:var(--red);">A terminar</small>' : '' ?></td>
                    <td><span class="lp-badge lp-badge--<?= $s['status'] === 'active' ? 'win' : '' ?>"><?= $s['status'] === 'active' ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="end"><span class="pn-row-actions">
                        <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar <?= pub_e($s['name']) ?>" data-modal="#patrocinadorModal" data-reset data-values="<?= pn_values([
                            'id' => (int) $s['id'], 'janela' => 'Editar patrocinador', 'name' => $s['name'], 'tier' => $s['tier'], 'sector' => $s['sector'], 'contact' => $s['contact'],
                            'website' => $s['website'], 'description' => $s['description'], 'start_date' => $s['start_date'], 'end_date' => $s['end_date'], 'status' => $s['status'],
                        ]) ?>"><i class="fa-solid fa-pen"></i></button>
                        <form method="post" data-confirm="Remover o patrocinador <?= pub_e($s['name']) ?>?">
                            <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover <?= pub_e($s['name']) ?>"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (!$patrocinadores): ?><div class="pn-empty"><i class="fa-solid fa-handshake"></i>Ainda não há patrocinadores. Adicione o primeiro.</div><?php endif; ?>
    </section>
</div>

<dialog class="pn-modal pn-modal--wide" id="patrocinadorModal">
    <form method="post" enctype="multipart/form-data">
        <div class="pn-modal-head"><h2 data-text="janela">Patrocinador</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
            <div class="pn-form-grid">
                <div class="lp-field"><label for="s-name">Nome</label><input id="s-name" name="name" type="text" maxlength="100" required></div>
                <div class="lp-field"><label for="s-tier">Nível</label>
                    <select id="s-tier" name="tier"><?php foreach ($niveis as $k => [$v]): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                <div class="lp-field"><label for="s-sector">Setor</label><input id="s-sector" name="sector" type="text" maxlength="100" placeholder="Banca, Bebidas..."></div>
                <div class="lp-field"><label for="s-contact">Contacto</label><input id="s-contact" name="contact" type="text" maxlength="150"></div>
                <div class="lp-field full"><label for="s-site">Site</label><input id="s-site" name="website" type="url" maxlength="255" placeholder="https://"></div>
                <div class="lp-field full"><label for="s-desc">Descrição</label><textarea id="s-desc" name="description" style="min-height:80px;"></textarea></div>
                <div class="lp-field"><label for="s-start">Início do contrato</label><input id="s-start" name="start_date" type="date"></div>
                <div class="lp-field"><label for="s-end">Fim do contrato</label><input id="s-end" name="end_date" type="date"></div>
                <div class="lp-field"><label for="s-status">Estado</label>
                    <select id="s-status" name="status"><option value="active">Ativo (aparece no site)</option><option value="inactive">Inativo (escondido)</option></select></div>
                <div class="lp-field"><label for="s-logo">Logótipo</label><input id="s-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                    <p class="pn-help">Até 4 MB. Ao editar, deixe vazio para manter o atual.</p></div>
            </div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/../includes/layout/admin_bottom.php'; ?>
