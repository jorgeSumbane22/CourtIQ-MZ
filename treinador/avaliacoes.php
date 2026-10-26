<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Treinador: Avaliações
 * Avaliação dos jogadores da equipa por critérios (0 a 100). A nota geral é a média
 * dos critérios preenchidos.
 */

$panel = 'treinador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$grupos = [
    'Técnica' => ['passe' => 'Passe', 'drible' => 'Drible', 'lancamento' => 'Lançamento', 'finalizacao' => 'Finalização', 'controlo_bola' => 'Controlo de bola'],
    'Tática'  => ['posicionamento' => 'Posicionamento', 'leitura_jogo' => 'Leitura de jogo', 'decisao' => 'Tomada de decisão', 'defesa_coletiva' => 'Defesa coletiva'],
    'Física'  => ['resistencia' => 'Resistência', 'velocidade' => 'Velocidade', 'agilidade' => 'Agilidade', 'forca' => 'Força', 'recuperacao' => 'Recuperação'],
    'Mental'  => ['concentracao' => 'Concentração', 'disciplina' => 'Disciplina', 'lideranca' => 'Liderança', 'comunicacao' => 'Comunicação', 'atitude' => 'Atitude'],
];
$criterios = array_merge(...array_values($grupos));
$tipos     = ['treino' => 'Treino', 'jogo' => 'Jogo', 'mensal' => 'Balanço mensal'];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');

    try {
        if ($acao === 'save') {
            $playerId = (int) ($_POST['player_id'] ?? 0);
            if (!team_owns('players', $playerId)) throw new RuntimeException('Esse jogador não pertence à sua equipa.');
            $stmt = $pdo->prepare('SELECT escalao FROM players WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $playerId, 'team_id' => $teamId]);
            if (!escalao_allowed($team, (string) $stmt->fetchColumn())) throw new RuntimeException('Esse jogador não é de um escalão que treina.');

            $data = trim((string) ($_POST['data_avaliacao'] ?? ''));
            if (!strtotime($data)) throw new RuntimeException('Indique a data da avaliação.');

            $valores = [];
            foreach ($criterios as $campo => $_) {
                $bruto = trim((string) ($_POST[$campo] ?? ''));
                $valores[$campo] = $bruto === '' ? 0 : max(0, min(100, (int) $bruto));
            }
            $preenchidos = array_filter($valores);
            if (!$preenchidos) throw new RuntimeException('Preencha pelo menos um critério.');
            $nota = round(array_sum($preenchidos) / count($preenchidos), 2);

            $colunas = array_keys($valores);
            $sql = 'INSERT INTO avaliacoes (player_id, team_id, tipo, data_avaliacao, nota_geral, nota_total, estrelas, feedback, comentario, objetivos, '
                 . implode(', ', $colunas) . ') VALUES (:player_id, :team_id, :tipo, :data, :nota, :nota10, :estrelas, :feedback, :feedback2, :objetivos, :'
                 . implode(', :', $colunas) . ')';
            $feedback = trim((string) ($_POST['feedback'] ?? '')) ?: null;
            $pdo->prepare($sql)->execute($valores + [
                'player_id' => $playerId, 'team_id' => $teamId,
                'tipo'      => isset($tipos[$_POST['tipo'] ?? '']) ? (string) $_POST['tipo'] : 'treino',
                'data'      => $data, 'nota' => $nota, 'nota10' => round($nota / 10, 1), 'estrelas' => (int) max(1, min(5, round($nota / 20))),
                'feedback'  => $feedback, 'feedback2' => $feedback,
                'objetivos' => trim((string) ($_POST['objetivos'] ?? '')) ?: null,
            ]);
            panel_log($pdo, $teamId, 'avaliacao_registada', 'Jogador #' . $playerId . ' — nota ' . $nota);
            flash_set('success', 'Avaliação registada (nota geral ' . number_format($nota, 0) . ').');

        } elseif ($acao === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if (!team_owns('avaliacoes', $id)) throw new RuntimeException('Avaliação não encontrada.');
            $pdo->prepare('DELETE FROM avaliacoes WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
            flash_set('success', 'Avaliação removida.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar a avaliação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] treinador/avaliacoes: ' . $e->getMessage());
    }
    panel_redirect('avaliacoes.php');
}

$escalaoAtual = escalao_atual($team);
$jogadores = escalao_only(panel_players($pdo, $teamId), $escalaoAtual);

$stmt = $pdo->prepare(
    'SELECT a.*, COALESCE(u.name, p.name) AS jogador, p.jersey_number, p.photo_url
     FROM avaliacoes a
     JOIN players p ON p.id = a.player_id
     LEFT JOIN users u ON u.id = p.user_id
     WHERE a.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual) . '
     ORDER BY a.data_avaliacao DESC, a.id DESC'
);
$stmt->execute(['team_id' => $teamId]);
$avaliacoes = $stmt->fetchAll();

// última avaliação e evolução de cada jogador
$porJogador = [];
foreach ($avaliacoes as $a) {
    $porJogador[(int) $a['player_id']][] = $a;     // já vêm da mais recente para a mais antiga
}
$media = $avaliacoes ? array_sum(array_map(fn($a) => (float) $a['nota_geral'], $avaliacoes)) / count($avaliacoes) : 0;
$semAvaliacao = count(array_filter($jogadores, fn($j) => empty($porJogador[(int) $j['id']])));

$pageTitle = 'Avaliações';
$activeNav = 'avaliacoes';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Avaliações', count($avaliacoes) . ' avaliações registadas em ' . $team['name'] . '.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#avaliacaoModal" data-reset><i class="fa-solid fa-plus"></i> Nova avaliação</button>'); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $media > 0 ? number_format($media, 0) : '—' ?></b><span>Nota média da equipa (0–100)</span></div>
        <div class="lp-card lp-kpi"><b><?= count($porJogador) ?></b><span>Jogadores avaliados</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $semAvaliacao ? 'down' : 'up' ?>"><b><?= $semAvaliacao ?></b><span>Por avaliar</span></div>
    </section>

    <div class="pn-cols">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-star"></i>Plantel</h2><small>Última avaliação de cada jogador</small></div>
            <div class="lp-table-wrap"><table class="lp-table pn-table">
                <thead><tr><th scope="col">Jogador</th><th scope="col">Última</th><th scope="col">Nota</th><th scope="col">Evolução</th><th scope="col" class="end">Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($jogadores as $j):
                        $hist   = $porJogador[(int) $j['id']] ?? [];
                        $ultima = $hist[0] ?? null;
                        $diff   = isset($hist[1]) ? (float) $hist[0]['nota_geral'] - (float) $hist[1]['nota_geral'] : null;
                    ?>
                    <tr>
                        <td><div class="pn-user"><?= pn_avatar($j['photo_url'], $j['name']) ?><div><b>#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?></b><small><?= pub_e(pub_position($j['position'])) ?></small></div></div></td>
                        <td><?= $ultima ? pub_e(pub_date($ultima['data_avaliacao'])) : '<span style="color:var(--muted);">—</span>' ?><small><?= count($hist) ?> avaliação(ões)</small></td>
                        <td><?php if ($ultima): ?>
                            <span class="lp-bar-row" style="grid-template-columns:90px 36px;gap:8px;">
                                <span class="lp-bar <?= (float) $ultima['nota_geral'] >= 75 ? 'lp-bar--green' : '' ?>"><i style="width:<?= min(100, (int) $ultima['nota_geral']) ?>%"></i></span>
                                <b><?= number_format((float) $ultima['nota_geral'], 0) ?></b>
                            </span>
                            <?php else: ?><span class="lp-badge">Por avaliar</span><?php endif; ?></td>
                        <td><?php if ($diff !== null): ?>
                            <span class="lp-badge lp-badge--<?= $diff > 0 ? 'win' : ($diff < 0 ? 'loss' : '') ?>"><i class="fa-solid fa-arrow-<?= $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'right') ?>"></i> <?= ($diff > 0 ? '+' : '') . number_format($diff, 0) ?></span>
                            <?php else: ?><span style="color:var(--muted);">—</span><?php endif; ?></td>
                        <td class="end"><button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal="#avaliacaoModal" data-reset data-values="<?= pn_values(['player_id' => (int) $j['id']]) ?>"><i class="fa-solid fa-star"></i> Avaliar</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if (!$jogadores): ?><div class="pn-empty"><i class="fa-solid fa-user-group"></i>A equipa ainda não tem jogadores.</div><?php endif; ?>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-clock-rotate-left"></i>Histórico</h2><small>Mais recentes</small></div>
            <div class="pn-list">
                <?php foreach (array_slice($avaliacoes, 0, 12) as $a): ?>
                <div style="align-items:flex-start;">
                    <b style="font-family:var(--display);font-size:26px;font-weight:800;color:var(--accent-text);width:44px;text-align:center;line-height:1;"><?= number_format((float) $a['nota_geral'], 0) ?></b>
                    <div class="grow">
                        <b style="white-space:normal;">#<?= (int) $a['jersey_number'] ?> <?= pub_e($a['jogador']) ?></b>
                        <small><?= pub_e(pub_date($a['data_avaliacao'])) ?> · <?= pub_e($tipos[$a['tipo']] ?? ucfirst((string) $a['tipo'])) ?></small>
                        <?php if (!empty($a['feedback']) || !empty($a['comentario'])): ?><small style="white-space:normal;font-size:13px;color:var(--text-secondary);margin-top:4px;"><?= pub_e($a['feedback'] ?: $a['comentario']) ?></small><?php endif; ?>
                    </div>
                    <form method="post" data-confirm="Remover esta avaliação de <?= pub_e($a['jogador']) ?>?"><?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover avaliação"><i class="fa-solid fa-trash"></i></button></form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$avaliacoes): ?><div class="pn-empty"><i class="fa-solid fa-star"></i>Ainda não há avaliações.</div><?php endif; ?>
        </section>
    </div>
</div>

<dialog class="pn-modal pn-modal--wide" id="avaliacaoModal">
    <form method="post">
        <div class="pn-modal-head"><h2>Nova avaliação</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form">
            <?= panel_csrf() ?><input type="hidden" name="action" value="save">
            <div class="pn-form-grid">
                <div class="lp-field full"><label for="a-player">Jogador</label>
                    <select id="a-player" name="player_id" required>
                        <?php foreach ($jogadores as $j): ?><option value="<?= (int) $j['id'] ?>">#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="lp-field"><label for="a-tipo">Tipo</label>
                    <select id="a-tipo" name="tipo"><?php foreach ($tipos as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="lp-field"><label for="a-data">Data</label><input id="a-data" name="data_avaliacao" type="date" required value="<?= date('Y-m-d') ?>"></div>
            </div>

            <p class="pn-help" style="margin:0;">Notas de 0 a 100. Deixe em branco os critérios que não avaliou: não entram na média.</p>
            <?php foreach ($grupos as $grupo => $campos): ?>
            <fieldset style="border:1px solid var(--border);border-radius:12px;padding:14px 16px;">
                <legend class="lp-sub" style="margin:0;padding:0 8px;"><?= $grupo ?></legend>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;">
                    <?php foreach ($campos as $campo => $rotulo): ?>
                    <div class="lp-field"><label for="a-<?= $campo ?>"><?= $rotulo ?></label><input id="a-<?= $campo ?>" name="<?= $campo ?>" type="number" min="0" max="100" inputmode="numeric"></div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <?php endforeach; ?>

            <div class="lp-field"><label for="a-feedback">Comentário para o jogador</label><textarea id="a-feedback" name="feedback" style="min-height:80px;"></textarea></div>
            <div class="lp-field"><label for="a-objetivos">Objetivos para trabalhar</label><textarea id="a-objetivos" name="objetivos" style="min-height:70px;"></textarea></div>
        </div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Registar avaliação</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
