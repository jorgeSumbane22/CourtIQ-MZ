<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Admin: Dashboard
 * Resumo da equipa do administrador. Todos os números vêm da base de dados
 * e dizem respeito apenas a $teamId.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);

// escalão em vista: todos, ou um de cada vez (as finanças e as mensagens são sempre do clube inteiro)
$escalaoAtual = escalao_atual($team);
$gameRepo   = new GameRepository();
$terminados = escalao_only($gameRepo->finished($teamId, 200), $escalaoAtual);
$proximos   = array_slice(escalao_only($gameRepo->upcoming($teamId, 40), $escalaoAtual), 0, 5);
$registo    = pub_record($terminados);
$jogadores  = escalao_only(panel_players($pdo, $teamId), $escalaoAtual);

$disponiveis = count(array_filter($jogadores, fn($j) => $j['status'] === 'active'));

/** Um valor único da base de dados, sempre filtrado pela equipa. */
$valor = function (string $sql) use ($pdo, $teamId) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['team_id' => $teamId]);
    return $stmt->fetchColumn();
};

$receitas = (float) $valor("SELECT COALESCE(SUM(amount), 0) FROM finances WHERE team_id = :team_id AND type = 'income'");
$despesas = (float) $valor("SELECT COALESCE(SUM(amount), 0) FROM finances WHERE team_id = :team_id AND type = 'expense'");
$presenca = (float) $valor(
    'SELECT COALESCE(AVG(a.present) * 100, 0) FROM attendance a JOIN players p ON p.id = a.player_id WHERE p.team_id = :team_id' . escalao_sql('p.escalao', $escalaoAtual)
);
$mensagensPorLer = (int) $valor("SELECT COUNT(*) FROM contact_messages WHERE team_id = :team_id AND status = 'unread'");

$stmt = $pdo->prepare(
    "SELECT * FROM trainings WHERE team_id = :team_id AND training_date >= CURDATE() AND status = 'scheduled'" . escalao_sql('escalao', $escalaoAtual) . "
     ORDER BY training_date, start_time LIMIT 4"
);
$stmt->execute(['team_id' => $teamId]);
$treinos = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM contact_messages WHERE team_id = :team_id ORDER BY created_at DESC LIMIT 4');
$stmt->execute(['team_id' => $teamId]);
$mensagens = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM finances WHERE team_id = :team_id ORDER BY date DESC, id DESC LIMIT 5');
$stmt->execute(['team_id' => $teamId]);
$movimentos = $stmt->fetchAll();

$marcadores = array_filter($jogadores, fn($j) => (int) $j['games_played'] > 0);
usort($marcadores, fn($a, $b) => (float) $b['avg_pts'] <=> (float) $a['avg_pts']);
$marcadores = array_slice($marcadores, 0, 5);
$maxPts     = max(0.1, (float) ($marcadores[0]['avg_pts'] ?? 0));

$proximo = $proximos[0] ?? null;
$saldo   = $receitas - $despesas;

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../includes/layout/admin_top.php';
?>

<div class="lp pn">
    <?php panel_head('Olá, ' . ($user['name'] ?? 'Admin'), 'Resumo de ' . $team['name'] . ' em ' . date('d/m/Y') . '.',
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../index.php?equipa=' . $teamId . '"><i class="fa-solid fa-globe"></i> Ver site público</a>'
        . '<a class="lp-btn lp-btn--primary lp-btn--sm" href="jogos.php"><i class="fa-solid fa-plus"></i> Agendar jogo</a>'); ?>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <section class="lp-kpis" aria-label="Indicadores">
        <a class="lp-card lp-kpi" href="jogadores.php"><b><?= count($jogadores) ?></b><span>Jogadores (<?= $disponiveis ?> disponíveis)</span></a>
        <a class="lp-card lp-kpi lp-kpi--accent" href="jogos.php"><b><?= $registo['vitorias'] ?>–<?= $registo['derrotas'] ?></b><span>Vitórias–Derrotas</span></a>
        <a class="lp-card lp-kpi" href="estatisticas.php"><b><?= $registo['aproveitamento'] ?>%</b><span>Aproveitamento</span></a>
        <a class="lp-card lp-kpi" href="presencas.php"><b><?= number_format($presenca, 0) ?>%</b><span>Presença nos treinos</span></a>
        <a class="lp-card lp-kpi lp-kpi--<?= $saldo >= 0 ? 'up' : 'down' ?>" href="financas.php"><b style="font-size:26px;"><?= pn_money($saldo) ?></b><span>Saldo financeiro</span></a>
    </section>

    <div class="pn-cols">
        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-calendar-days"></i>Próximos jogos</h2><a href="jogos.php">Gerir jogos</a></div>
                <?php if ($proximos): ?>
                <div class="pn-list">
                    <?php foreach ($proximos as $jogo): $ts = strtotime($jogo['game_date']); ?>
                    <div>
                        <div class="lp-date" style="width:46px;"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                        <div class="grow">
                            <b>vs <?= pub_e($jogo['opponent']) ?></b>
                            <small><?= pub_e(pub_time($jogo['game_time'])) ?> &bull; <?= pub_e($jogo['location'] ?: 'Local a definir') ?></small>
                        </div>
                        <span class="lp-badge"><?= ($jogo['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="pn-empty"><i class="fa-solid fa-calendar-xmark"></i>Não há jogos agendados.</div>
                <?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-check-double"></i>Últimos resultados</h2><a href="jogos.php">Ver todos</a></div>
                <?php if ($terminados): ?>
                <div class="pn-list">
                    <?php foreach (array_slice($terminados, 0, 5) as $jogo):
                        $ts = strtotime($jogo['game_date']);
                        $ganhou = (int) $jogo['our_score'] > (int) $jogo['opponent_score'];
                    ?>
                    <div>
                        <div class="lp-date" style="width:46px;"><b><?= date('d', $ts) ?></b><span><?= pub_month((int) date('n', $ts)) ?></span></div>
                        <div class="grow"><b>vs <?= pub_e($jogo['opponent']) ?></b><small><?= pub_e($jogo['location'] ?: '—') ?></small></div>
                        <span class="lp-score"><?= (int) $jogo['our_score'] ?> &ndash; <?= (int) $jogo['opponent_score'] ?></span>
                        <span class="lp-pill lp-pill--<?= $ganhou ? 'win' : 'loss' ?>" title="<?= $ganhou ? 'Vitória' : 'Derrota' ?>"><?= $ganhou ? 'V' : 'D' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="pn-empty"><i class="fa-solid fa-basketball"></i>Ainda não há jogos terminados.</div>
                <?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-sack-dollar"></i>Finanças</h2><a href="financas.php">Ver movimentos</a></div>
                <div class="lp-pad">
                    <div class="lp-bars">
                        <?php $maxFin = max(1, $receitas, $despesas); ?>
                        <div class="lp-bar-row"><span>Receitas</span><span class="lp-bar lp-bar--green"><i style="width:<?= round($receitas / $maxFin * 100) ?>%"></i></span><b style="font-size:14px;"></b></div>
                        <div class="lp-bar-row"><span>Despesas</span><span class="lp-bar"><i style="width:<?= round($despesas / $maxFin * 100) ?>%"></i></span><b style="font-size:14px;"></b></div>
                    </div>
                    <p style="margin-top:14px;font-size:14px;color:var(--muted);">
                        Receitas <b style="color:var(--green);"><?= pn_money($receitas) ?></b> &bull;
                        Despesas <b style="color:var(--red);"><?= pn_money($despesas) ?></b>
                    </p>
                </div>
                <div class="pn-list" style="border-top:1px solid var(--border);">
                    <?php foreach ($movimentos as $m): ?>
                    <div>
                        <div class="grow"><b><?= pub_e($m['description']) ?></b><small><?= pub_e(pub_date($m['date'])) ?> &bull; <?= pub_e(ucfirst((string) $m['category'])) ?></small></div>
                        <span class="pn-money" style="color:var(--<?= $m['type'] === 'income' ? 'green' : 'red' ?>);"><?= $m['type'] === 'income' ? '+' : '−' ?> <?= pn_money($m['amount']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="lp-stack" style="gap:24px;">
            <?php if ($proximo): ?>
            <section class="lp-card lp-pad" style="border-color:var(--accent);">
                <span class="lp-eyebrow">Próximo jogo</span>
                <div style="font-family:var(--display);font-size:28px;font-weight:800;color:var(--text);line-height:1.1;">vs <?= pub_e($proximo['opponent']) ?></div>
                <div class="lp-game-meta" style="margin:10px 0 16px;">
                    <span><i class="fa-solid fa-calendar-days"></i><?= pub_e(pub_date($proximo['game_date'])) ?> <?= pub_e(pub_time($proximo['game_time'])) ?></span>
                    <span><i class="fa-solid fa-location-dot"></i><?= pub_e($proximo['location'] ?: 'Local a definir') ?></span>
                </div>
                <a class="lp-btn lp-btn--ghost lp-btn--sm lp-btn--block" href="presencas.php?jogo_id=<?= (int) $proximo['id'] ?>"><i class="fa-solid fa-clipboard-check"></i> Marcar presenças</a>
            </section>
            <?php endif; ?>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-ranking-star"></i>Melhores marcadores</h2><a href="estatisticas.php">Estatísticas</a></div>
                <?php if ($marcadores): ?>
                <div class="lp-pad"><div class="lp-bars">
                    <?php foreach ($marcadores as $j): ?>
                    <div class="lp-bar-row">
                        <span title="<?= pub_e($j['name']) ?>"><?= pub_e($j['name']) ?></span>
                        <span class="lp-bar"><i style="width:<?= round((float) $j['avg_pts'] / $maxPts * 100) ?>%"></i></span>
                        <b><?= number_format((float) $j['avg_pts'], 1) ?></b>
                    </div>
                    <?php endforeach; ?>
                </div></div>
                <?php else: ?>
                <div class="pn-empty"><i class="fa-solid fa-chart-column"></i>Sem estatísticas registadas.</div>
                <?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-bullseye"></i>Próximos treinos</h2><a href="treinos.php">Gerir treinos</a></div>
                <?php if ($treinos): ?>
                <div class="pn-list">
                    <?php foreach ($treinos as $t): ?>
                    <div>
                        <div class="grow">
                            <b><?= pub_e($t['name']) ?></b>
                            <small><?= pub_e(pub_date($t['training_date'])) ?> &bull; <?= pub_e(pub_time($t['start_time'] ?: $t['training_time'])) ?> &bull; <?= pub_e($t['location'] ?: '—') ?></small>
                        </div>
                        <span class="lp-badge lp-badge--accent"><?= pub_e(pn_training_type($t['type'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="pn-empty"><i class="fa-solid fa-bullseye"></i>Não há treinos agendados.</div>
                <?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title">
                    <h2><i class="fa-solid fa-envelope"></i>Mensagens do site <?php if ($mensagensPorLer): ?><span class="lp-badge lp-badge--accent"><?= $mensagensPorLer ?> por ler</span><?php endif; ?></h2>
                    <a href="notificacoes.php#mensagens">Ver todas</a>
                </div>
                <?php if ($mensagens): ?>
                <div class="pn-list">
                    <?php foreach ($mensagens as $m): ?>
                    <div>
                        <div class="grow"><b><?= pub_e($m['subject']) ?></b><small><?= pub_e($m['name']) ?> &bull; <?= pub_e(pub_date($m['created_at'])) ?></small></div>
                        <?php if ($m['status'] === 'unread'): ?><span class="lp-badge lp-badge--info">Nova</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="pn-empty"><i class="fa-solid fa-inbox"></i>Sem mensagens.</div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout/admin_bottom.php'; ?>
