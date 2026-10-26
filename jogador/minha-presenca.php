<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Minha presença
 * Todas as presenças e faltas do jogador, em treinos e jogos.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);

$pageTitle = 'Minha Presença';
$activeNav = 'minha-presenca';

if (!$player) {
    require panel_layout('top');
    panel_no_player();
    require panel_layout('bottom');
    exit;
}

// Presenças do jogador com o evento a que dizem respeito (treino ou jogo da própria equipa)
$stmt = $pdo->prepare(
    "SELECT a.*, COALESCE(t.name, CONCAT('Jogo vs ', g.opponent)) AS titulo, COALESCE(t.training_date, g.game_date) AS data
     FROM attendance a
     LEFT JOIN trainings t ON a.event_type = 'training' AND t.id = a.event_id AND t.team_id = :t1
     LEFT JOIN games g     ON a.event_type = 'game'     AND g.id = a.event_id AND g.team_id = :t2
     WHERE a.player_id = :player_id AND (t.id IS NOT NULL OR g.id IS NOT NULL)
     ORDER BY data DESC, a.id DESC"
);
$stmt->execute(['t1' => $teamId, 't2' => $teamId, 'player_id' => (int) $player['id']]);
$registos = $stmt->fetchAll();

$total     = count($registos);
$presentes = count(array_filter($registos, fn($r) => (int) $r['present'] === 1));
$justif    = count(array_filter($registos, fn($r) => (int) $r['present'] === 0 && in_array($r['status'], ['excused', 'injured'], true)));
$faltas    = $total - $presentes - $justif;
$taxa      = $total ? round($presentes / $total * 100) : null;

// Assiduidade por mês (para ver a evolução)
$porMes = [];
foreach ($registos as $r) {
    $mes = substr((string) $r['data'], 0, 7);
    $porMes[$mes]['total']     = ($porMes[$mes]['total'] ?? 0) + 1;
    $porMes[$mes]['presentes'] = ($porMes[$mes]['presentes'] ?? 0) + (int) $r['present'];
}
ksort($porMes);

$rotulos = ['present' => ['Presente', 'win'], 'absent' => ['Falta', 'loss'], 'excused' => ['Falta justificada', 'gold'], 'injured' => ['Lesionado', 'gold'], 'suspended' => ['Suspenso', 'loss']];

require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Minha presença', $total . ' registos em treinos e jogos.'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $taxa !== null ? $taxa . '%' : '—' ?></b><span>Assiduidade</span></div>
        <div class="lp-card lp-kpi lp-kpi--up"><b><?= $presentes ?></b><span>Presenças</span></div>
        <div class="lp-card lp-kpi"><b><?= $justif ?></b><span>Faltas justificadas</span></div>
        <div class="lp-card lp-kpi lp-kpi--down"><b><?= $faltas ?></b><span>Faltas por justificar</span></div>
    </section>

    <div class="pn-cols">
        <section class="lp-card" data-filter>
            <div class="pn-section-title">
                <h2><i class="fa-solid fa-clipboard-check"></i>Registo</h2>
                <div class="lp-chips" role="group" aria-label="Filtrar registos">
                    <button type="button" class="lp-chip is-active" data-filter-value="" aria-pressed="true">Todos</button>
                    <button type="button" class="lp-chip" data-filter-value="training" aria-pressed="false">Treinos</button>
                    <button type="button" class="lp-chip" data-filter-value="game" aria-pressed="false">Jogos</button>
                    <button type="button" class="lp-chip" data-filter-value="falta" aria-pressed="false">Faltas</button>
                </div>
            </div>
            <div class="pn-list">
                <?php foreach ($registos as $r):
                    $chave = (int) $r['present'] === 1 ? 'present' : ($r['status'] ?: 'absent');
                    if ($chave === 'present' && (int) $r['present'] === 0) $chave = 'absent';
                    [$rotulo, $tipo] = $rotulos[$chave] ?? $rotulos['absent'];
                ?>
                <div data-filter-item data-tags="<?= pub_e($r['event_type']) ?><?= (int) $r['present'] === 0 ? ' falta' : '' ?>">
                    <span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-<?= $r['event_type'] === 'game' ? 'trophy' : 'dumbbell' ?>"></i></span>
                    <div class="grow"><b><?= pub_e($r['titulo']) ?></b><small><?= pub_e(pub_date($r['data'])) ?><?= $r['justification'] ? ' · ' . pub_e($r['justification']) : '' ?></small></div>
                    <span class="lp-badge lp-badge--<?= $tipo ?>"><?= $rotulo ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="pn-empty" data-filter-empty <?= $registos ? 'hidden' : '' ?>><i class="fa-solid fa-clipboard"></i><?= $registos ? 'Nenhum registo neste filtro.' : 'Ainda não há presenças registadas.' ?></p>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-chart-simple"></i>Por mês</h2></div>
            <?php if ($porMes): ?>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($porMes as $mes => $m): $pct = round($m['presentes'] / $m['total'] * 100); [$ano, $n] = explode('-', $mes); ?>
                <div class="lp-bar-row">
                    <span style="text-transform:capitalize;"><?= pub_e(pub_month((int) $n, false)) ?> <?= pub_e($ano) ?></span>
                    <span class="lp-bar <?= $pct >= 75 ? 'lp-bar--green' : '' ?>"><i style="width:<?= $pct ?>%"></i></span>
                    <b><?= $pct ?>%</b>
                </div>
                <?php endforeach; ?>
            </div><p class="pn-help">Para justificar uma falta, fale com o treinador.</p></div>
            <?php else: ?><div class="pn-empty"><i class="fa-solid fa-chart-simple"></i>Sem dados.</div><?php endif; ?>
        </section>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
