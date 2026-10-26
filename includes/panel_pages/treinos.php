<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Treinos
 * Agendar, editar e remover os treinos da equipa.
 */


[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

$tipos   = ['tactical', 'technical', 'physical', 'collective', 'strategic', 'recovery'];
$estados = ['scheduled', 'in_progress', 'finished', 'cancelled'];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    $id   = (int) ($_POST['training_id'] ?? 0);

    try {
        if ($acao !== 'create' && !team_owns('trainings', $id)) {
            throw new RuntimeException('Treino não encontrado.');
        }
        if ($acao !== 'create') {
            // um treinador só mexe nos treinos dos escalões que treina
            $stmt = $pdo->prepare('SELECT escalao FROM trainings WHERE id = :id AND team_id = :team_id');
            $stmt->execute(['id' => $id, 'team_id' => $teamId]);
            if (!escalao_allowed($team, (string) $stmt->fetchColumn())) throw new RuntimeException('Esse treino não é de um escalão que treina.');
        }

        if ($acao === 'create' || $acao === 'update') {
            $dados = [
                'name'          => trim((string) ($_POST['name'] ?? '')),
                'training_date' => trim((string) ($_POST['training_date'] ?? '')),
                'start_time'    => trim((string) ($_POST['start_time'] ?? '')) ?: null,
                'end_time'      => trim((string) ($_POST['end_time'] ?? '')) ?: null,
                'location'      => trim((string) ($_POST['location'] ?? '')) ?: null,
                'type'          => (string) ($_POST['type'] ?? 'tactical'),
                'objective'     => trim((string) ($_POST['objective'] ?? '')) ?: null,
                'notes'         => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'status'        => (string) ($_POST['status'] ?? 'scheduled'),
                'escalao'       => escalao_from_post($team, $_POST['escalao'] ?? ''),
            ];
            if ($dados['name'] === '' || mb_strlen($dados['name']) > 100) throw new RuntimeException('Indique o nome do treino.');
            if (!strtotime($dados['training_date'])) throw new RuntimeException('Indique a data do treino.');
            if (!in_array($dados['type'], $tipos, true)) throw new RuntimeException('Tipo de treino inválido.');
            if (!in_array($dados['status'], $estados, true)) $dados['status'] = 'scheduled';
            if ($dados['start_time'] && $dados['end_time'] && $dados['end_time'] <= $dados['start_time']) {
                throw new RuntimeException('A hora de fim tem de ser depois da hora de início.');
            }

            if ($acao === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO trainings (team_id, name, training_date, start_time, end_time, training_time, location, type, escalao, objective, description, notes, status)
                     VALUES (:team_id, :name, :training_date, :start_time, :end_time, :start_time2, :location, :type, :escalao, :objective, :objective2, :notes, :status)'
                );
                $stmt->execute($dados + ['team_id' => $teamId, 'start_time2' => $dados['start_time'], 'objective2' => $dados['objective']]);
                panel_log($pdo, $teamId, 'treino_agendado', $dados['name'] . ' em ' . $dados['training_date']);
                flash_set('success', 'Treino agendado.');
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE trainings SET name = :name, training_date = :training_date, start_time = :start_time, end_time = :end_time,
                            training_time = :start_time2, location = :location, type = :type, escalao = :escalao, objective = :objective, description = :objective2,
                            notes = :notes, status = :status
                     WHERE id = :id AND team_id = :team_id'
                );
                $stmt->execute($dados + ['id' => $id, 'team_id' => $teamId, 'start_time2' => $dados['start_time'], 'objective2' => $dados['objective']]);
                flash_set('success', 'Treino atualizado.');
            }

        } elseif ($acao === 'delete') {
            $pdo->prepare("DELETE a FROM attendance a WHERE a.event_type = 'training' AND a.event_id = :id")->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM trainings WHERE id = :id AND team_id = :team_id')->execute(['id' => $id, 'team_id' => $teamId]);
            panel_log($pdo, $teamId, 'treino_removido', '#' . $id);
            flash_set('success', 'Treino removido.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/treinos: ' . $e->getMessage());
    }
    panel_redirect('treinos.php');
}

// Treinos com a contagem de presenças
$stmt = $pdo->prepare(
    "SELECT t.*,
            (SELECT COUNT(*) FROM attendance a WHERE a.event_type = 'training' AND a.event_id = t.id) AS marcados,
            (SELECT COALESCE(SUM(a.present), 0) FROM attendance a WHERE a.event_type = 'training' AND a.event_id = t.id) AS presentes
     FROM trainings t
     WHERE t.team_id = :team_id
     ORDER BY t.training_date DESC, t.start_time DESC"
);
$stmt->execute(['team_id' => $teamId]);
$treinos = $stmt->fetchAll();
$escalaoAtual = escalao_atual($team);
if ($escalaoAtual !== '') {
    $treinos = array_values(array_filter($treinos, fn($t) => $t['escalao'] === $escalaoAtual));
}

$hoje      = date('Y-m-d');
$agendados = count(array_filter($treinos, fn($t) => $t['status'] === 'scheduled' && $t['training_date'] >= $hoje));
$feitos    = count(array_filter($treinos, fn($t) => $t['status'] === 'finished'));

$pageTitle = 'Treinos';
$activeNav = 'treinos';
require panel_layout('top');

$campos = function (string $p, bool $comEstado) use ($tipos, $estados, $team, $escalaoAtual): void { ?>
    <div class="pn-form-grid">
        <div class="lp-field"><label for="<?= $p ?>name">Nome do treino</label><input id="<?= $p ?>name" name="name" type="text" maxlength="100" required placeholder="Treino tático"></div>
        <div class="lp-field"><label for="<?= $p ?>esc">Escalão</label>
            <select id="<?= $p ?>esc" name="escalao" required><?= escalao_options($team, $escalaoAtual ?: 'seniores') ?></select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>date">Data</label><input id="<?= $p ?>date" name="training_date" type="date" required></div>
        <div class="lp-field"><label for="<?= $p ?>type">Tipo</label>
            <select id="<?= $p ?>type" name="type"><?php foreach ($tipos as $t): ?><option value="<?= $t ?>"><?= pub_e(pn_training_type($t)) ?></option><?php endforeach; ?></select>
        </div>
        <div class="lp-field"><label for="<?= $p ?>start">Início</label><input id="<?= $p ?>start" name="start_time" type="time"></div>
        <div class="lp-field"><label for="<?= $p ?>end">Fim</label><input id="<?= $p ?>end" name="end_time" type="time"></div>
        <div class="lp-field<?= $comEstado ? '' : ' full' ?>"><label for="<?= $p ?>loc">Local</label><input id="<?= $p ?>loc" name="location" type="text" maxlength="255" placeholder="<?= pub_e($team['stadium'] ?? '') ?>"></div>
        <?php if ($comEstado): ?>
        <div class="lp-field"><label for="<?= $p ?>status">Estado</label>
            <select id="<?= $p ?>status" name="status"><?php foreach ($estados as $e): ?><option value="<?= $e ?>"><?= pn_event_status($e)[0] ?></option><?php endforeach; ?></select>
        </div>
        <?php endif; ?>
        <div class="lp-field full"><label for="<?= $p ?>obj">Objetivo</label><input id="<?= $p ?>obj" name="objective" type="text" maxlength="255"></div>
        <div class="lp-field full"><label for="<?= $p ?>notes">Notas</label><textarea id="<?= $p ?>notes" name="notes" style="min-height:90px;"></textarea></div>
    </div>
<?php }; ?>

<div class="lp pn">
    <?php panel_head('Treinos', $agendados . ' agendados · ' . $feitos . ' realizados.',
        '<button type="button" class="lp-btn lp-btn--primary lp-btn--sm" data-modal="#novoTreino" data-reset><i class="fa-solid fa-plus"></i> Agendar treino</button>'); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section class="lp-card" data-filter>
        <div class="pn-section-title">
            <h2><i class="fa-solid fa-bullseye"></i>Todos os treinos</h2>
            <div class="lp-chips" role="group" aria-label="Filtrar treinos">
                <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos</button>
                <button type="button" class="lp-chip" data-filter-value="scheduled" aria-pressed="false">Agendados</button>
                <button type="button" class="lp-chip" data-filter-value="finished" aria-pressed="false">Realizados</button>
            </div>
        </div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Data</th><th scope="col">Treino</th><th scope="col">Tipo</th><th scope="col">Estado</th><th scope="col">Presenças</th><th scope="col" class="end">Ações</th></tr></thead>
            <tbody>
                <?php foreach ($treinos as $t): [$rotulo, $tipo] = pn_event_status($t['status']); ?>
                <tr data-filter-item data-tags="<?= pub_e($t['status']) ?>">
                    <td><b><?= pub_e(pub_date($t['training_date'])) ?></b><small><?= pub_e(pub_time($t['start_time'])) ?><?= $t['end_time'] ? '–' . pub_e(pub_time($t['end_time'])) : '' ?></small></td>
                    <td class="lp-table-team"><?= pub_e($t['name']) ?><small><?= pub_e(escalao_label($t['escalao'])) ?> · <?= pub_e($t['objective'] ?: ($t['location'] ?: '—')) ?></small></td>
                    <td><span class="lp-badge lp-badge--accent"><?= pub_e(pn_training_type($t['type'])) ?></span></td>
                    <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= $rotulo ?></span></td>
                    <td><?= (int) $t['marcados'] ? (int) $t['presentes'] . ' / ' . (int) $t['marcados'] : '<span style="color:var(--muted);">Por marcar</span>' ?></td>
                    <td class="end"><span class="pn-row-actions">
                        <a class="lp-btn lp-btn--ghost lp-btn--sm" href="presencas.php?treino_id=<?= (int) $t['id'] ?>"><i class="fa-solid fa-clipboard-check"></i> Presenças</a>
                        <button type="button" class="pn-icon-btn" title="Editar" aria-label="Editar treino" data-modal="#editarTreino" data-values="<?= pn_values([
                            'training_id' => (int) $t['id'], 'name' => $t['name'], 'training_date' => $t['training_date'], 'start_time' => pub_time($t['start_time']),
                            'end_time' => pub_time($t['end_time']), 'location' => $t['location'], 'type' => $t['type'], 'objective' => $t['objective'],
                            'notes' => $t['notes'], 'status' => $t['status'], 'escalao' => $t['escalao'],
                        ]) ?>"><i class="fa-solid fa-pen"></i></button>
                        <form method="post" data-confirm="Remover o treino '<?= pub_e($t['name']) ?>' e as presenças associadas?">
                            <?= panel_csrf() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="training_id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Remover" aria-label="Remover treino"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="pn-empty" data-filter-empty <?= $treinos ? 'hidden' : '' ?>><i class="fa-solid fa-bullseye"></i><?= $treinos ? 'Nenhum treino neste filtro.' : 'Ainda não há treinos. Agende o primeiro.' ?></p>
    </section>
</div>

<dialog class="pn-modal" id="novoTreino">
    <form method="post">
        <div class="pn-modal-head"><h2>Agendar treino</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form"><?= panel_csrf() ?><input type="hidden" name="action" value="create"><?php $campos('n-', false); ?></div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Agendar</button>
        </div>
    </form>
</dialog>

<dialog class="pn-modal" id="editarTreino">
    <form method="post">
        <div class="pn-modal-head"><h2>Editar treino</h2><button type="button" class="pn-icon-btn" data-modal-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="pn-modal-body lp-form"><?= panel_csrf() ?><input type="hidden" name="action" value="update"><input type="hidden" name="training_id"><?php $campos('e-', true); ?></div>
        <div class="pn-modal-foot">
            <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" data-modal-close>Cancelar</button>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar</button>
        </div>
    </form>
</dialog>

<?php require panel_layout('bottom'); ?>
