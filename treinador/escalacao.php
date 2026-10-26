<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Treinador: Escalação (convocatória)
 * Para um jogo da equipa: quem é convocado, quem é titular, o capitão e o plano de jogo.
 * O jogo (?game_id=) e todos os jogadores têm de pertencer à equipa do treinador.
 */

$panel = 'treinador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

const MAX_CONVOCADOS = 12;
const TITULARES      = 5;
$posicoes = ['PG', 'SG', 'SF', 'PF', 'C'];

/** Devolve o jogo se for desta equipa, senão null. */
$carregarJogo = function (int $id) use ($pdo, $teamId): ?array {
    if ($id <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM games WHERE id = :id AND team_id = :team_id');
    $stmt->execute(['id' => $id, 'team_id' => $teamId]);
    $jogo = $stmt->fetch() ?: null;
    // um treinador só faz a convocatória de jogos dos escalões que treina
    return $jogo && escalao_allowed($GLOBALS['team'] ?? null, $jogo['escalao']) ? $jogo : null;
};

$jogadores = panel_players($pdo, $teamId);
$porId     = array_column($jogadores, null, 'id');

// ============================================================================
// GUARDAR
// ============================================================================
if (panel_is_post()) {
    $gameId    = (int) ($_POST['game_id'] ?? 0);
    $confirmar = ($_POST['action'] ?? '') === 'confirm';
    $jogo      = $carregarJogo($gameId);

    try {
        if (!$jogo) throw new RuntimeException('Jogo não encontrado.');

        // só contam jogadores desta equipa
        // ... e do escalão do jogo
        $convocados = array_values(array_filter(array_map('intval', array_keys((array) ($_POST['convocado'] ?? []))),
            fn($id) => isset($porId[$id]) && $porId[$id]['escalao'] === $jogo['escalao']));
        $titulares  = array_values(array_intersect(array_map('intval', array_keys((array) ($_POST['titular'] ?? []))), $convocados));
        $capitao    = (int) ($_POST['capitao'] ?? 0);
        if (!in_array($capitao, $convocados, true)) $capitao = 0;

        if (count($convocados) > MAX_CONVOCADOS) throw new RuntimeException('Só pode convocar até ' . MAX_CONVOCADOS . ' jogadores.');
        if (count($titulares) > TITULARES) throw new RuntimeException('O cinco inicial só pode ter ' . TITULARES . ' jogadores.');
        if ($confirmar && count($titulares) !== TITULARES) throw new RuntimeException('Para confirmar, escolha exatamente ' . TITULARES . ' titulares.');

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM escalacao WHERE game_id = :game_id')->execute(['game_id' => $gameId]);

        $insert = $pdo->prepare(
            'INSERT INTO escalacao (game_id, player_id, position, posicao_campo, is_starter, is_titular, is_captain, minutos_planeados)
             VALUES (:game_id, :player_id, :position, :position2, :starter, :starter2, :captain, :minutos)'
        );
        foreach ($convocados as $pid) {
            $pos = (string) ($_POST['posicao'][$pid] ?? $porId[$pid]['position']);
            if (!in_array($pos, $posicoes, true)) $pos = $porId[$pid]['position'];
            $min = trim((string) ($_POST['minutos'][$pid] ?? ''));
            $titular = in_array($pid, $titulares, true) ? 1 : 0;
            $insert->execute([
                'game_id' => $gameId, 'player_id' => $pid, 'position' => $pos, 'position2' => $pos,
                'starter' => $titular, 'starter2' => $titular, 'captain' => $pid === $capitao ? 1 : 0,
                'minutos' => $min === '' ? null : max(0, min(48, (int) $min)),
            ]);
        }

        $pdo->prepare('UPDATE games SET escalacao_status = :s, capitao_id = :c WHERE id = :id AND team_id = :team_id')
            ->execute(['s' => $confirmar ? 'confirmada' : 'rascunho', 'c' => $capitao ?: null, 'id' => $gameId, 'team_id' => $teamId]);

        $pdo->prepare(
            'INSERT INTO estrategias_jogo (game_id, team_id, title, sistema_ataque, sistema_defesa, plano_jogo, jogadas_especiais)
             VALUES (:game_id, :team_id, :title, :ataque, :defesa, :plano, :jogadas)
             ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), title = VALUES(title), sistema_ataque = VALUES(sistema_ataque),
                     sistema_defesa = VALUES(sistema_defesa), plano_jogo = VALUES(plano_jogo), jogadas_especiais = VALUES(jogadas_especiais)'
        )->execute([
            'game_id' => $gameId, 'team_id' => $teamId, 'title' => 'Plano para ' . $jogo['opponent'],
            'ataque'  => trim((string) ($_POST['sistema_ataque'] ?? '')) ?: null,
            'defesa'  => trim((string) ($_POST['sistema_defesa'] ?? '')) ?: null,
            'plano'   => trim((string) ($_POST['plano_jogo'] ?? '')) ?: null,
            'jogadas' => trim((string) ($_POST['jogadas_especiais'] ?? '')) ?: null,
        ]);
        $pdo->commit();

        $mensagem = $confirmar ? 'Convocatória confirmada.' : 'Rascunho da convocatória guardado.';

        // ao confirmar, avisa os convocados que têm conta
        if ($confirmar && !empty($_POST['notificar'])) {
            $aviso = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, :type)');
            $n = 0;
            foreach ($convocados as $pid) {
                if (empty($porId[$pid]['user_id'])) continue;
                $aviso->execute([
                    'user_id' => (int) $porId[$pid]['user_id'],
                    'title'   => 'Convocatória: vs ' . $jogo['opponent'],
                    'message' => 'Foi convocado para o jogo de ' . pub_date($jogo['game_date']) . ' ' . pub_time($jogo['game_time'])
                               . (in_array($pid, $titulares, true) ? ' (cinco inicial).' : '.'),
                    'type'    => 'success',
                ]);
                $n++;
            }
            $mensagem .= ' ' . $n . ' jogador(es) notificado(s).';
        }

        panel_log($pdo, $teamId, $confirmar ? 'convocatoria_confirmada' : 'convocatoria_guardada', 'vs ' . $jogo['opponent'] . ' (' . count($convocados) . ' convocados)');
        flash_set('success', $mensagem);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar a convocatória.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] treinador/escalacao: ' . $e->getMessage());
    }
    panel_redirect('escalacao.php' . ($jogo ? '?game_id=' . $gameId : ''));
}

// ============================================================================
// DADOS
// ============================================================================
$stmt = $pdo->prepare(
    "SELECT g.*, (SELECT COUNT(*) FROM escalacao e WHERE e.game_id = g.id) AS convocados
     FROM games g
     WHERE g.team_id = :team_id AND g.status <> 'finished'" . escalao_sql('g.escalao', $escalaoAtual = escalao_atual($team)) . "
     ORDER BY g.game_date, g.id"
);
$stmt->execute(['team_id' => $teamId]);
$jogos = $stmt->fetchAll();

$jogo = $carregarJogo((int) ($_GET['game_id'] ?? 0)) ?: ($jogos[0] ?? null);

$linhas = [];
$estrategia = [];
if ($jogo) {
    $stmt = $pdo->prepare('SELECT e.* FROM escalacao e JOIN games g ON g.id = e.game_id WHERE e.game_id = :game_id AND g.team_id = :team_id');
    $stmt->execute(['game_id' => (int) $jogo['id'], 'team_id' => $teamId]);
    $linhas = array_column($stmt->fetchAll(), null, 'player_id');

    $stmt = $pdo->prepare('SELECT * FROM estrategias_jogo WHERE game_id = :game_id');
    $stmt->execute(['game_id' => (int) $jogo['id']]);
    $estrategia = $stmt->fetch() ?: [];
}

$nConvocados = count($linhas);
$nTitulares  = count(array_filter($linhas, fn($l) => $l['is_starter'] || $l['is_titular']));
$confirmada  = $jogo && ($jogo['escalacao_status'] ?? '') === 'confirmada';

$pageTitle = 'Escalação';
$activeNav = 'escalacao';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Escalação', $jogo
        ? 'vs ' . $jogo['opponent'] . ' · ' . pub_date($jogo['game_date']) . ' ' . pub_time($jogo['game_time'])
        : 'Não há jogos por disputar. Agende um jogo para fazer a convocatória.',
        $jogo ? '<span class="lp-badge lp-badge--' . ($confirmada ? 'win' : 'gold') . '" style="align-self:center;">' . ($confirmada ? 'Confirmada' : 'Rascunho') . '</span>' : ''); ?>

    <?= escalao_chips($team, $escalaoAtual, 'escalacao.php') ?>

    <?php if (!$jogo): ?>
        <div class="lp-card"><div class="pn-empty"><i class="fa-solid fa-calendar-xmark"></i>Sem jogos por disputar. <a href="jogos.php" style="color:var(--accent-text);font-weight:600;">Agendar jogo</a></div></div>
    <?php else: ?>

    <div class="pn-cols">
        <form method="post" class="lp-stack" style="gap:24px;" id="escalacaoForm">
            <?= panel_csrf() ?><input type="hidden" name="game_id" value="<?= (int) $jogo['id'] ?>">

            <section class="lp-card">
                <div class="pn-section-title">
                    <h2><i class="fa-solid fa-list-ol"></i>Convocatória</h2>
                    <small><span id="contConvocados"><?= $nConvocados ?></span>/<?= MAX_CONVOCADOS ?> convocados · <span id="contTitulares"><?= $nTitulares ?></span>/<?= TITULARES ?> titulares</small>
                </div>
                <div class="lp-table-wrap"><table class="lp-table pn-table">
                    <thead><tr>
                        <th scope="col">Jogador</th><th scope="col">Convocado</th><th scope="col">Titular</th><th scope="col">Capitão</th>
                        <th scope="col">Posição</th><th scope="col" class="num">Minutos</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($jogadores as $j):
                            if ($jogo && $j['escalao'] !== $jogo['escalao']) continue;   // convocam-se jogadores do escalão do jogo
                            $pid   = (int) $j['id'];
                            $linha = $linhas[$pid] ?? null;
                            $ativo = $j['status'] === 'active';
                            $pos   = $linha['position'] ?? $j['position'];
                        ?>
                        <tr data-linha<?= $ativo ? '' : ' style="opacity:0.55;"' ?>>
                            <td><div class="pn-user"><?= pn_avatar($j['photo_url'], $j['name']) ?><div>
                                <b>#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?></b>
                                <small><?= pub_e(pub_position($j['position'])) ?> · <?= number_format((float) $j['avg_pts'], 1) ?> pts<?= $ativo ? '' : ' · ' . pub_e(pub_player_status($j['status'])[0]) ?></small></div></div></td>
                            <td><input type="checkbox" name="convocado[<?= $pid ?>]" value="1" data-conv <?= $linha ? 'checked' : '' ?> aria-label="Convocar <?= pub_e($j['name']) ?>" style="width:18px;height:18px;accent-color:var(--accent);"></td>
                            <td><input type="checkbox" name="titular[<?= $pid ?>]" value="1" data-tit <?= $linha && ($linha['is_starter'] || $linha['is_titular']) ? 'checked' : '' ?> aria-label="<?= pub_e($j['name']) ?> no cinco inicial" style="width:18px;height:18px;accent-color:var(--accent);"></td>
                            <td><input type="radio" name="capitao" value="<?= $pid ?>" data-cap <?= ($linha ? $linha['is_captain'] : false) || (!$linhas && $j['is_captain']) ? 'checked' : '' ?> aria-label="<?= pub_e($j['name']) ?> como capitão" style="width:18px;height:18px;accent-color:var(--accent);"></td>
                            <td>
                                <select name="posicao[<?= $pid ?>]" aria-label="Posição de <?= pub_e($j['name']) ?>" style="padding:7px 8px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-family:inherit;font-size:13px;">
                                    <?php foreach ($posicoes as $p): ?><option value="<?= $p ?>"<?= $p === $pos ? ' selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
                                </select>
                            </td>
                            <td class="num"><input class="pn-stat-input" type="number" min="0" max="48" name="minutos[<?= $pid ?>]" value="<?= pub_e($linha['minutos_planeados'] ?? '') ?>" aria-label="Minutos planeados de <?= pub_e($j['name']) ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if (!$jogadores): ?><div class="pn-empty"><i class="fa-solid fa-user-group"></i>A equipa ainda não tem jogadores.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-chess-board"></i>Plano de jogo</h2><a href="taticas.php">Caderno tático</a></div>
                <div class="lp-pad lp-form">
                    <div class="pn-form-grid">
                        <div class="lp-field"><label for="e-ataque">Sistema de ataque</label><input id="e-ataque" name="sistema_ataque" type="text" maxlength="100" value="<?= pub_e($estrategia['sistema_ataque'] ?? '') ?>" placeholder="Motion 4-out 1-in"></div>
                        <div class="lp-field"><label for="e-defesa">Sistema de defesa</label><input id="e-defesa" name="sistema_defesa" type="text" maxlength="100" value="<?= pub_e($estrategia['sistema_defesa'] ?? '') ?>" placeholder="Homem a homem"></div>
                        <div class="lp-field full"><label for="e-plano">Plano de jogo</label><textarea id="e-plano" name="plano_jogo" style="min-height:110px;"><?= pub_e($estrategia['plano_jogo'] ?? '') ?></textarea></div>
                        <div class="lp-field full"><label for="e-jogadas">Jogadas especiais</label><textarea id="e-jogadas" name="jogadas_especiais" style="min-height:80px;"><?= pub_e($estrategia['jogadas_especiais'] ?? '') ?></textarea></div>
                    </div>
                    <label class="lp-check"><input type="checkbox" name="notificar" value="1" checked><span>Ao confirmar, notificar os convocados que têm conta de acesso</span></label>
                    <div class="pn-form-actions">
                        <button type="submit" name="action" value="save" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-floppy-disk"></i> Guardar rascunho</button>
                        <button type="submit" name="action" value="confirm" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Confirmar convocatória</button>
                    </div>
                </div>
            </section>
        </form>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-trophy"></i>Jogos por disputar</h2></div>
            <div class="pn-list">
                <?php foreach ($jogos as $g): $atual = (int) $g['id'] === (int) $jogo['id']; ?>
                <a href="?game_id=<?= (int) $g['id'] ?>" style="<?= $atual ? 'background:var(--glow);' : '' ?>">
                    <div class="grow"><b>vs <?= pub_e($g['opponent']) ?></b><small><?= pub_e(pub_date($g['game_date'])) ?> · <?= ($g['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></small></div>
                    <span class="lp-badge lp-badge--<?= ($g['escalacao_status'] ?? '') === 'confirmada' ? 'win' : ((int) $g['convocados'] ? 'gold' : '') ?>">
                        <?= ($g['escalacao_status'] ?? '') === 'confirmada' ? 'Confirmada' : ((int) $g['convocados'] ? 'Rascunho' : 'Por fazer') ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <script>
        (function () {
            var form = document.getElementById('escalacaoForm');
            if (!form) return;
            var MAX = <?= MAX_CONVOCADOS ?>, FIVE = <?= TITULARES ?>;

            function update(changed) {
                var conv = form.querySelectorAll('[data-conv]:checked').length;
                var tit = form.querySelectorAll('[data-tit]:checked').length;
                if (changed && changed.checked) {
                    if (changed.hasAttribute('data-conv') && conv > MAX) { changed.checked = false; conv--; }
                    if (changed.hasAttribute('data-tit') && tit > FIVE) { changed.checked = false; tit--; }
                }
                document.getElementById('contConvocados').textContent = conv;
                document.getElementById('contTitulares').textContent = tit;
            }

            form.addEventListener('change', function (e) {
                var row = e.target.closest('[data-linha]');
                if (!row) return;
                var conv = row.querySelector('[data-conv]'), tit = row.querySelector('[data-tit]'), cap = row.querySelector('[data-cap]');
                // titular ou capitão implica estar convocado; desconvocar limpa o resto
                if ((e.target === tit || e.target === cap) && e.target.checked) conv.checked = true;
                if (e.target === conv && !conv.checked) { tit.checked = false; cap.checked = false; }
                update(e.target);
                update(null);
            });
        })();
    </script>

    <?php endif; ?>
</div>

<?php require panel_layout('bottom'); ?>
