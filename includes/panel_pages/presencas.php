<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Presenças
 * Marca as presenças dos jogadores num treino (?treino_id=) ou num jogo (?jogo_id=).
 * O evento e os jogadores têm de pertencer à equipa do administrador.
 */


[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());

/** Devolve o evento (treino ou jogo) se for desta equipa, senão null. */
$carregarEvento = function (string $tipo, int $id) use ($pdo, $teamId): ?array {
    if ($id <= 0) return null;
    $sql = $tipo === 'game'
        ? "SELECT id, CONCAT('Jogo vs ', opponent) AS titulo, game_date AS data, game_time AS hora, location, escalao FROM games WHERE id = :id AND team_id = :team_id"
        : 'SELECT id, name AS titulo, training_date AS data, start_time AS hora, location, escalao FROM trainings WHERE id = :id AND team_id = :team_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id, 'team_id' => $teamId]);
    $evento = $stmt->fetch() ?: null;
    // um treinador só abre treinos e jogos dos escalões que treina
    return $evento && escalao_allowed($GLOBALS['team'] ?? null, $evento['escalao']) ? $evento : null;
};

// ============================================================================
// GUARDAR
// ============================================================================
if (panel_is_post()) {
    $tipo   = ($_POST['event_type'] ?? '') === 'game' ? 'game' : 'training';
    $id     = (int) ($_POST['event_id'] ?? 0);
    $voltar = 'presencas.php?' . ($tipo === 'game' ? 'jogo_id=' : 'treino_id=') . $id;

    $eventoGuardar = $carregarEvento($tipo, $id);
    if (!$eventoGuardar) {
        flash_set('error', 'Evento não encontrado.');
        panel_redirect('presencas.php');
    }

    // só jogadores desta equipa e do escalão do treino/jogo
    $stmt = $pdo->prepare('SELECT id, status FROM players WHERE team_id = :team_id AND escalao = :escalao');
    $stmt->execute(['team_id' => $teamId, 'escalao' => $eventoGuardar['escalao']]);
    $daEquipa = array_column($stmt->fetchAll(), 'status', 'id');

    $upsert = $pdo->prepare(
        'INSERT INTO attendance (event_type, event_id, player_id, present, status, justification, marked_at)
         VALUES (:type, :event_id, :player_id, :present, :status, :just, NOW())
         ON DUPLICATE KEY UPDATE present = VALUES(present), status = VALUES(status), justification = VALUES(justification), marked_at = NOW()'
    );

    $gravados = 0;
    foreach ((array) ($_POST['presente'] ?? []) as $playerId => $valor) {
        $playerId = (int) $playerId;
        if (!isset($daEquipa[$playerId])) continue;
        $presente = $valor === '1';
        $just     = trim((string) ($_POST['justificacao'][$playerId] ?? '')) ?: null;
        $upsert->execute([
            'type' => $tipo, 'event_id' => $id, 'player_id' => $playerId,
            'present' => $presente ? 1 : 0,
            'status'  => $presente ? 'present' : ($just ? 'excused' : 'absent'),
            'just'    => $presente ? null : $just,
        ]);
        $gravados++;
    }
    panel_log($pdo, $teamId, 'presencas_marcadas', ($tipo === 'game' ? 'Jogo' : 'Treino') . " #$id ($gravados jogadores)");
    flash_set('success', 'Presenças guardadas para ' . $gravados . ' jogadores.');
    panel_redirect($voltar);
}

// ============================================================================
// DADOS
// ============================================================================
$tipo   = isset($_GET['jogo_id']) ? 'game' : 'training';
$id     = (int) ($_GET['jogo_id'] ?? $_GET['treino_id'] ?? 0);
$evento = $carregarEvento($tipo, $id);

$lista = [];
if ($evento) {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.jersey_number, p.position, p.status AS player_status, p.photo_url, COALESCE(u.name, p.name) AS name,
                a.present, a.justification
         FROM players p
         LEFT JOIN users u ON u.id = p.user_id
         LEFT JOIN attendance a ON a.player_id = p.id AND a.event_type = :type AND a.event_id = :event_id
         WHERE p.team_id = :team_id AND p.escalao = :escalao
         ORDER BY p.jersey_number'
    );
    $stmt->execute(['type' => $tipo, 'event_id' => $id, 'team_id' => $teamId, 'escalao' => $evento['escalao']]);
    $lista = $stmt->fetchAll();
}

// Eventos recentes para escolher
$escalaoAtual = escalao_atual($team);
$stmt = $pdo->prepare(
    "SELECT 'training' AS tipo, id, name AS titulo, training_date AS data FROM trainings WHERE team_id = :t1" . escalao_sql('escalao', $escalaoAtual) . "
     UNION ALL
     SELECT 'game', id, CONCAT('Jogo vs ', opponent), game_date FROM games WHERE team_id = :t2" . escalao_sql('escalao', $escalaoAtual) . "
     ORDER BY data DESC LIMIT 30"
);
$stmt->execute(['t1' => $teamId, 't2' => $teamId]);
$eventos = $stmt->fetchAll();

// Taxa de presença por jogador (treinos e jogos)
$stmt = $pdo->prepare(
    'SELECT p.id, p.jersey_number, COALESCE(u.name, p.name) AS name, COUNT(a.id) AS total, COALESCE(SUM(a.present), 0) AS presentes
     FROM players p
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN attendance a ON a.player_id = p.id
     WHERE p.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual) . '
     GROUP BY p.id
     ORDER BY p.jersey_number'
);
$stmt->execute(['team_id' => $teamId]);
$taxas = $stmt->fetchAll();

$pageTitle = 'Presenças';
$activeNav = 'presencas';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Presenças', $evento
        ? $evento['titulo'] . ' · ' . pub_date($evento['data']) . ' ' . pub_time($evento['hora'])
        : 'Escolha um treino ou um jogo para marcar as presenças.'); ?>

    <?= escalao_chips($team, $escalaoAtual, 'presencas.php') ?>

    <div class="pn-cols">
        <div class="lp-stack" style="gap:24px;">
            <?php if ($evento): ?>
            <form method="post" class="lp-card">
                <?= panel_csrf() ?>
                <input type="hidden" name="event_type" value="<?= $tipo ?>"><input type="hidden" name="event_id" value="<?= (int) $id ?>">
                <div class="pn-section-title">
                    <h2><i class="fa-solid fa-clipboard-check"></i><?= pub_e($evento['titulo']) ?></h2>
                    <button type="button" class="lp-btn lp-btn--ghost lp-btn--sm" id="todosPresentes"><i class="fa-solid fa-check-double"></i> Todos presentes</button>
                </div>
                <div class="pn-check-list">
                    <?php foreach ($lista as $l):
                        $pid = (int) $l['id'];
                        $presente = $l['present'] === null ? null : (bool) $l['present'];
                    ?>
                    <div class="pn-check-row">
                        <div class="pn-user">
                            <?= pn_avatar($l['photo_url'], $l['name']) ?>
                            <div><b>#<?= (int) $l['jersey_number'] ?> <?= pub_e($l['name']) ?></b>
                                <small><?= pub_e(pub_position($l['position'])) ?><?= $l['player_status'] !== 'active' ? ' · ' . pub_e(pub_player_status($l['player_status'])[0]) : '' ?></small></div>
                        </div>
                        <div class="pn-switch" role="radiogroup" aria-label="Presença de <?= pub_e($l['name']) ?>">
                            <label><input type="radio" name="presente[<?= $pid ?>]" value="1" <?= $presente === true ? 'checked' : '' ?> required><span class="yes">Presente</span></label>
                            <label><input type="radio" name="presente[<?= $pid ?>]" value="0" <?= $presente === false ? 'checked' : '' ?>><span class="no">Ausente</span></label>
                        </div>
                        <input type="text" name="justificacao[<?= $pid ?>]" maxlength="255" placeholder="Justificação (se ausente)" value="<?= pub_e($l['justification']) ?>" aria-label="Justificação de <?= pub_e($l['name']) ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$lista): ?><div class="pn-empty"><i class="fa-solid fa-user-group"></i>A equipa ainda não tem jogadores.</div><?php endif; ?>
                <div class="pn-modal-foot">
                    <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar presenças</button>
                </div>
            </form>
            <?php else: ?>
            <div class="lp-card"><div class="pn-empty"><i class="fa-solid fa-hand-pointer"></i>Escolha um evento na lista ao lado.</div></div>
            <?php endif; ?>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-chart-simple"></i>Assiduidade do plantel</h2><small>Treinos e jogos</small></div>
                <div class="lp-pad"><div class="lp-bars">
                    <?php foreach ($taxas as $t): $pct = (int) $t['total'] ? round((int) $t['presentes'] / (int) $t['total'] * 100) : 0; ?>
                    <div class="lp-bar-row">
                        <span title="<?= pub_e($t['name']) ?>">#<?= (int) $t['jersey_number'] ?> <?= pub_e($t['name']) ?></span>
                        <span class="lp-bar <?= $pct >= 75 ? 'lp-bar--green' : '' ?>"><i style="width:<?= $pct ?>%"></i></span>
                        <b><?= (int) $t['total'] ? $pct . '%' : '—' ?></b>
                    </div>
                    <?php endforeach; ?>
                </div></div>
            </section>
        </div>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-list"></i>Eventos</h2><small>Mais recentes</small></div>
            <div class="pn-list">
                <?php foreach ($eventos as $ev):
                    $ativo = $evento && $ev['tipo'] === $tipo && (int) $ev['id'] === $id;
                    $link  = '?' . ($ev['tipo'] === 'game' ? 'jogo_id=' : 'treino_id=') . (int) $ev['id'];
                ?>
                <a href="<?= $link ?>" style="<?= $ativo ? 'background:var(--glow);' : '' ?>">
                    <span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-<?= $ev['tipo'] === 'game' ? 'trophy' : 'bullseye' ?>"></i></span>
                    <div class="grow"><b><?= pub_e($ev['titulo']) ?></b><small><?= pub_e(pub_date($ev['data'])) ?></small></div>
                    <i class="fa-solid fa-chevron-right" style="color:var(--muted);font-size:12px;"></i>
                </a>
                <?php endforeach; ?>
            </div>
            <?php if (!$eventos): ?><div class="pn-empty"><i class="fa-solid fa-calendar-xmark"></i>Ainda não há treinos nem jogos.</div><?php endif; ?>
        </section>
    </div>
</div>

<script>
    (function () {
        var btn = document.getElementById('todosPresentes');
        if (!btn) return;
        btn.addEventListener('click', function () {
            document.querySelectorAll('.pn-check-row input[type="radio"][value="1"]').forEach(function (r) { r.checked = true; });
        });
    })();
</script>

<?php require panel_layout('bottom'); ?>
