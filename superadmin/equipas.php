<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Equipas
 * Criar, editar, ativar/desativar e apagar equipas. Só o superadmin o pode fazer.
 * O número máximo de equipas vem de system_config.max_teams.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require_once __DIR__ . '/../includes/upload_helper.php';

[$pdo, $user] = panel_boot_global();

$maxEquipas = (int) ($pdo->query("SELECT config_value FROM system_config WHERE config_key = 'max_teams'")->fetchColumn() ?: 0);

/** Lê e valida os dados de uma equipa. */
$lerEquipa = function (int $ignorarId = 0) use ($pdo): array {
    $dados = [
        'name'         => trim((string) ($_POST['name'] ?? '')),
        'city'         => trim((string) ($_POST['city'] ?? '')) ?: null,
        'stadium'      => trim((string) ($_POST['stadium'] ?? '')) ?: null,
        'founded_year' => (int) ($_POST['founded_year'] ?? 0) ?: null,
        'escaloes'     => escaloes_from_post($_POST['escaloes'] ?? []),
    ];
    if ($dados['name'] === '' || mb_strlen($dados['name']) > 100) throw new RuntimeException('Indique o nome da equipa.');
    team_name_check($dados['name']);
    if ($dados['founded_year'] !== null && ($dados['founded_year'] < 1901 || $dados['founded_year'] > (int) date('Y'))) {
        throw new RuntimeException('Ano de fundação inválido.');
    }
    $stmt = $pdo->prepare('SELECT id FROM teams WHERE name = :name AND id <> :id');
    $stmt->execute(['name' => $dados['name'], 'id' => $ignorarId]);
    if ($stmt->fetch()) throw new RuntimeException('Já existe uma equipa com esse nome.');
    return $dados;
};

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['team_id'] ?? 0);

    try {
        if ($acao === 'create') {
            $existentes = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
            if ($maxEquipas > 0 && $existentes >= $maxEquipas) {
                throw new RuntimeException('Limite de ' . $maxEquipas . ' equipas atingido. Para criar mais, aumente o limite em Configurações.');
            }
            $dados = $lerEquipa();

            // conta de administrador da nova equipa (opcional)
            $adminUser  = trim((string) ($_POST['admin_username'] ?? ''));
            $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
            $adminNome  = trim((string) ($_POST['admin_name'] ?? ''));
            $adminPass  = (string) ($_POST['admin_password'] ?? '');
            $comAdmin   = $adminUser !== '' || $adminEmail !== '' || $adminPass !== '';
            if ($comAdmin) {
                if ($adminNome === '') throw new RuntimeException('Indique o nome do administrador.');
                if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $adminUser)) throw new RuntimeException('Utilizador do administrador inválido.');
                if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email do administrador inválido.');
                if (strlen($adminPass) < 8) throw new RuntimeException('A palavra-passe do administrador deve ter pelo menos 8 caracteres.');
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
                $stmt->execute(['u' => $adminUser, 'e' => $adminEmail]);
                if ($stmt->fetch()) throw new RuntimeException('Já existe uma conta com esse utilizador ou email.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO teams (name, city, stadium, founded_year, escaloes, logo_initials) VALUES (:name, :city, :stadium, :founded_year, :escaloes, :initials)'
            );
            $stmt->execute($dados + ['initials' => mb_strtoupper(mb_substr($dados['name'], 0, 2))]);
            $novoId = (int) $pdo->lastInsertId();
            if (upload_has('logo')) {   // o emblema guarda-se na pasta do clube, que só existe depois de ele ter número
                $pdo->prepare('UPDATE teams SET logo_url = :logo WHERE id = :id')->execute(['logo' => upload_image('logo', 'emblema', $novoId), 'id' => $novoId]);
            }
            if ($comAdmin) {
                $pdo->prepare(
                    "INSERT INTO users (name, username, email, password_hash, role, team_id, avatar_initials)
                     VALUES (:name, :username, :email, :hash, 'admin', :team_id, :initials)"
                )->execute([
                    'name' => $adminNome, 'username' => $adminUser, 'email' => $adminEmail,
                    'hash' => password_hash($adminPass, PASSWORD_DEFAULT), 'team_id' => $novoId,
                    'initials' => mb_strtoupper(mb_substr($adminNome, 0, 2)),
                ]);
            }
            $pdo->commit();
            panel_log_global($pdo, 'equipa_criada', $dados['name'], $novoId);
            flash_set('success', 'Equipa criada' . ($comAdmin ? ' com a conta de administrador ' . $adminUser . '.' : '. Falta atribuir-lhe um administrador em Utilizadores.'));

        } elseif ($acao === 'update') {
            $stmt = $pdo->prepare('SELECT logo_url FROM teams WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $logoAntigo = $stmt->fetchColumn();
            if ($logoAntigo === false) throw new RuntimeException('Equipa não encontrada.');
            $dados = $lerEquipa($id);
            // não se tira um escalão que ainda tem jogadores
            $stmt = $pdo->prepare('SELECT DISTINCT escalao FROM players WHERE team_id = :id');
            $stmt->execute(['id' => $id]);
            $emFalta = array_diff($stmt->fetchAll(PDO::FETCH_COLUMN), explode(',', $dados['escaloes']));
            if ($emFalta) throw new RuntimeException('Ainda há jogadores no escalão ' . implode(', ', array_map('escalao_label', $emFalta)) . '. Mude-os de escalão antes de o retirar.');
            // sem ficheiro novo mantém-se o emblema atual
            $dados['logo_url'] = upload_has('logo') ? upload_image('logo', 'emblema', $id) : ($logoAntigo ?: null);
            $stmt = $pdo->prepare(
                'UPDATE teams SET name = :name, city = :city, stadium = :stadium, founded_year = :founded_year, escaloes = :escaloes, logo_url = :logo_url, logo_initials = :initials WHERE id = :id'
            );
            $stmt->execute($dados + ['id' => $id, 'initials' => mb_strtoupper(mb_substr($dados['name'], 0, 2))]);
            if ($dados['logo_url'] !== ($logoAntigo ?: null)) upload_delete($logoAntigo ?: null, $id);
            panel_log_global($pdo, 'equipa_atualizada', $dados['name'], $id);
            flash_set('success', 'Equipa atualizada.');

        } elseif ($acao === 'toggle') {
            $pdo->prepare("UPDATE teams SET status = IF(status = 'active', 'inactive', 'active') WHERE id = :id")->execute(['id' => $id]);
            panel_log_global($pdo, 'equipa_estado_alterado', '#' . $id, $id);
            flash_set('success', 'Estado da equipa alterado.');

        } elseif ($acao === 'delete') {
            $stmt = $pdo->prepare('SELECT name FROM teams WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $nome = $stmt->fetchColumn();
            if ($nome === false) throw new RuntimeException('Equipa não encontrada.');
            // proteção contra enganos: é preciso escrever o nome exato da equipa
            if (trim((string) ($_POST['confirm_name'] ?? '')) !== $nome) {
                throw new RuntimeException('Para apagar, escreva o nome exato da equipa: ' . $nome);
            }
            $pdo->beginTransaction();
            // as presenças não têm ligação direta à equipa: saem com os jogadores (ON DELETE CASCADE)
            $pdo->prepare('DELETE FROM users WHERE team_id = :id')->execute(['id' => $id]);   // as contas da equipa
            $pdo->prepare('DELETE FROM teams WHERE id = :id')->execute(['id' => $id]);        // o resto sai em cascata
            $pdo->commit();
            panel_log_global($pdo, 'equipa_apagada', (string) $nome);
            flash_set('success', 'A equipa ' . $nome . ' e todos os seus dados foram apagados.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/equipas: ' . $e->getMessage());
    }
    panel_redirect('equipas.php');
}

$equipas = $pdo->query(
    "SELECT t.*,
            (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id) AS contas,
            (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
            (SELECT COUNT(*) FROM games g WHERE g.team_id = t.id) AS jogos,
            (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') FROM users u WHERE u.team_id = t.id AND u.role = 'admin') AS admins
     FROM teams t ORDER BY t.name"
)->fetchAll();

$podeCriar = $maxEquipas <= 0 || count($equipas) < $maxEquipas;

$pageTitle = 'Equipas';
$activeNav = 'equipas';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Equipas', count($equipas) . ($maxEquipas > 0 ? ' de ' . $maxEquipas : '') . ' equipas no sistema.',
        $podeCriar
            ? '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novaEquipa" data-reset><i class="fa-solid fa-plus"></i> Nova equipa</button>'
            : '<span class="lp-badge lp-badge--gold" style="align-self:center;">Limite de ' . $maxEquipas . ' equipas atingido</span>'); ?>

    <section class="lp-grid lp-grid--wide">
        <?php foreach ($equipas as $e): $logo = pub_img($e['logo_url'], '../'); $ativa = $e['status'] === 'active'; ?>
        <article class="lp-card lp-team-card"<?= $ativa ? '' : ' style="opacity:0.7;"' ?>>
            <div class="lp-team-top">
                <div class="lp-crest"><?php if ($logo): ?><img src="<?= pub_e($logo) ?>" alt=""><?php else: ?><?= pub_initial($e['name']) ?><?php endif; ?></div>
                <div style="min-width:0;">
                    <h2 style="font-family:var(--display);font-size:24px;font-weight:800;color:var(--text);line-height:1.1;"><?= pub_e($e['name']) ?></h2>
                    <small><?= pub_e($e['city'] ?: '—') ?><?= $e['founded_year'] ? ' · desde ' . pub_e($e['founded_year']) : '' ?></small>
                </div>
                <span class="lp-badge lp-badge--<?= $ativa ? 'win' : 'loss' ?>" style="margin-left:auto;"><?= $ativa ? 'Ativa' : 'Inativa' ?></span>
            </div>

            <div class="lp-mini-stats" style="margin-top:0;">
                <div><b><?= (int) $e['contas'] ?></b><span>CONTAS</span></div>
                <div><b><?= (int) $e['jogadores'] ?></b><span>JOGADORES</span></div>
                <div><b><?= (int) $e['jogos'] ?></b><span>JOGOS</span></div>
            </div>

            <ul class="lp-info" style="gap:10px;">
                <li><span class="lp-info-icon"><i class="fa-solid fa-location-dot"></i></span><div><small>Pavilhão</small><b><?= pub_e($e['stadium'] ?: '—') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-layer-group"></i></span><div><small>Escalões</small><b><?= implode(' &bull; ', team_escaloes($e)) ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-user-shield"></i></span><div><small>Administração</small><b><?= pub_e($e['admins'] ?: 'Sem administrador') ?></b></div></li>
            </ul>

            <div class="pn-row-actions" style="justify-content:flex-start;flex-wrap:wrap;">
                <a class="lp-btn lp-btn--primary lp-btn--sm" href="../admin/dashboard.php?equipa=<?= (int) $e['id'] ?>"><i class="fa-solid fa-right-to-bracket"></i> Entrar no painel</a>
                <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar <?= pub_e($e['name']) ?>" data-modal="#editarEquipa" data-values="<?= pn_values([
                    'team_id' => (int) $e['id'], 'name' => $e['name'], 'city' => $e['city'], 'stadium' => $e['stadium'], 'founded_year' => $e['founded_year'],
                ] + array_combine(array_map(fn($k) => 'esc_' . $k, array_keys(ESCALOES)), array_map(fn($k) => isset(team_escaloes($e)[$k]) ? 1 : 0, array_keys(ESCALOES)))) ?>"><i class="fa-solid fa-pen"></i></button>
                <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="team_id" value="<?= (int) $e['id'] ?>">
                    <button type="submit" class="pn-icon-btn" title="<?= $ativa ? 'Desativar' : 'Ativar' ?>" aria-label="<?= $ativa ? 'Desativar' : 'Ativar' ?> <?= pub_e($e['name']) ?>"><i class="fa-solid fa-<?= $ativa ? 'eye-slash' : 'eye' ?>"></i></button></form>
                <button type="button" class="pn-icon-btn pn-icon-btn--danger" title="Apagar" aria-label="Apagar <?= pub_e($e['name']) ?>" data-modal="#apagarEquipa" data-reset
                        data-values="<?= pn_values(['team_id' => (int) $e['id'], 'nome' => $e['name'], 'resumo' => (int) $e['contas'] . ' contas, ' . (int) $e['jogadores'] . ' jogadores e ' . (int) $e['jogos'] . ' jogos']) ?>"><i class="fa-solid fa-trash"></i></button>
            </div>
        </article>
        <?php endforeach; ?>
        <?php if (!$equipas): ?><p class="lp-empty">Ainda não há equipas. Crie a primeira.</p><?php endif; ?>
    </section>
</div>

<?php $camposEquipa = function (string $p, bool $nova): void { ?>
    <div class="pn-form-grid">
        <div class="lp-field full"><label for="<?= $p ?>name">Nome da equipa</label><input id="<?= $p ?>name" name="name" type="text" maxlength="100" required></div>
        <div class="lp-field"><label for="<?= $p ?>city">Cidade</label><input id="<?= $p ?>city" name="city" type="text" maxlength="100"></div>
        <div class="lp-field"><label for="<?= $p ?>year">Ano de fundação</label><input id="<?= $p ?>year" name="founded_year" type="number" min="1901" max="<?= date('Y') ?>"></div>
        <div class="lp-field full"><label for="<?= $p ?>stadium">Pavilhão</label><input id="<?= $p ?>stadium" name="stadium" type="text" maxlength="100"></div>
        <fieldset class="lp-field full" style="border:0;padding:0;margin:0;"><legend style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;">Escalões do clube</legend>
            <?php foreach (ESCALOES as $k => $v): ?>
            <label class="lp-check" style="margin-bottom:6px;"><input type="checkbox" name="escaloes[]" value="<?= $k ?>"<?= $nova ? ' checked' : ' data-field="esc_' . $k . '"' ?>><span><?= $v ?></span></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="lp-field full"><label for="<?= $p ?>logo">Emblema</label><input id="<?= $p ?>logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
            <p class="pn-help">JPG, PNG, WEBP ou GIF até 4 MB.<?= $nova ? '' : ' Deixe vazio para manter o emblema atual.' ?></p></div>
    </div>
<?php }; ?>

<dialog class="pn-modal" id="novaEquipa">
    <form method="post" autocomplete="off" enctype="multipart/form-data">
        <div class="pn-modal-head"><h2>Nova equipa</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="create">
            <?php $camposEquipa('n-', true); ?>
            <h3 class="lp-sub" style="margin:8px 0 0;">Administrador da equipa (opcional)</h3>
            <p class="pn-help" style="margin-top:-8px;">Cria já a conta de quem vai gerir esta equipa. Pode fazê-lo mais tarde em Utilizadores.</p>
            <div class="pn-form-grid">
                <div class="lp-field full"><label for="n-aname">Nome</label><input id="n-aname" name="admin_name" type="text" maxlength="100"></div>
                <div class="lp-field"><label for="n-auser">Utilizador</label><input id="n-auser" name="admin_username" type="text" maxlength="50"></div>
                <div class="lp-field"><label for="n-aemail">Email</label><input id="n-aemail" name="admin_email" type="email" maxlength="100"></div>
                <div class="lp-field full"><label for="n-apass">Palavra-passe (mín. 8 caracteres)</label><input id="n-apass" name="admin_password" type="password" minlength="8" autocomplete="new-password"></div>
            </div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Criar equipa</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="editarEquipa">
    <form method="post" enctype="multipart/form-data">
        <div class="pn-modal-head"><h2>Editar equipa</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form"><?= panel_csrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="team_id"><?php $camposEquipa('e-', false); ?></div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="apagarEquipa">
    <form method="post" autocomplete="off">
        <div class="pn-modal-head"><h2>Apagar equipa</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="team_id">
            <div class="lp-notice lp-notice--error"><i class="fa-solid fa-triangle-exclamation"></i><div>
                Vai apagar <b data-text="nome"></b> e <b>tudo o que lhe pertence</b>: <span data-text="resumo"></span>, treinos, finanças, notícias, patrocinadores e mensagens.
                <b>Não é possível desfazer.</b> Faça primeiro um <a href="backup-global.php" style="color:var(--accent-text);font-weight:600;">backup</a>.
            </div></div>
            <div class="lp-field"><label for="d-confirm">Para confirmar, escreva o nome da equipa</label><input id="d-confirm" name="confirm_name" type="text" required></div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--danger lp-btn--sm"><i class="fa-solid fa-trash"></i> Apagar definitivamente</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
