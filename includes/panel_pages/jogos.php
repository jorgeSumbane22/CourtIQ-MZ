<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Jogos
 * Agendar, editar, registar resultado, lançar estatísticas e remover jogos da equipa.
 * Com ?estatisticas=ID abre a folha de estatísticas de um jogo.
 */


[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$gameRepo = new GameRepository();

/** Lê e valida o formulário de jogo. Devolve [dados, erros]. */
$lerJogo = function (): array {
    $erros = [];
    $dados = [
        'opponent'    => trim((string) ($_POST['opponent'] ?? '')),
        'game_date'   => trim((string) ($_POST['game_date'] ?? '')),
        'game_time'   => trim((string) ($_POST['game_time'] ?? '')) ?: null,
        'location'    => trim((string) ($_POST['location'] ?? '')) ?: null,
        'home_away'   => ($_POST['home_away'] ?? 'home') === 'away' ? 'away' : 'home',
        'competition' => trim((string) ($_POST['competition'] ?? '')) ?: 'Liga Nacional',
        'jornada'     => trim((string) ($_POST['jornada'] ?? '')) ?: null,
        'escalao'     => escalao_from_post($GLOBALS['team'] ?? null, $_POST['escalao'] ?? ''),
    ];
    if ($dados['opponent'] === '' || mb_strlen($dados['opponent']) > 100) $erros[] = 'Indique o adversário.';
    if (!strtotime($dados['game_date'])) $erros[] = 'Indique a data do jogo.';
    return [$dados, $erros];
};

// ============================================================================
// AÇÕES
// ============================================================================
if (panel_is_post()) {
    $acao   = (string) ($_POST['action'] ?? '');
    $gameId = (int) ($_POST['game_id'] ?? 0);
    $voltar = 'jogos.php';

    try {
        if ($acao !== 'create' && !team_owns('games', $gameId)) {
            throw new RuntimeException('Jogo não encontrado.');
        }
        if ($acao !== 'create') {
            // um treinador só mexe nos jogos dos escalões que treina
            $stmt = $pdo->prepare('SELECT escalao FROM games WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $gameId, 'team_id' => $teamId]);
            if (!escalao_allowed($team, (string) $stmt->fetchColumn())) throw new RuntimeException('Esse jogo não é de um escalão que treina.');
        }

        if ($acao === 'create' || $acao === 'update') {
            [$dados, $erros] = $lerJogo();
            if ($erros) throw new RuntimeException(implode(' ', $erros));

            if ($acao === 'create') {
                $stmt = $pdo->prepare(
                    "INSERT INTO games (team_id, opponent, game_date, game_time, location, home_away, competition, escalao, jornada, status)
                     VALUES (:team_id, :opponent, :game_date, :game_time, :location, :home_away, :competition, :escalao, :jornada, 'scheduled')"
                );
                $stmt->execute($dados + ['team_id' => $teamId]);
                panel_log($pdo, $teamId, 'jogo_agendado', 'vs ' . $dados['opponent'] . ' em ' . $dados['game_date']);
                notify_fans($pdo, $teamId, 'Novo jogo marcado: vs ' . $dados['opponent'],
                    $team['name'] . ' (' . escalao_label($dados['escalao']) . ') joga contra ' . $dados['opponent'] . ' em ' . pub_date($dados['game_date']) . '.');
                flash_set('success', 'Jogo agendado.');
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE games SET opponent = :opponent, game_date = :game_date, game_time = :game_time, location = :location,
                            home_away = :home_away, competition = :competition, escalao = :escalao, jornada = :jornada
                     WHERE id = :id AND team_id = :team_id'
                );
                $stmt->execute($dados + ['id' => $gameId, 'team_id' => $teamId]);
                panel_log($pdo, $teamId, 'jogo_editado', 'vs ' . $dados['opponent']);
                flash_set('success', 'Jogo atualizado.');
            }

        } elseif ($acao === 'finalize') {
            $nosso = (int) ($_POST['our_score'] ?? -1);
            $deles = (int) ($_POST['opponent_score'] ?? -1);
            if ($nosso < 0 || $deles < 0 || $nosso > 300 || $deles > 300) throw new RuntimeException('Resultado inválido.');
            if ($nosso === $deles) throw new RuntimeException('No basquetebol não há empates: verifique o resultado.');
            $stmt = $pdo->prepare("UPDATE games SET our_score = :o, opponent_score = :d, status = 'finished' WHERE id = :id AND team_id = :team_id");
            $stmt->execute(['o' => $nosso, 'd' => $deles, 'id' => $gameId, 'team_id' => $teamId]);
            panel_log($pdo, $teamId, 'resultado_registado', "Jogo #$gameId: $nosso-$deles");
            $stmt = $pdo->prepare('SELECT opponent FROM games WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $gameId, 'team_id' => $teamId]);
            $adversario = (string) $stmt->fetchColumn();
            notify_fans($pdo, $teamId, ($nosso > $deles ? 'Vitória ' : 'Derrota ') . $nosso . '–' . $deles . ' contra ' . $adversario,
                $team['name'] . ' ' . $nosso . ' – ' . $deles . ' ' . $adversario . '.', $nosso > $deles ? 'success' : 'info');
            flash_set('success', 'Resultado registado.');

        } elseif ($acao === 'status') {
            $estado = (string) ($_POST['status'] ?? '');
            if (!in_array($estado, ['scheduled', 'postponed', 'cancelled'], true)) throw new RuntimeException('Estado inválido.');
            $stmt = $pdo->prepare('UPDATE games SET status = :s WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['s' => $estado, 'id' => $gameId, 'team_id' => $teamId]);
            flash_set('success', 'Estado do jogo atualizado.');

        } elseif ($acao === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM games WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $gameId, 'team_id' => $teamId]);
            panel_log($pdo, $teamId, 'jogo_removido', '#' . $gameId);
            flash_set('success', 'Jogo removido.');

        } elseif ($acao === 'save_stats') {
            $voltar = 'jogos.php?estatisticas=' . $gameId;
            $gravados = 0;
            foreach ((array) ($_POST['stats'] ?? []) as $playerId => $linha) {
                $limpo = [];
                foreach (['points', 'rebounds', 'assists', 'steals', 'blocks', 'fouls', 'minutes_played'] as $campo) {
                    $limpo[$campo] = max(0, min(200, (int) ($linha[$campo] ?? 0)));
                }
                // saveStat confirma que o jogo e o jogador são desta equipa
                if ($gameRepo->saveStat($gameId, (int) $playerId, $limpo)) {
                    $gravados++;
                }
            }
            panel_log($pdo, $teamId, 'estatisticas_guardadas', "Jogo #$gameId ($gravados jogadores)");
            flash_set('success', 'Estatísticas guardadas para ' . $gravados . ' jogadores.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/jogos: ' . $e->getMessage());
    }
    panel_redirect($voltar);
}

// ============================================================================
// DADOS
// ============================================================================
$escalaoAtual = escalao_atual($team);
$stmt = $pdo->prepare('SELECT * FROM games WHERE team_id = :team_id ORDER BY game_date DESC, id DESC');
$stmt->execute(['team_id' => $teamId]);
$jogos = $stmt->fetchAll();
if ($escalaoAtual !== '') {
    $jogos = array_values(array_filter($jogos, fn($j) => $j['escalao'] === $escalaoAtual));
}

$porJogar   = array_reverse(array_values(array_filter($jogos, fn($j) => $j['status'] !== 'finished')));
$terminados = array_values(array_filter($jogos, fn($j) => $j['status'] === 'finished'));
$registo    = pub_record($terminados);

// Folha de estatísticas de um jogo
$jogoStats = null;
$linhasStats = [];
$statsId = (int) ($_GET['estatisticas'] ?? 0);
if ($statsId > 0) {
    foreach ($jogos as $j) {
        if ((int) $j['id'] === $statsId) $jogoStats = $j;     // só jogos desta equipa
    }
    if ($jogoStats) {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.jersey_number, p.position, COALESCE(u.name, p.name) AS name, s.points, s.rebounds, s.assists, s.steals, s.blocks, s.fouls, s.minutes_played
             FROM players p
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN game_player_stats s ON s.player_id = p.id AND s.game_id = :game_id
             WHERE p.team_id = :team_id
             ORDER BY p.jersey_number'
        );
        $stmt->execute(['game_id' => $statsId, 'team_id' => $teamId]);
        $linhasStats = $stmt->fetchAll();
    } else {
        flash_set('error', 'Jogo não encontrado.');
        panel_redirect('jogos.php');
    }
}

$pageTitle = 'Jogos';
$activeNav = 'jogos';
require panel_layout('top');

$camposJogo = function (string $p) use ($team, $escalaoAtual): void { ?>
    <div class="pn-form-grid">
        <div class="lp-field"><label for="<?= $p ?>opp">Adversário</label><input id="<?= $p ?>opp" name="opponent" type="text" maxlength="100" required></div>
        <div class="lp-field"><label for="<?= $p ?>esc">Escalão</label>
            <select id="<?= $p ?>esc" name="escalao" required><?= escalao_options($team, $escalaoAtual ?: 'seniores') ?></select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>date">Data</label><input id="<?= $p ?>date" name="game_date" type="date" required></div>
        <div class="lp-field"><label for="<?= $p ?>time">Hora</label><input id="<?= $p ?>time" name="game_time" type="time"></div>
        <div class="lp-field"><label for="<?= $p ?>ha">Local do jogo</label>
            <select id="<?= $p ?>ha" name="home_away"><option value="home">Em casa</option><option value="away">Fora</option></select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>loc">Pavilhão</label><input id="<?= $p ?>loc" name="location" type="text" maxlength="255" placeholder="<?= pub_e($team['stadium'] ?? '') ?>"></div>
        <div class="lp-field"><label for="<?= $p ?>comp">Competição</label><input id="<?= $p ?>comp" name="competition" type="text" maxlength="100" value="Liga Nacional"></div>
        <div class="lp-field"><label for="<?= $p ?>jor">Jornada</label><input id="<?= $p ?>jor" name="jornada" type="text" maxlength="50" placeholder="Jornada 7"></div>
    </div>
<?php }; ?>

<div class="lp pn">
<?php if ($jogoStats): ?>

    <a class="lp-back" href="jogos.php"><i class="fa-solid fa-arrow-left"></i> Voltar aos jogos</a>
    <?php panel_head('Estatísticas do jogo', $team['name'] . ' vs ' . $jogoStats['opponent'] . ' · ' . pub_date($jogoStats['game_date'])
        . ($jogoStats['status'] === 'finished' ? ' · ' . (int) $jogoStats['our_score'] . '–' . (int) $jogoStats['opponent_score'] : '')); ?>

    <form method="post" class="lp-card">
        <?= panel_csrf() ?><input type="hidden" name="action" value="save_stats"><input type="hidden" name="game_id" value="<?= (int) $jogoStats['id'] ?>">
        <div class="lp-table-wrap">
            <table class="lp-table pn-table">
                <thead><tr>
                    <th scope="col">Jogador</th>
                    <th scope="col" class="num" title="Minutos">MIN</th><th scope="col" class="num" title="Pontos">PTS</th><th scope="col" class="num" title="Ressaltos">REB</th>
                    <th scope="col" class="num" title="Assistências">AST</th><th scope="col" class="num" title="Roubos de bola">STL</th>
                    <th scope="col" class="num" title="Desarmes">BLK</th><th scope="col" class="num" title="Faltas">FAL</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($linhasStats as $l): $pid = (int) $l['id']; ?>
                    <tr>
                        <td class="lp-table-team">#<?= (int) $l['jersey_number'] ?> <?= pub_e($l['name']) ?> <small style="display:inline;color:var(--muted);">· <?= pub_e($l['position']) ?></small></td>
                        <?php foreach (['minutes_played', 'points', 'rebounds', 'assists', 'steals', 'blocks', 'fouls'] as $campo): ?>
                        <td class="num"><input class="pn-stat-input" type="number" min="0" max="200" name="stats[<?= $pid ?>][<?= $campo ?>]" value="<?= (int) ($l[$campo] ?? 0) ?>" aria-label="<?= $campo ?> de <?= pub_e($l['name']) ?>"></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$linhasStats): ?><div class="pn-empty"><i class="fa-solid fa-user-group"></i>A equipa ainda não tem jogadores.</div><?php endif; ?>
        <div class="pn-modal-foot">
            <a class="lp-btn lp-btn--ghost lp-btn--sm" href="jogos.php">Cancelar</a>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar estatísticas</button>
        </div>
    </form>

<?php else: ?>

    <?php panel_head('Jogos', count($porJogar) . ' por disputar · ' . $registo['vitorias'] . ' vitórias e ' . $registo['derrotas'] . ' derrotas.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novoJogo" data-reset><i class="fa-solid fa-plus"></i> Agendar jogo</button>'); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-calendar-days"></i>Por disputar</h2><small><?= count($porJogar) ?> jogos</small></div>
        <?php if ($porJogar): ?>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Data</th><th scope="col">Adversário</th><th scope="col">Local</th><th scope="col">Estado</th><th scope="col" class="end">Ações</th></tr></thead>
            <tbody>
                <?php foreach ($porJogar as $j): [$rotulo, $tipo] = pn_event_status($j['status']); ?>
                <tr>
                    <td><b><?= pub_e(pub_date($j['game_date'])) ?></b><small><?= pub_e(pub_time($j['game_time'])) ?: '—' ?></small></td>
                    <td class="lp-table-team">vs <?= pub_e($j['opponent']) ?><small><?= pub_e(escalao_label($j['escalao'])) ?> · <?= pub_e($j['competition']) ?><?= $j['jornada'] ? ' · ' . pub_e($j['jornada']) : '' ?></small></td>
                    <td><?= ($j['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?><small><?= pub_e($j['location'] ?: '—') ?></small></td>
                    <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= $rotulo ?></span></td>
                    <td class="end"><span class="pn-row-actions">
                        <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal="#resultado" data-values="<?= pn_values(['game_id' => (int) $j['id'], 'titulo' => 'vs ' . $j['opponent'], 'our_score' => '', 'opponent_score' => '']) ?>"><i class="fa-solid fa-flag-checkered"></i> Resultado</button>
                        <a class="pn-icon-btn" href="presencas.php?jogo_id=<?= (int) $j['id'] ?>" title="Presenças" aria-label="Presenças"><i class="fa-solid fa-clipboard-check"></i></a>
                        <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar jogo" data-modal="#editarJogo" data-values="<?= pn_values([
                            'game_id' => (int) $j['id'], 'opponent' => $j['opponent'], 'game_date' => $j['game_date'], 'game_time' => pub_time($j['game_time']),
                            'location' => $j['location'], 'home_away' => $j['home_away'], 'competition' => $j['competition'], 'jornada' => $j['jornada'], 'status' => $j['status'],
                            'escalao' => $j['escalao'],
                        ]) ?>"><i class="fa-solid fa-pen"></i></button>
                        <?php if (can('games.delete')): ?>
                        <form method="post" data-confirm="Remover o jogo contra <?= pub_e($j['opponent']) ?>?">
                            <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="game_id" value="<?= (int) $j['id'] ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover jogo"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><div class="pn-empty"><i class="fa-solid fa-calendar-xmark"></i>Não há jogos por disputar. Agende o próximo.</div><?php endif; ?>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-check-double"></i>Terminados</h2><small><?= count($terminados) ?> jogos</small></div>
        <?php if ($terminados): ?>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Data</th><th scope="col">Adversário</th><th scope="col">Local</th><th scope="col">Resultado</th><th scope="col" class="end">Ações</th></tr></thead>
            <tbody>
                <?php foreach ($terminados as $j): $ganhou = (int) $j['our_score'] > (int) $j['opponent_score']; ?>
                <tr>
                    <td><b><?= pub_e(pub_date($j['game_date'])) ?></b></td>
                    <td class="lp-table-team">vs <?= pub_e($j['opponent']) ?><small><?= pub_e($j['competition']) ?><?= $j['jornada'] ? ' · ' . pub_e($j['jornada']) : '' ?></small></td>
                    <td><?= ($j['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></td>
                    <td><span class="lp-score" style="font-size:20px;"><?= (int) $j['our_score'] ?> &ndash; <?= (int) $j['opponent_score'] ?></span>
                        <span class="lp-badge lp-badge--<?= $ganhou ? 'win' : 'loss' ?>" style="margin-left:8px;"><?= $ganhou ? 'Vitória' : 'Derrota' ?></span></td>
                    <td class="end"><span class="pn-row-actions">
                        <a class="lp-btn lp-btn--ghost lp-btn--sm" href="?estatisticas=<?= (int) $j['id'] ?>"><i class="fa-solid fa-chart-column"></i> Estatísticas</a>
                        <button type="button" class="pn-icon-btn" title="Corrigir resultado" aria-label="Corrigir resultado" data-modal="#resultado" data-values="<?= pn_values(['game_id' => (int) $j['id'], 'titulo' => 'vs ' . $j['opponent'], 'our_score' => (int) $j['our_score'], 'opponent_score' => (int) $j['opponent_score']]) ?>"><i class="fa-solid fa-pen"></i></button>
                        <?php if (can('games.delete')): ?>
                        <form method="post" data-confirm="Remover o jogo contra <?= pub_e($j['opponent']) ?>? As estatísticas deste jogo também são apagadas.">
                            <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="game_id" value="<?= (int) $j['id'] ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover jogo"><i class="fa-solid fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php else: ?><div class="pn-empty"><i class="fa-solid fa-basketball"></i>Ainda não há jogos terminados.</div><?php endif; ?>
    </section>

<?php endif; ?>
</div>

<dialog class="pn-modal" id="novoJogo">
    <form method="post">
        <div class="pn-modal-head"><h2>Agendar jogo</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form"><?= panel_csrf() ?><input type="hidden" name="action" value="create"><?php $camposJogo('n-'); ?></div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Agendar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="editarJogo">
    <form method="post">
        <div class="pn-modal-head"><h2>Editar jogo</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form"><?= panel_csrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="game_id"><?php $camposJogo('e-'); ?></div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="resultado">
    <form method="post">
        <div class="pn-modal-head"><h2>Resultado <span data-text="titulo" style="color:var(--muted);font-size:18px;"></span></h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="finalize"><input type="hidden" name="game_id">
            <div class="pn-form-grid">
                <div class="lp-field"><label for="r-our"><?= pub_e($team['name']) ?></label><input id="r-our" name="our_score" type="number" min="0" max="300" required></div>
                <div class="lp-field"><label for="r-opp">Adversário</label><input id="r-opp" name="opponent_score" type="number" min="0" max="300" required></div>
            </div>
            <p class="pn-help">Ao guardar, o jogo passa a "Terminado" e entra na classificação.</p>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-flag-checkered"></i> Guardar resultado</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
