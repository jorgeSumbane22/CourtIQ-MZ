<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Meus treinos
 * Os treinos da equipa e a presença do jogador em cada um.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);

$pageTitle = 'Meus Treinos';
$activeNav = 'meus-treinos';

if (!$player) {
    require panel_layout('top');
    panel_no_player();
    require panel_layout('bottom');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT t.*, a.present, a.status AS presenca, a.justification
     FROM trainings t
     LEFT JOIN attendance a ON a.event_type = 'training' AND a.event_id = t.id AND a.player_id = :player_id
     WHERE t.team_id = :team_id" . escalao_sql('t.escalao', (string) ($player['escalao'] ?? 'seniores')) . "
     ORDER BY t.training_date DESC, t.start_time DESC"
);
$stmt->execute(['player_id' => (int) $player['id'], 'team_id' => $teamId]);
$treinos = $stmt->fetchAll();

$hoje     = date('Y-m-d');
$futuros  = array_reverse(array_values(array_filter($treinos, fn($t) => $t['status'] === 'scheduled' && $t['training_date'] >= $hoje)));
$passados = array_values(array_filter($treinos, fn($t) => !($t['status'] === 'scheduled' && $t['training_date'] >= $hoje)));
$marcados = array_filter($passados, fn($t) => $t['present'] !== null);
$presente = count(array_filter($marcados, fn($t) => (int) $t['present'] === 1));

$porTipo = array_count_values(array_column($treinos, 'type'));
arsort($porTipo);

require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Meus treinos', count($futuros) . ' agendados · ' . count($passados) . ' realizados.'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= count($futuros) ?></b><span>Próximos treinos</span></div>
        <div class="lp-card lp-kpi lp-kpi--up"><b><?= $presente ?></b><span>Presenças</span></div>
        <div class="lp-card lp-kpi lp-kpi--down"><b><?= count($marcados) - $presente ?></b><span>Faltas</span></div>
        <div class="lp-card lp-kpi"><b><?= $marcados ? round($presente / count($marcados) * 100) . '%' : '—' ?></b><span>Assiduidade nos treinos</span></div>
    </section>

    <div class="pn-cols">
        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-calendar-days"></i>Próximos treinos</h2></div>
                <?php if ($futuros): ?>
                <div class="pn-list">
                    <?php foreach ($futuros as $t): $ts = strtotime($t['training_date']); ?>
                    <div style="align-items:flex-start;">
                        <div class="lp-date" style="width:46px;"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                        <div class="grow">
                            <b><?= pub_e($t['name']) ?></b>
                            <small><?= pub_e(pub_time($t['start_time'] ?: $t['training_time'])) ?><?= $t['end_time'] ? '–' . pub_e(pub_time($t['end_time'])) : '' ?> · <?= pub_e($t['location'] ?: '—') ?></small>
                            <?php if (!empty($t['objective'])): ?><small style="white-space:normal;font-size:13px;color:var(--text-secondary);margin-top:4px;"><?= pub_e($t['objective']) ?></small><?php endif; ?>
                        </div>
                        <span class="lp-badge lp-badge--accent"><?= pub_e(pn_training_type($t['type'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-dumbbell"></i>Não há treinos agendados.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-clock-rotate-left"></i>Treinos anteriores</h2><small>A minha presença</small></div>
                <?php if ($passados): ?>
                <div class="pn-list">
                    <?php foreach ($passados as $t): ?>
                    <div>
                        <div class="grow"><b><?= pub_e($t['name']) ?></b><small><?= pub_e(pub_date($t['training_date'])) ?> · <?= pub_e(pn_training_type($t['type'])) ?><?= $t['justification'] ? ' · ' . pub_e($t['justification']) : '' ?></small></div>
                        <?php if ($t['present'] === null): ?>
                            <span class="lp-badge"><?= $t['status'] === 'cancelled' ? 'Cancelado' : 'Por marcar' ?></span>
                        <?php elseif ((int) $t['present'] === 1): ?>
                            <span class="lp-badge lp-badge--win"><i class="fa-solid fa-check"></i> Presente</span>
                        <?php else: ?>
                            <span class="lp-badge lp-badge--<?= $t['presenca'] === 'excused' ? 'gold' : 'loss' ?>"><?= $t['presenca'] === 'excused' ? 'Falta justificada' : ($t['presenca'] === 'injured' ? 'Lesionado' : 'Falta') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?><div class="pn-empty"><i class="fa-solid fa-dumbbell"></i>Ainda não houve treinos.</div><?php endif; ?>
            </section>
        </div>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-chart-pie"></i>Tipos de treino</h2></div>
            <?php if ($porTipo): $max = max($porTipo); ?>
            <div class="lp-pad"><div class="lp-bars">
                <?php foreach ($porTipo as $tipo => $n): ?>
                <div class="lp-bar-row"><span><?= pub_e(pn_training_type($tipo)) ?></span><span class="lp-bar"><i style="width:<?= round($n / $max * 100) ?>%"></i></span><b><?= $n ?></b></div>
                <?php endforeach; ?>
            </div></div>
            <?php else: ?><div class="pn-empty"><i class="fa-solid fa-chart-pie"></i>Sem treinos.</div><?php endif; ?>
        </section>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
