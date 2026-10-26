<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Utilizadores
 * Todas as contas do sistema: criar, mudar de papel ou de equipa, suspender,
 * repor a palavra-passe e apagar.
 *
 * Regras: o superadmin não tem equipa; os outros papéis têm de ter uma.
 * Não se pode apagar nem despromover a própria conta, nem ficar sem nenhum superadmin.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();
$euId = (int) $user['id'];

$papeis  = ['superadmin' => 'Superadmin', 'admin' => 'Administrador', 'treinador' => 'Treinador', 'jogador' => 'Jogador', 'adepto' => 'Adepto'];
$estados = ['active' => ['Ativa', 'win'], 'inactive' => ['Inativa', ''], 'suspended' => ['Suspensa', 'loss']];
$equipas = panel_all_teams($pdo);

/** Papel + equipa coerentes: devolve o team_id a guardar. */
$equipaPara = function (string $papel, int $teamId) use ($equipas): ?int {
    if ($papel === 'superadmin') return null;
    if (!isset($equipas[$teamId])) throw new RuntimeException('Escolha a equipa desta conta.');
    return $teamId;
};

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['user_id'] ?? 0);

    try {
        if ($acao === 'create') {
            $nome     = trim((string) ($_POST['name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email    = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $papel    = isset($papeis[$_POST['role'] ?? '']) ? (string) $_POST['role'] : 'jogador';
            if ($nome === '' || mb_strlen($nome) > 100) throw new RuntimeException('Indique o nome.');
            if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $username)) throw new RuntimeException('Utilizador inválido (3 a 50 letras, números, ponto ou hífen).');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email inválido.');
            if (strlen($password) < 8) throw new RuntimeException('A palavra-passe deve ter pelo menos 8 caracteres.');
            $teamId = $equipaPara($papel, (int) ($_POST['team_id'] ?? 0));

            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
            $stmt->execute(['u' => $username, 'e' => $email]);
            if ($stmt->fetch()) throw new RuntimeException('Já existe uma conta com esse utilizador ou email.');
            role_limit_check($pdo, $papel, $teamId);   // 1 superadmin; 1 admin e 2 treinadores por clube

            $pdo->prepare(
                'INSERT INTO users (name, username, email, password_hash, role, team_id, avatar_initials)
                 VALUES (:name, :username, :email, :hash, :role, :team_id, :initials)'
            )->execute([
                'name' => $nome, 'username' => $username, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $papel, 'team_id' => $teamId, 'initials' => mb_strtoupper(mb_substr($nome, 0, 2)),
            ]);
            panel_log_global($pdo, 'conta_criada', $username . ' (' . $papel . ')', $teamId);
            flash_set('success', 'Conta ' . $username . ' criada.');

        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $alvo = $stmt->fetch();
            if (!$alvo) throw new RuntimeException('Conta não encontrada.');

            $outrosSuper = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND status = 'active' AND id <> " . (int) $id)->fetchColumn();

            if ($acao === 'update') {
                $nome   = trim((string) ($_POST['name'] ?? ''));
                $email  = trim((string) ($_POST['email'] ?? ''));
                $papel  = isset($papeis[$_POST['role'] ?? '']) ? (string) $_POST['role'] : $alvo['role'];
                $estado = isset($estados[$_POST['status'] ?? '']) ? (string) $_POST['status'] : $alvo['status'];
                if ($nome === '' || mb_strlen($nome) > 100) throw new RuntimeException('Indique o nome.');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email inválido.');
                $teamId = $equipaPara($papel, (int) ($_POST['team_id'] ?? 0));

                if ($id === $euId && ($papel !== 'superadmin' || $estado !== 'active')) {
                    throw new RuntimeException('Não pode despromover nem suspender a sua própria conta.');
                }
                if ($alvo['role'] === 'superadmin' && ($papel !== 'superadmin' || $estado !== 'active') && $outrosSuper === 0) {
                    throw new RuntimeException('Tem de existir pelo menos um superadmin ativo.');
                }
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :id');
                $stmt->execute(['e' => $email, 'id' => $id]);
                if ($stmt->fetch()) throw new RuntimeException('Esse email já está a ser usado por outra conta.');
                if ($papel !== $alvo['role'] || (int) $teamId !== (int) $alvo['team_id']) {
                    role_limit_check($pdo, $papel, $teamId, $id);
                }

                // escalões que treina (só treinadores): todos os do clube ou nenhum marcado = sem restrição
                $escaloes = null;
                if ($papel === 'treinador' && $teamId) {
                    $stmt = $pdo->prepare('SELECT escaloes FROM teams WHERE id = :id');
                    $stmt->execute(['id' => $teamId]);
                    $doClube    = team_escaloes(['escaloes' => (string) $stmt->fetchColumn()]);
                    $marcados   = array_map('strval', (array) ($_POST['escaloes'] ?? []));
                    $escolhidos = array_keys(array_intersect_key($doClube, array_flip($marcados)));
                    if ($marcados && !$escolhidos) {
                        throw new RuntimeException('Esse clube não tem os escalões escolhidos. Tem: ' . implode(', ', $doClube) . '.');
                    }
                    $escaloes = $escolhidos && count($escolhidos) < count($doClube) ? implode(',', $escolhidos) : null;
                }

                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET name = :name, email = :email, role = :role, team_id = :team_id, status = :status, escaloes = :escaloes WHERE id = :id')
                    ->execute(['name' => $nome, 'email' => $email, 'role' => $papel, 'team_id' => $teamId, 'status' => $estado, 'escaloes' => $escaloes, 'id' => $id]);
                // se a conta mudou de equipa ou deixou de ser de jogador, desliga-se da ficha de jogador antiga
                $pdo->prepare('UPDATE players SET user_id = NULL WHERE user_id = :id AND (team_id <> :team_id OR :role <> "jogador")')
                    ->execute(['id' => $id, 'team_id' => (int) $teamId, 'role' => $papel]);
                $pdo->commit();
                panel_log_global($pdo, 'conta_atualizada', $alvo['username'] . ' → ' . $papel . ', ' . $estado, $teamId);
                flash_set('success', 'Conta ' . $alvo['username'] . ' atualizada.');

            } elseif ($acao === 'password') {
                $nova = (string) ($_POST['new_password'] ?? '');
                if (strlen($nova) < 8) throw new RuntimeException('A palavra-passe deve ter pelo menos 8 caracteres.');
                $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute(['hash' => password_hash($nova, PASSWORD_DEFAULT), 'id' => $id]);
                Auth::clearAttempts((string) $alvo['username']);   // levanta o bloqueio de login da conta
                panel_log_global($pdo, 'password_reposta', $alvo['username'], $alvo['team_id'] !== null ? (int) $alvo['team_id'] : null);
                flash_set('success', 'Palavra-passe de ' . $alvo['username'] . ' reposta.');

            } elseif ($acao === 'delete') {
                if ($id === $euId) throw new RuntimeException('Não pode apagar a sua própria conta.');
                if ($alvo['role'] === 'superadmin' && $outrosSuper === 0) throw new RuntimeException('Tem de existir pelo menos um superadmin ativo.');
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
                panel_log_global($pdo, 'conta_apagada', $alvo['username'], $alvo['team_id'] !== null ? (int) $alvo['team_id'] : null);
                flash_set('success', 'Conta ' . $alvo['username'] . ' apagada.');
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/utilizadores: ' . $e->getMessage());
    }
    panel_redirect('utilizadores.php');
}

$contas = $pdo->query(
    "SELECT u.id, u.name, u.username, u.email, u.role, u.team_id, u.status, u.last_login, u.created_at, u.escaloes, t.name AS team_name, t.escaloes AS team_escaloes,
            (SELECT p.id FROM players p WHERE p.user_id = u.id LIMIT 1) AS player_id
     FROM users u LEFT JOIN teams t ON t.id = u.team_id
     ORDER BY FIELD(u.role, 'superadmin', 'admin', 'treinador', 'jogador'), t.name, u.name"
)->fetchAll();

$porPapel  = array_count_values(array_column($contas, 'role'));
$semEquipa = count(array_filter($contas, fn($c) => $c['team_id'] === null && $c['role'] !== 'superadmin'));

$pageTitle = 'Utilizadores';
$activeNav = 'utilizadores';
require panel_layout('top');

$opcoesEquipa = function () use ($equipas): void {
    echo '<option value="0">— sem equipa (só superadmin) —</option>';
    foreach ($equipas as $e) echo '<option value="' . (int) $e['id'] . '">' . pub_e($e['name']) . '</option>';
};
?>

<div class="lp pn">
    <?php panel_head('Utilizadores', count($contas) . ' contas em ' . count($equipas) . ' equipas.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novaConta" data-reset><i class="fa-solid fa-user-plus"></i> Nova conta</button>'); ?>

    <?php if ($semEquipa): ?>
        <div class="lp-notice lp-notice--error" role="status"><i class="fa-solid fa-triangle-exclamation"></i><div><b><?= $semEquipa ?> conta(s) sem equipa.</b> Estas pessoas entram mas não vêem nada até lhes atribuir uma equipa.</div></div>
    <?php endif; ?>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Procurar por nome, utilizador, email ou equipa..." aria-label="Procurar conta"></div>
            <div class="lp-chips" role="group" aria-label="Filtrar por papel">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todas <small><?= count($contas) ?></small></button>
                <?php foreach ($papeis as $papel => $rotulo): if (empty($porPapel[$papel])) continue; ?>
                <button type="button" class="lp-chip" data-filter-value="<?= $papel ?>" aria-pressed="false"><?= $rotulo ?> <small><?= (int) $porPapel[$papel] ?></small></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="lp-card lp-table-wrap">
            <table class="lp-table pn-table">
                <thead><tr><th scope="col">Conta</th><th scope="col">Papel</th><th scope="col">Equipa</th><th scope="col">Estado</th><th scope="col">Último acesso</th><th scope="col" class="end">Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($contas as $c): [$estado, $tipo] = $estados[$c['status']] ?? ['—', '']; $eu = (int) $c['id'] === $euId; ?>
                    <tr data-filter-item data-tags="<?= pub_e($c['role']) ?>" data-name="<?= pub_e(mb_strtolower($c['name'] . ' ' . $c['username'] . ' ' . $c['email'] . ' ' . $c['team_name'])) ?>">
                        <td><div class="pn-user"><span class="lp-avatar lp-avatar--sm"><?= pub_initial($c['name']) ?></span><div>
                            <b><?= pub_e($c['name']) ?><?= $eu ? ' <span class="lp-badge lp-badge--accent" style="margin-left:6px;">Eu</span>' : '' ?></b>
                            <small><?= pub_e($c['username']) ?> · <?= pub_e($c['email']) ?></small></div></div></td>
                        <td><span class="lp-badge lp-badge--<?= ['superadmin' => 'gold', 'admin' => 'accent', 'treinador' => 'info'][$c['role']] ?? '' ?>"><?= pub_e($papeis[$c['role']] ?? $c['role']) ?></span></td>
                        <td><?= $c['team_name'] ? pub_e($c['team_name']) : ($c['role'] === 'superadmin' ? '<span style="color:var(--muted);">Todas</span>' : '<span class="lp-badge lp-badge--loss">Sem equipa</span>') ?>
                            <?= $c['role'] === 'jogador' && !$c['player_id'] ? '<small>Sem ficha de jogador</small>' : '' ?>
                            <?php $escTreinador = $c['role'] === 'treinador' && $c['team_id'] ? coach_escaloes(['escaloes' => $c['team_escaloes']], (string) $c['escaloes']) : []; ?>
                            <?php if ($escTreinador): ?><small>Treina: <?= implode(' &bull; ', $escTreinador) ?></small><?php endif; ?></td>
                        <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= $estado ?></span></td>
                        <td><?= $c['last_login'] ? pub_e(pub_date($c['last_login'], 'd/m/Y H:i')) : '<span style="color:var(--muted);">Nunca</span>' ?></td>
                        <td class="end"><span class="pn-row-actions">
                            <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar <?= pub_e($c['username']) ?>" data-modal="#editarConta" data-values="<?= pn_values([
                                'user_id' => (int) $c['id'], 'utilizador' => $c['username'], 'name' => $c['name'], 'email' => $c['email'], 'role' => $c['role'],
                                'team_id' => (int) $c['team_id'], 'status' => $c['status'],
                            ] + array_combine(array_map(fn($k) => 'esc_' . $k, array_keys(ESCALOES)), array_map(fn($k) => isset($escTreinador[$k]) ? 1 : 0, array_keys(ESCALOES)))) ?>"><i class="fa-solid fa-pen"></i></button>
                            <button type="button" class="pn-icon-btn" title="Repor palavra-passe" aria-label="Repor palavra-passe de <?= pub_e($c['username']) ?>" data-modal="#reporPassword" data-reset
                                    data-values="<?= pn_values(['user_id' => (int) $c['id'], 'utilizador' => $c['username']]) ?>"><i class="fa-solid fa-key"></i></button>
                            <?php if (!$eu): ?>
                            <form method="post" data-confirm="Apagar a conta <?= pub_e($c['username']) ?>? Esta pessoa deixa de poder entrar.">
                                <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= (int) $c['id'] ?>">
                                <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Apagar" aria-label="Apagar <?= pub_e($c['username']) ?>"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="pn-empty" data-filter-empty hidden><i class="fa-solid fa-users"></i>Nenhuma conta corresponde à pesquisa.</p>
        </div>
    </section>
</div>

<dialog class="pn-modal" id="novaConta">
    <form method="post" autocomplete="off">
        <div class="pn-modal-head"><h2>Nova conta</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="create">
            <div class="pn-form-grid">
                <div class="lp-field full"><label for="n-name">Nome</label><input id="n-name" name="name" type="text" maxlength="100" required></div>
                <div class="lp-field"><label for="n-user">Utilizador</label><input id="n-user" name="username" type="text" maxlength="50" required></div>
                <div class="lp-field"><label for="n-email">Email</label><input id="n-email" name="email" type="email" maxlength="100" required></div>
                <div class="lp-field"><label for="n-role">Papel</label>
                    <select id="n-role" name="role"><?php foreach ($papeis as $k => $v): ?><option value="<?= $k ?>"<?= $k === 'admin' ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field"><label for="n-team">Equipa</label><select id="n-team" name="team_id"><?php $opcoesEquipa(); ?></select></div>
                <div class="lp-field full"><label for="n-pass">Palavra-passe (mín. 8 caracteres)</label><input id="n-pass" name="password" type="password" minlength="8" required autocomplete="new-password"></div>
            </div>
            <p class="pn-help">As contas de jogador criadas aqui não ficam ligadas a uma ficha de jogador. Para isso, crie o jogador no Plantel da equipa com "conta de acesso".</p>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Criar conta</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="editarConta">
    <form method="post">
        <div class="pn-modal-head"><h2>Editar <span data-text="utilizador" style="color:var(--muted);font-size:18px;"></span></h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id">
            <div class="pn-form-grid">
                <div class="lp-field"><label for="e-name">Nome</label><input id="e-name" name="name" type="text" maxlength="100" required></div>
                <div class="lp-field"><label for="e-email">Email</label><input id="e-email" name="email" type="email" maxlength="100" required></div>
                <div class="lp-field"><label for="e-role">Papel</label>
                    <select id="e-role" name="role"><?php foreach ($papeis as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field"><label for="e-team">Equipa</label><select id="e-team" name="team_id"><?php $opcoesEquipa(); ?></select></div>
                <div class="lp-field full"><label for="e-status">Estado</label>
                    <select id="e-status" name="status"><?php foreach ($estados as $k => [$v]): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <fieldset class="lp-field full" style="border:0;padding:0;margin:0;"><legend style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;">Escalões que treina</legend>
                    <?php foreach (ESCALOES as $k => $v): ?>
                    <label class="lp-check" style="margin-bottom:6px;"><input type="checkbox" name="escaloes[]" value="<?= $k ?>" data-field="esc_<?= $k ?>"><span><?= $v ?></span></label>
                    <?php endforeach; ?>
                    <p class="pn-help">Só conta para treinadores. Sem nenhum marcado, treina todos os escalões do clube.</p>
                </fieldset>
            </div>
            <p class="pn-help">Contas inativas ou suspensas não conseguem entrar.</p>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="reporPassword">
    <form method="post" autocomplete="off">
        <div class="pn-modal-head"><h2>Repor palavra-passe <span data-text="utilizador" style="color:var(--muted);font-size:18px;"></span></h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="password"><input type="hidden" name="user_id">
            <div class="lp-field"><label for="r-pass">Nova palavra-passe (mín. 8 caracteres)</label><input id="r-pass" name="new_password" type="password" minlength="8" required autocomplete="new-password"></div>
            <p class="pn-help">Comunique a nova palavra-passe à pessoa por um canal seguro e peça-lhe para a mudar em Configurações.</p>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-key"></i> Repor</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
