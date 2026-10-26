<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Admin: Equipa
 * Dados do clube e equipa técnica. O administrador só edita a sua própria equipa.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require_once __DIR__ . '/../includes/upload_helper.php';
require_once __DIR__ . '/../includes/password_reset.php';

[$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');

    try {
        if ($acao === 'update_team') {
            $dados = [
                'name'         => trim((string) ($_POST['name'] ?? '')),
                'city'         => trim((string) ($_POST['city'] ?? '')) ?: null,
                'stadium'      => trim((string) ($_POST['stadium'] ?? '')) ?: null,
                'founded_year' => (int) ($_POST['founded_year'] ?? 0) ?: null,
                'category'     => trim((string) ($_POST['category'] ?? '')) ?: 'Sénior',
                'league'       => trim((string) ($_POST['league'] ?? '')) ?: 'Liga Nacional',
            ];
            if ($dados['name'] === '' || mb_strlen($dados['name']) > 100) throw new RuntimeException('Indique o nome da equipa.');
            team_name_check($dados['name']);
            if ($dados['founded_year'] !== null && ($dados['founded_year'] < 1901 || $dados['founded_year'] > (int) date('Y'))) {
                throw new RuntimeException('Ano de fundação inválido.');
            }
            $stmt = $pdo->prepare('SELECT id FROM teams WHERE name = :name AND id <> :id');
            $stmt->execute(['name' => $dados['name'], 'id' => $teamId]);
            if ($stmt->fetch()) throw new RuntimeException('Já existe outra equipa com esse nome.');

            $dados['escaloes'] = escaloes_from_post($_POST['escaloes'] ?? []);
            // não se tira um escalão que ainda tem jogadores
            $stmt = $pdo->prepare('SELECT DISTINCT escalao FROM players WHERE team_id = :team_id');
            $stmt->execute(['team_id' => $teamId]);
            $emFalta = array_diff($stmt->fetchAll(PDO::FETCH_COLUMN), explode(',', $dados['escaloes']));
            if ($emFalta) throw new RuntimeException('Ainda há jogadores no escalão ' . implode(', ', array_map('escalao_label', $emFalta)) . '. Mude-os de escalão antes de o retirar.');

            // sem ficheiro novo mantém-se o emblema atual
            $logoAntigo = $team['logo_url'] ?? null;
            $dados['logo_url'] = upload_has('logo') ? upload_image('logo', 'emblema', $teamId) : $logoAntigo;

            $stmt = $pdo->prepare(
                'UPDATE teams SET name = :name, city = :city, stadium = :stadium, founded_year = :founded_year,
                        category = :category, league = :league, escaloes = :escaloes, logo_url = :logo_url, logo_initials = :initials
                 WHERE id = :id'
            );
            $stmt->execute($dados + ['id' => $teamId, 'initials' => mb_strtoupper(mb_substr($dados['name'], 0, 2))]);
            if ($dados['logo_url'] !== $logoAntigo) upload_delete($logoAntigo, $teamId);
            panel_log($pdo, $teamId, 'equipa_atualizada', $dados['name']);
            flash_set('success', 'Dados da equipa atualizados.');

        } elseif ($acao === 'add_coach') {
            $nome = trim((string) ($_POST['coach_name'] ?? ''));
            if ($nome === '' || mb_strlen($nome) > 100) throw new RuntimeException('Indique o nome do elemento da equipa técnica.');
            $stmt = $pdo->prepare(
                'INSERT INTO coaches (team_id, name, role_title, bio, experience_years, certification)
                 VALUES (:team_id, :name, :role_title, :bio, :experience_years, :certification)'
            );
            $stmt->execute([
                'team_id'          => $teamId,
                'name'             => $nome,
                'role_title'       => trim((string) ($_POST['role_title'] ?? '')) ?: 'Treinador',
                'bio'              => trim((string) ($_POST['bio'] ?? '')) ?: null,
                'experience_years' => max(0, min(70, (int) ($_POST['experience_years'] ?? 0))),
                'certification'    => trim((string) ($_POST['certification'] ?? '')) ?: null,
            ]);
            panel_log($pdo, $teamId, 'tecnico_adicionado', $nome);
            flash_set('success', 'Elemento adicionado à equipa técnica.');

        } elseif ($acao === 'coach_escaloes') {
            // escalões que um treinador deste clube treina
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = :id AND team_id = :team_id AND role = 'treinador'");
            $stmt->execute(['id' => (int) ($_POST['user_id'] ?? 0), 'team_id' => $teamId]);
            $treinador = $stmt->fetch();
            if (!$treinador) throw new RuntimeException('Treinador não encontrado neste clube.');
            $escolhidos = array_keys(array_intersect_key(team_escaloes($team), array_flip(array_map('strval', (array) ($_POST['escaloes'] ?? [])))));
            if (!$escolhidos) throw new RuntimeException('Escolha pelo menos um escalão para o treinador.');
            // todos os do clube = sem restrição (guarda-se vazio, para acompanhar escalões que o clube venha a ter)
            $guardar = count($escolhidos) === count(team_escaloes($team)) ? null : implode(',', $escolhidos);
            $pdo->prepare('UPDATE users SET escaloes = :e WHERE id = :id AND team_id = :team_id')->execute(['e' => $guardar, 'id' => (int) $treinador['id'], 'team_id' => $teamId]);
            panel_log($pdo, $teamId, 'treinador_escaloes', $treinador['name'] . ': ' . implode(', ', $escolhidos));
            flash_set('success', $treinador['name'] . ' passa a treinar: ' . implode(', ', array_map('escalao_label', $escolhidos)) . '.');

        } elseif ($acao === 'reset_link') {
            // ligação de uso único para um treinador ou jogador desta equipa escolher palavra-passe nova
            $stmt = $pdo->prepare("SELECT id, name, username, email, role, team_id FROM users WHERE id = :id AND team_id = :team_id AND role IN ('treinador', 'jogador') AND status = 'active'");
            $stmt->execute(['id' => (int) ($_POST['user_id'] ?? 0), 'team_id' => $teamId]);
            $conta = $stmt->fetch();
            if (!$conta) throw new RuntimeException('Conta não encontrada nesta equipa.');

            $token = pr_create($pdo, (int) $conta['id'], PR_TTL_ADMIN, (int) $user['id']);
            $_SESSION['pr_link'] = ['name' => $conta['name'], 'username' => $conta['username'], 'link' => pr_link($token)];
            panel_log($pdo, $teamId, 'password_reset_gerado', $conta['username']);

        } elseif ($acao === 'delete_coach') {
            $id = (int) ($_POST['coach_id'] ?? 0);
            if (!team_owns('coaches', $id)) throw new RuntimeException('Elemento não encontrado.');
            $pdo->prepare('DELETE FROM coaches WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
            panel_log($pdo, $teamId, 'tecnico_removido', '#' . $id);
            flash_set('success', 'Elemento removido da equipa técnica.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/equipas: ' . $e->getMessage());
    }
    panel_redirect('equipas.php');
}

$stmt = $pdo->prepare('SELECT c.*, COALESCE(u.name, c.name) AS display_name, u.username FROM coaches c LEFT JOIN users u ON u.id = c.user_id WHERE c.team_id = :team_id ORDER BY c.id');
$stmt->execute(['team_id' => $teamId]);
$tecnicos = $stmt->fetchAll();

$membros = get_team_members($teamId);
$stats   = TeamHelper::getTeamStats($teamId);
$logo    = pub_img($team['logo_url'] ?? null, '../');
$papeis  = ['admin' => 'Administrador', 'treinador' => 'Treinador', 'jogador' => 'Jogador'];

// ligação acabada de gerar: mostra-se uma única vez
$ligacao = $_SESSION['pr_link'] ?? null;
unset($_SESSION['pr_link']);

$pageTitle = 'Equipa';
$activeNav = 'equipa';
require __DIR__ . '/../includes/layout/admin_top.php';
?>

<div class="lp pn">
    <?php panel_head($team['name'], 'Dados do clube, equipa técnica e contas com acesso.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../index.php?equipa=' . $teamId . '"><i class="fa-solid fa-globe"></i> Ver site público</a>'); ?>

    <?php if ($ligacao): ?>
    <section class="lp-card lp-pad" role="status" style="border-color:var(--accent);">
        <b style="color:var(--text);"><i class="fa-solid fa-key" style="color:var(--accent);margin-right:8px;"></i>Ligação para <?= pub_e($ligacao['name']) ?> (<?= pub_e($ligacao['username']) ?>) escolher palavra-passe nova</b>
        <p class="pn-help" style="margin:6px 0 12px;">Envie-a à pessoa por um canal seguro. É válida durante 24 horas, só pode ser usada uma vez e não volta a ser mostrada.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;" class="lp-form">
            <input id="prLink" type="text" readonly value="<?= pub_e($ligacao['link']) ?>" aria-label="Ligação de recuperação" style="flex:1 1 320px;font-family:ui-monospace,Consolas,monospace;font-size:12px;" onfocus="this.select()">
            <button type="button" class="lp-btn lp-btn--primary lp-btn--sm" onclick="var i=document.getElementById('prLink');i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){document.execCommand('copy')});this.lastChild.textContent=' Copiado';"><i class="fa-solid fa-copy"></i> Copiar</button>
        </div>
    </section>
    <?php endif; ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi"><b><?= (int) $stats['total_jogadores'] ?></b><span>Jogadores</span></div>
        <div class="lp-card lp-kpi"><b><?= count($tecnicos) ?></b><span>Equipa técnica</span></div>
        <div class="lp-card lp-kpi"><b><?= count($membros) ?></b><span>Contas com acesso</span></div>
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= (int) $stats['total_vitorias'] ?>–<?= (int) $stats['total_derrotas'] ?></b><span>Vitórias–Derrotas</span></div>
    </section>

    <div class="pn-cols pn-cols--even">
        <form method="post" class="lp-card" enctype="multipart/form-data">
            <div class="pn-section-title"><h2><i class="fa-solid fa-shield-halved"></i>Dados do clube</h2></div>
            <div class="lp-pad lp-form">
                <?= panel_csrf() ?><input type="hidden" name="action" value="update_team">
                <?php if ($logo): ?><img src="<?= pub_e($logo) ?>" alt="Emblema" style="width:96px;height:96px;border-radius:16px;object-fit:cover;border:1px solid var(--border);"><?php endif; ?>
                <div class="pn-form-grid">
                    <div class="lp-field full"><label for="t-name">Nome da equipa</label><input id="t-name" name="name" type="text" maxlength="100" required value="<?= pub_e($team['name']) ?>"></div>
                    <div class="lp-field"><label for="t-city">Cidade</label><input id="t-city" name="city" type="text" maxlength="100" value="<?= pub_e($team['city']) ?>"></div>
                    <div class="lp-field"><label for="t-year">Ano de fundação</label><input id="t-year" name="founded_year" type="number" min="1901" max="<?= date('Y') ?>" value="<?= pub_e($team['founded_year']) ?>"></div>
                    <div class="lp-field full"><label for="t-stadium">Pavilhão</label><input id="t-stadium" name="stadium" type="text" maxlength="100" value="<?= pub_e($team['stadium']) ?>"></div>
                    <fieldset class="lp-field" style="border:0;padding:0;margin:0;">
                        <legend style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;">Escalões do clube</legend>
                        <?php $temEscaloes = team_escaloes($team); foreach (ESCALOES as $k => $v): ?>
                        <label class="lp-check" style="margin-bottom:6px;"><input type="checkbox" name="escaloes[]" value="<?= $k ?>" <?= isset($temEscaloes[$k]) ? 'checked' : '' ?>><span><?= $v ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <div class="lp-field"><label for="t-league">Competição</label><input id="t-league" name="league" type="text" maxlength="100" value="<?= pub_e($team['league'] ?? 'Liga Nacional') ?>"></div>
                    <div class="lp-field full"><label for="t-logo">Emblema</label><input id="t-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                        <p class="pn-help">JPG, PNG, WEBP ou GIF até 4 MB. Deixe vazio para manter o emblema atual.</p></div>
                </div>
                <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar dados</button></div>
            </div>
        </form>

        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card">
                <div class="pn-section-title">
                    <h2><i class="fa-solid fa-chalkboard-user"></i>Equipa técnica</h2>
                    <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal="#novoTecnico" data-reset><i class="fa-solid fa-plus"></i> Adicionar</button>
                </div>
                <div class="pn-list">
                    <?php foreach ($tecnicos as $t): ?>
                    <div>
                        <span class="lp-avatar lp-avatar--sm"><?= pub_initial($t['display_name']) ?></span>
                        <div class="grow"><b><?= pub_e($t['display_name']) ?></b><small><?= pub_e($t['role_title'] ?: 'Treinador') ?><?= (int) $t['experience_years'] ? ' · ' . (int) $t['experience_years'] . ' anos' : '' ?><?= $t['username'] ? ' · conta ' . pub_e($t['username']) : '' ?></small></div>
                        <?php if (!$t['user_id']): // quem tem conta de treinador não se remove por aqui ?>
                        <form method="post" data-confirm="Remover <?= pub_e($t['display_name']) ?> da equipa técnica?">
                            <?= panel_csrf() ?><input type="hidden" name="action" value="delete_coach"><input type="hidden" name="coach_id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover <?= pub_e($t['display_name']) ?>"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$tecnicos): ?><div class="pn-empty"><i class="fa-solid fa-chalkboard-user"></i>Ainda não há equipa técnica registada.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-user-lock"></i>Contas com acesso</h2><small><?= count($membros) ?> contas</small></div>
                <div class="pn-list">
                    <?php foreach ($membros as $m): ?>
                    <div>
                        <span class="lp-avatar lp-avatar--sm"><?= pub_initial($m['name']) ?></span>
                        <?php $escTreinador = $m['role'] === 'treinador' ? coach_escaloes($team, (string) ($m['escaloes'] ?? '')) : []; ?>
                        <div class="grow"><b><?= pub_e($m['name']) ?></b><small><?= pub_e($m['username']) ?> · <?= pub_e($m['email']) ?></small>
                            <?php if ($escTreinador): ?><small>Treina: <?= pub_e(implode(', ', $escTreinador)) ?></small><?php endif; ?></div>
                        <span class="lp-badge lp-badge--<?= $m['role'] === 'admin' ? 'accent' : ($m['role'] === 'treinador' ? 'info' : '') ?>"><?= pub_e($papeis[$m['role']] ?? $m['role']) ?></span>
                        <?php if ($m['role'] === 'treinador'): ?>
                        <button type="button" class="pn-icon-btn" title="Escalões que treina" aria-label="Escalões de <?= pub_e($m['name']) ?>" data-modal="#escaloesTreinador"
                                data-values="<?= pn_values(['user_id' => (int) $m['id'], 'quem' => $m['name']] + array_combine(array_map(fn($k) => 'esc_' . $k, array_keys(ESCALOES)), array_map(fn($k) => isset($escTreinador[$k]) ? 1 : 0, array_keys(ESCALOES)))) ?>"><i class="fa-solid fa-layer-group"></i></button>
                        <?php endif; ?>
                        <?php if (in_array($m['role'], ['treinador', 'jogador'], true) && ($m['status'] ?? 'active') === 'active'): ?>
                        <form method="post" data-confirm="Gerar uma ligação para <?= pub_e($m['name']) ?> escolher uma palavra-passe nova?">
                            <?= panel_csrf() ?><input type="hidden" name="action" value="reset_link"><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="pn-icon-btn" title="Repor palavra-passe" aria-label="Repor palavra-passe de <?= pub_e($m['name']) ?>"><i class="fa-solid fa-key"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<dialog class="pn-modal" id="escaloesTreinador">
    <form method="post">
        <div class="pn-modal-head"><h2>Escalões do treinador</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="coach_escaloes"><input type="hidden" name="user_id">
            <p style="color:var(--text-secondary);">Escalões que <b data-text="quem" style="color:var(--text);"></b> treina. É com um deles que entra no login, e o seu painel só mostra estes.</p>
            <?php foreach (team_escaloes($team) as $k => $v): ?>
            <label class="lp-check"><input type="checkbox" name="escaloes[]" value="<?= $k ?>" data-field="esc_<?= $k ?>"><span><?= $v ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="novoTecnico">
    <form method="post">
        <div class="pn-modal-head"><h2>Adicionar à equipa técnica</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="add_coach">
            <div class="pn-form-grid">
                <div class="lp-field full"><label for="c-name">Nome</label><input id="c-name" name="coach_name" type="text" maxlength="100" required></div>
                <div class="lp-field"><label for="c-role">Função</label><input id="c-role" name="role_title" type="text" maxlength="100" placeholder="Treinador Adjunto"></div>
                <div class="lp-field"><label for="c-exp">Anos de experiência</label><input id="c-exp" name="experience_years" type="number" min="0" max="70" value="0"></div>
                <div class="lp-field full"><label for="c-cert">Certificação</label><input id="c-cert" name="certification" type="text" maxlength="100" placeholder="FIBA Nível 1"></div>
                <div class="lp-field full"><label for="c-bio">Apresentação</label><textarea id="c-bio" name="bio" style="min-height:90px;"></textarea></div>
            </div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Adicionar</button>
        </div>
    </form>
</dialog>

<?php require __DIR__ . '/../includes/layout/admin_bottom.php'; ?>
