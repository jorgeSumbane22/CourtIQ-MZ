<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Jogadores
 * Gestão do plantel da equipa do administrador (criar, editar, remover, exportar).
 */


require_once __DIR__ . '/../upload_helper.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$posicoes = ['PG', 'SG', 'SF', 'PF', 'C'];
$estados  = ['active', 'injured', 'suspended', 'inactive'];

/** Lê e valida os campos comuns do formulário de jogador. Devolve [dados, erros]. */
$lerJogador = function (?int $ignorarId = null) use ($pdo, $teamId, $posicoes, $estados): array {
    $erros = [];
    $dados = [
        'name'          => trim((string) ($_POST['name'] ?? '')),
        'jersey_number' => (int) ($_POST['jersey_number'] ?? -1),
        'position'      => (string) ($_POST['position'] ?? ''),
        'birth_date'    => trim((string) ($_POST['birth_date'] ?? '')) ?: null,
        'height'        => trim((string) ($_POST['height'] ?? '')) !== '' ? (float) str_replace(',', '.', (string) $_POST['height']) : null,
        'weight'        => trim((string) ($_POST['weight'] ?? '')) !== '' ? (float) str_replace(',', '.', (string) $_POST['weight']) : null,
        'status'        => (string) ($_POST['status'] ?? 'active'),
        'is_captain'    => !empty($_POST['is_captain']) ? 1 : 0,
        'escalao'       => escalao_from_post($GLOBALS['team'] ?? null, $_POST['escalao'] ?? ''),
    ];

    if ($dados['name'] === '' || mb_strlen($dados['name']) > 100) $erros[] = 'Indique o nome do jogador.';
    if ($dados['jersey_number'] < 0 || $dados['jersey_number'] > 99) $erros[] = 'O número da camisola deve estar entre 0 e 99.';
    if (!in_array($dados['position'], $posicoes, true)) $erros[] = 'Posição inválida.';
    if (!in_array($dados['status'], $estados, true)) $erros[] = 'Estado inválido.';
    if ($dados['height'] !== null && ($dados['height'] < 1.2 || $dados['height'] > 2.5)) $erros[] = 'A altura deve ser indicada em metros (ex.: 1,95).';
    if ($dados['weight'] !== null && ($dados['weight'] < 30 || $dados['weight'] > 200)) $erros[] = 'Peso inválido.';
    if ($dados['birth_date'] !== null && !strtotime($dados['birth_date'])) $erros[] = 'Data de nascimento inválida.';

    // o número da camisola é único dentro da equipa
    $stmt = $pdo->prepare('SELECT id FROM players WHERE team_id = :team_id AND jersey_number = :n AND id <> :id');
    $stmt->execute(['team_id' => $teamId, 'n' => $dados['jersey_number'], 'id' => (int) $ignorarId]);
    if ($stmt->fetch()) $erros[] = 'Já existe um jogador com o número ' . $dados['jersey_number'] . '.';

    return [$dados, $erros];
};

// ============================================================================
// AÇÕES
// ============================================================================
if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');

    try {
        if ($acao === 'create') {
            [$dados, $erros] = $lerJogador();

            // conta de acesso opcional
            $username = trim((string) ($_POST['username'] ?? ''));
            $email    = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $comConta = $username !== '' || $email !== '' || $password !== '';
            if ($comConta) {
                require_can('accounts.create');
                if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $username)) $erros[] = 'Utilizador inválido (3 a 50 letras, números, ponto ou hífen).';
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'Email da conta inválido.';
                if (strlen($password) < 6) $erros[] = 'A palavra-passe deve ter pelo menos 6 caracteres.';
                $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
                $stmt->execute(['u' => $username, 'e' => $email]);
                if ($stmt->fetch()) $erros[] = 'Já existe uma conta com esse utilizador ou email.';
            }

            if ($erros) throw new RuntimeException(implode(' ', $erros));
            $dados['photo_url'] = upload_has('photo') ? upload_image('photo', 'jogadores', $teamId) : null;

            $pdo->beginTransaction();
            $userId = null;
            if ($comConta) {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, username, email, password_hash, role, team_id, avatar_initials)
                     VALUES (:name, :username, :email, :hash, 'jogador', :team_id, :initials)"
                );
                $stmt->execute([
                    'name' => $dados['name'], 'username' => $username, 'email' => $email,
                    'hash' => password_hash($password, PASSWORD_DEFAULT), 'team_id' => $teamId,
                    'initials' => mb_strtoupper(mb_substr($dados['name'], 0, 2)),
                ]);
                $userId = (int) $pdo->lastInsertId();
            }
            $stmt = $pdo->prepare(
                'INSERT INTO players (team_id, user_id, name, jersey_number, position, escalao, birth_date, height, weight, status, is_captain, photo_url)
                 VALUES (:team_id, :user_id, :name, :jersey_number, :position, :escalao, :birth_date, :height, :weight, :status, :is_captain, :photo_url)'
            );
            $stmt->execute($dados + ['team_id' => $teamId, 'user_id' => $userId]);
            $pdo->commit();

            panel_log($pdo, $teamId, 'jogador_criado', $dados['name']);
            flash_set('success', 'Jogador adicionado ao plantel.');

        } elseif ($acao === 'update') {
            $id = (int) ($_POST['player_id'] ?? 0);
            if (!team_owns('players', $id)) throw new RuntimeException('Jogador não encontrado.');
            $stmt = $pdo->prepare('SELECT escalao FROM players WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            if (!escalao_allowed($team, (string) $stmt->fetchColumn())) throw new RuntimeException('Esse jogador não é de um escalão que treina.');
            [$dados, $erros] = $lerJogador($id);
            if ($erros) throw new RuntimeException(implode(' ', $erros));

            // sem ficheiro novo mantém-se a fotografia atual
            $stmt = $pdo->prepare('SELECT photo_url FROM players WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            $fotoAntiga = $stmt->fetchColumn() ?: null;
            $dados['photo_url'] = $fotoAntiga;
            if (!empty($_POST['remove_photo'])) $dados['photo_url'] = null;
            if (upload_has('photo')) $dados['photo_url'] = upload_image('photo', 'jogadores', $teamId);

            $stmt = $pdo->prepare(
                'UPDATE players SET name = :name, jersey_number = :jersey_number, position = :position, escalao = :escalao, birth_date = :birth_date,
                        height = :height, weight = :weight, status = :status, is_captain = :is_captain, photo_url = :photo_url
                 WHERE id = :id AND team_id = :team_id'
            );
            $stmt->execute($dados + ['id' => $id, 'team_id' => $teamId]);
            if ($dados['photo_url'] !== $fotoAntiga) upload_delete($fotoAntiga, $teamId);
            // a conta ligada (se existir) acompanha o nome
            $pdo->prepare('UPDATE users u JOIN players p ON p.user_id = u.id SET u.name = :name WHERE p.id = :id AND p.team_id = :team_id')
                ->execute(['name' => $dados['name'], 'id' => $id, 'team_id' => $teamId]);

            panel_log($pdo, $teamId, 'jogador_editado', $dados['name']);
            flash_set('success', 'Dados do jogador atualizados.');

        } elseif ($acao === 'delete') {
            $id = (int) ($_POST['player_id'] ?? 0);
            if (!team_owns('players', $id)) throw new RuntimeException('Jogador não encontrado.');
            $stmt = $pdo->prepare('SELECT photo_url, escalao FROM players WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            $ficha = $stmt->fetch() ?: [];
            if (!escalao_allowed($team, (string) ($ficha['escalao'] ?? ''))) throw new RuntimeException('Esse jogador não é de um escalão que treina.');
            $foto = ($ficha['photo_url'] ?? null) ?: null;
            $stmt = $pdo->prepare('DELETE FROM players WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            upload_delete($foto, $teamId);
            panel_log($pdo, $teamId, 'jogador_removido', '#' . $id);
            flash_set('success', 'Jogador removido do plantel.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/jogadores: ' . $e->getMessage());
    }
    panel_redirect(panel_url('jogadores.php'));
}

$escalaoAtual = escalao_atual($team);
$jogadores    = panel_players($pdo, $teamId);
if ($escalaoAtual !== '') {
    $jogadores = array_values(array_filter($jogadores, fn($j) => $j['escalao'] === $escalaoAtual));
}

// ============================================================================
// EXPORTAR CSV
// ============================================================================
if (($_GET['exportar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jogadores_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numero', 'Nome', 'Escalao', 'Posicao', 'Estado', 'Capitao', 'Nascimento', 'Altura', 'Peso', 'Jogos', 'PTS', 'REB', 'AST'], ';');
    foreach ($jogadores as $j) {
        fputcsv($out, [
            $j['jersey_number'], $j['name'], escalao_label($j['escalao']), pub_position($j['position']), pub_player_status($j['status'])[0],
            $j['is_captain'] ? 'Sim' : 'Nao', $j['birth_date'], $j['height'], $j['weight'], $j['games_played'],
            number_format((float) $j['avg_pts'], 1, ',', ''), number_format((float) $j['avg_reb'], 1, ',', ''), number_format((float) $j['avg_ast'], 1, ',', ''),
        ], ';');
    }
    exit;
}

$porEstado = array_count_values(array_column($jogadores, 'status'));

$pageTitle = 'Jogadores';
$activeNav = panel_name() === 'treinador' ? 'plantel' : 'jogadores';
require panel_layout('top');

/** Campos do formulário (usados na janela de criar e na de editar). */
$campos = function (string $p) use ($posicoes, $estados, $team, $escalaoAtual): void { ?>
    <div class="pn-form-grid">
        <div class="lp-field full"><label for="<?= $p ?>name">Nome completo</label><input id="<?= $p ?>name" name="name" type="text" maxlength="100" required></div>
        <div class="lp-field"><label for="<?= $p ?>num">Número da camisola</label><input id="<?= $p ?>num" name="jersey_number" type="number" min="0" max="99" required></div>
        <div class="lp-field"><label for="<?= $p ?>pos">Posição</label>
            <select id="<?= $p ?>pos" name="position" required>
                <?php foreach ($posicoes as $pos): ?><option value="<?= $pos ?>"><?= pub_e(pub_position($pos)) ?> (<?= $pos ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>esc">Escalão</label>
            <select id="<?= $p ?>esc" name="escalao" required><?= escalao_options($team, $escalaoAtual ?: 'seniores') ?></select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>birth">Data de nascimento</label><input id="<?= $p ?>birth" name="birth_date" type="date"></div>
        <div class="lp-field"><label for="<?= $p ?>status">Estado</label>
            <select id="<?= $p ?>status" name="status">
                <?php foreach ($estados as $estado): ?><option value="<?= $estado ?>"><?= pub_e(pub_player_status($estado)[0]) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>height">Altura (m)</label><input id="<?= $p ?>height" name="height" type="number" step="0.01" min="1.2" max="2.5" placeholder="1.95"></div>
        <div class="lp-field"><label for="<?= $p ?>weight">Peso (kg)</label><input id="<?= $p ?>weight" name="weight" type="number" step="0.1" min="30" max="200"></div>
        <div class="lp-field full"><label for="<?= $p ?>photo">Fotografia</label><input id="<?= $p ?>photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
            <p class="pn-help">JPG, PNG, WEBP ou GIF até 4 MB.<?= $p === 'e-' ? ' Deixe vazio para manter a fotografia atual.' : '' ?></p></div>
        <label class="lp-check full"><input type="checkbox" name="is_captain" value="1"><span>Capitão de equipa</span></label>
        <?php if ($p === 'e-'): ?><label class="lp-check full"><input type="checkbox" name="remove_photo" value="1"><span>Remover a fotografia atual</span></label><?php endif; ?>
    </div>
<?php }; ?>

<div class="lp pn">
    <?php panel_head('Jogadores', count($jogadores) . ' atletas no plantel de ' . $team['name'] . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="?exportar=csv"><i class="fa-solid fa-file-csv"></i> Exportar CSV</a>'
        . '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novoJogador" data-reset><i class="fa-solid fa-plus"></i> Novo jogador</button>'); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section class="lp-kpis" aria-label="Resumo do plantel">
        <div class="lp-card lp-kpi"><b><?= count($jogadores) ?></b><span>No plantel</span></div>
        <div class="lp-card lp-kpi lp-kpi--up"><b><?= (int) ($porEstado['active'] ?? 0) ?></b><span>Disponíveis</span></div>
        <div class="lp-card lp-kpi lp-kpi--down"><b><?= (int) ($porEstado['injured'] ?? 0) ?></b><span>Lesionados</span></div>
        <div class="lp-card lp-kpi"><b><?= (int) ($porEstado['suspended'] ?? 0) + (int) ($porEstado['inactive'] ?? 0) ?></b><span>Suspensos / inativos</span></div>
    </section>

    <section data-filter>
        <div class="lp-toolbar">
            <div class="lp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Procurar jogador..." aria-label="Procurar jogador"></div>
            <div class="lp-chips" role="group" aria-label="Filtrar por posição">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos</button>
                <?php foreach ($posicoes as $pos): ?><button type="button" class="lp-chip" data-filter-value="<?= $pos ?>" aria-pressed="false"><?= pub_e(pub_position($pos)) ?></button><?php endforeach; ?>
            </div>
        </div>

        <div class="lp-card lp-table-wrap">
            <table class="lp-table pn-table">
                <thead>
                    <tr>
                        <th scope="col">Jogador</th><th scope="col">Posição</th><th scope="col">Estado</th>
                        <th scope="col" class="num" title="Jogos">J</th><th scope="col" class="num">PTS</th><th scope="col" class="num">REB</th><th scope="col" class="num">AST</th>
                        <th scope="col" class="end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jogadores as $j):
                        [$estado, $tipo] = pub_player_status($j['status']);
                    ?>
                    <tr data-filter-item data-tags="<?= pub_e($j['position']) ?>" data-name="<?= pub_e(mb_strtolower((string) $j['name'])) ?>">
                        <td>
                            <div class="pn-user">
                                <?= pn_avatar($j['photo_url'], $j['name']) ?>
                                <div>
                                    <b>#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?> <?= $j['is_captain'] ? '<i class="fa-solid fa-star" style="color:var(--gold);font-size:11px;" title="Capitão"></i>' : '' ?></b>
                                    <small><?= pub_e(escalao_label($j['escalao'])) ?> · <?= $j['username'] ? 'Conta: ' . pub_e($j['username']) : 'Sem conta de acesso' ?></small>
                                </div>
                            </div>
                        </td>
                        <td><?= pub_e(pub_position($j['position'])) ?></td>
                        <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= pub_e($estado) ?></span></td>
                        <td class="num"><?= (int) $j['games_played'] ?></td>
                        <td class="num lp-table-pts"><?= number_format((float) $j['avg_pts'], 1) ?></td>
                        <td class="num"><?= number_format((float) $j['avg_reb'], 1) ?></td>
                        <td class="num"><?= number_format((float) $j['avg_ast'], 1) ?></td>
                        <td class="end">
                            <span class="pn-row-actions">
                                <a class="pn-icon-btn" href="jogador-perfil.php?id=<?= (int) $j['id'] ?>" title="Ver ficha" aria-label="Ver ficha de <?= pub_e($j['name']) ?>"><i class="fa-solid fa-eye"></i></a>
                                <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar <?= pub_e($j['name']) ?>" data-modal="#editarJogador"
                                        data-values="<?= pn_values([
                                            'player_id' => (int) $j['id'], 'name' => $j['name'], 'jersey_number' => (int) $j['jersey_number'],
                                            'position' => $j['position'], 'birth_date' => $j['birth_date'], 'height' => $j['height'], 'weight' => $j['weight'],
                                            'status' => $j['status'], 'is_captain' => (int) $j['is_captain'], 'remove_photo' => 0, 'escalao' => $j['escalao'],
                                        ]) ?>"><i class="fa-solid fa-pen"></i></button>
                                <?php if (can('players.delete')): ?>
                                <form method="post" data-confirm="Remover <?= pub_e($j['name']) ?> do plantel? As estatísticas deste jogador também são apagadas.">
                                    <?= panel_csrf() ?>
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="player_id" value="<?= (int) $j['id'] ?>">
                                    <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover <?= pub_e($j['name']) ?>"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="pn-empty" data-filter-empty <?= $jogadores ? 'hidden' : '' ?>>
                <i class="fa-solid fa-user-group"></i><?= $jogadores ? 'Nenhum jogador corresponde à pesquisa.' : 'Ainda não há jogadores. Adicione o primeiro.' ?>
            </p>
        </div>
    </section>
</div>

<dialog class="pn-modal" id="novoJogador">
    <form method="post" enctype="multipart/form-data">
        <div class="pn-modal-head"><h2>Novo jogador</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="create">
            <?php $campos('n-'); ?>
            <?php if (can('accounts.create')): ?>
            <h3 class="lp-sub" style="margin:8px 0 0;">Conta de acesso (opcional)</h3>
            <p class="pn-help" style="margin-top:-8px;">Preencha para o jogador poder entrar no painel do jogador.</p>
            <div class="pn-form-grid">
                <div class="lp-field"><label for="n-user">Utilizador</label><input id="n-user" name="username" type="text" maxlength="50" autocomplete="off"></div>
                <div class="lp-field"><label for="n-email">Email</label><input id="n-email" name="email" type="email" maxlength="100" autocomplete="off"></div>
                <div class="lp-field full"><label for="n-pass">Palavra-passe (mín. 6 caracteres)</label><input id="n-pass" name="password" type="password" minlength="6" autocomplete="new-password"></div>
            </div>
            <?php else: ?>
            <p class="pn-help">A conta de acesso é criada pelo próprio jogador em "Criar conta" (e aceite em Pedidos de inscrição) ou pelo administrador do clube.</p>
            <?php endif; ?>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Adicionar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="editarJogador">
    <form method="post" enctype="multipart/form-data">
        <div class="pn-modal-head"><h2>Editar jogador</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="player_id">
            <?php $campos('e-'); ?>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
