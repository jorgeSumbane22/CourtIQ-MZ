<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Minha equipa
 * Os colegas de equipa, a equipa técnica e a posição na classificação.
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);   // pode ser null: a página funciona na mesma

// o plantel mostrado é o do escalão do jogador (sem ficha de jogador, o clube todo)
$meuEscalao = (string) ($player['escalao'] ?? '');
$plantel    = escalao_only(panel_players($pdo, $teamId), $meuEscalao);
$stats    = TeamHelper::getTeamStats($teamId);
$tabela   = (new TeamRepository())->standings($meuEscalao ?: 'seniores');
$posicao  = 0;
foreach ($tabela as $linha) {   // vitórias e derrotas do escalão do jogador, não do clube inteiro
    if ((int) $linha['id'] === $teamId && $meuEscalao !== '') {
        $stats['total_vitorias'] = (int) $linha['vitorias'];
        $stats['total_derrotas'] = (int) $linha['derrotas'];
        $stats['aproveitamento'] = (int) $linha['jogos'] ? (string) round((int) $linha['vitorias'] / (int) $linha['jogos'] * 100, 1) : '0';
    }
}
foreach ($tabela as $i => $linha) {
    if ((int) $linha['id'] === $teamId) $posicao = $i + 1;
}

$stmt = $pdo->prepare('SELECT c.*, COALESCE(u.name, c.name) AS display_name FROM coaches c LEFT JOIN users u ON u.id = c.user_id WHERE c.team_id = :team_id ORDER BY c.id');
$stmt->execute(['team_id' => $teamId]);
$tecnicos = $stmt->fetchAll();

$pageTitle = 'Minha Equipa';
$activeNav = 'minha-equipa';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head($team['name'], trim(($team['city'] ?? '') . ' · ' . ($team['stadium'] ?? ''), ' ·'),
        '<a class="lp-btn lp-btn--ghost lp-btn--sm" href="../index.php?equipa=' . $teamId . '"><i class="fa-solid fa-globe"></i> Site público</a>'); ?>

    <section class="lp-kpis" aria-label="Resumo da equipa">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= $posicao ? $posicao . 'º' : '—' ?></b><span>Lugar na classificação</span></div>
        <div class="lp-card lp-kpi"><b><?= (int) $stats['total_vitorias'] ?>–<?= (int) $stats['total_derrotas'] ?></b><span>Vitórias–Derrotas</span></div>
        <div class="lp-card lp-kpi"><b><?= pub_e($stats['aproveitamento']) ?>%</b><span>Aproveitamento</span></div>
        <div class="lp-card lp-kpi"><b><?= count($plantel) ?></b><span>Jogadores</span></div>
    </section>

    <div class="pn-cols">
        <section class="lp-card" data-filter>
            <div class="pn-section-title">
                <h2><i class="fa-solid fa-user-group"></i>Plantel<?php if ($meuEscalao !== ''): ?> <span class="lp-badge lp-badge--accent"><?= escalao_label($meuEscalao) ?></span><?php endif; ?></h2>
                <div class="lp-search" style="max-width:240px;"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-search placeholder="Procurar colega..." aria-label="Procurar colega"></div>
            </div>
            <div class="lp-table-wrap"><table class="lp-table pn-table">
                <thead><tr><th scope="col">Jogador</th><th scope="col">Estado</th><th scope="col" class="num">J</th><th scope="col" class="num">PTS</th><th scope="col" class="num">REB</th><th scope="col" class="num">AST</th></tr></thead>
                <tbody>
                    <?php foreach ($plantel as $j):
                        [$estado, $tipo] = pub_player_status($j['status']);
                        $eu = $player && (int) $j['id'] === (int) $player['id'];
                    ?>
                    <tr data-filter-item data-name="<?= pub_e(mb_strtolower((string) $j['name'])) ?>"<?= $eu ? ' class="is-us"' : '' ?>>
                        <td><div class="pn-user"><?= pn_avatar($j['photo_url'], $j['name']) ?><div>
                            <b>#<?= (int) $j['jersey_number'] ?> <?= pub_e($j['name']) ?><?= $j['is_captain'] ? ' <i class="fa-solid fa-star" style="color:var(--gold);font-size:11px;" title="Capitão"></i>' : '' ?><?= $eu ? ' <span class="lp-badge lp-badge--accent" style="margin-left:6px;">Eu</span>' : '' ?></b>
                            <small><?= pub_e(pub_position($j['position'])) ?></small></div></div></td>
                        <td><span class="lp-badge lp-badge--<?= $tipo ?>"><?= pub_e($estado) ?></span></td>
                        <td class="num"><?= (int) $j['games_played'] ?></td>
                        <td class="num lp-table-pts"><?= number_format((float) $j['avg_pts'], 1) ?></td>
                        <td class="num"><?= number_format((float) $j['avg_reb'], 1) ?></td>
                        <td class="num"><?= number_format((float) $j['avg_ast'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="pn-empty" data-filter-empty hidden><i class="fa-solid fa-user-group"></i>Nenhum colega corresponde à pesquisa.</p>
        </section>

        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-chalkboard-user"></i>Equipa técnica</h2></div>
                <div class="pn-list">
                    <?php foreach ($tecnicos as $t): ?>
                    <div>
                        <span class="lp-avatar lp-avatar--sm"><?= pub_initial($t['display_name']) ?></span>
                        <div class="grow"><b><?= pub_e($t['display_name']) ?></b><small><?= pub_e($t['role_title'] ?: 'Treinador') ?></small></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$tecnicos): ?><div class="pn-empty"><i class="fa-solid fa-chalkboard-user"></i>Sem equipa técnica registada.</div><?php endif; ?>
            </section>

            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-ranking-star"></i>Classificação</h2><a href="../visitante/classificacao.php?equipa=<?= $teamId ?>">Tabela completa</a></div>
                <div class="pn-list">
                    <?php foreach ($tabela as $i => $linha): $nossa = (int) $linha['id'] === $teamId; ?>
                    <div style="<?= $nossa ? 'background:var(--glow);' : '' ?>">
                        <span class="lp-pos<?= $i < 3 ? ' lp-pos--' . ($i + 1) : '' ?>"><?= $i + 1 ?></span>
                        <div class="grow"><b style="<?= $nossa ? 'color:var(--accent-text);' : '' ?>"><?= pub_e($linha['name']) ?></b><small><?= (int) $linha['vitorias'] ?>V · <?= (int) $linha['derrotas'] ?>D</small></div>
                        <b style="font-family:var(--display);font-size:20px;"><?= (int) $linha['pontos'] ?></b>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
