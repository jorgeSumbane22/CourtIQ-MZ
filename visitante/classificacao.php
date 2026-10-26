<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Classificação
 * Tabela da liga calculada a partir dos jogos terminados de todas as equipas.
 * (Resultados são informação pública; nada aqui expõe dados internos de uma equipa.)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';

[$teamId, $team] = pub_team(false);   // página da liga: não precisa de clube escolhido
$basePath = '../';

$pdo = Database::getConnection();

// cada escalão tem a sua tabela; por omissão mostra-se a dos seniores
$escalao = isset(ESCALOES[$_GET['escalao'] ?? '']) ? (string) $_GET['escalao'] : 'seniores';

$tabela = $pdo->query(
    "SELECT t.id, t.name, t.city,
            COUNT(g.id) AS jogos,
            COALESCE(SUM(g.our_score > g.opponent_score), 0) AS vitorias,
            COALESCE(SUM(g.our_score < g.opponent_score), 0) AS derrotas,
            COALESCE(SUM(g.our_score), 0)      AS marcados,
            COALESCE(SUM(g.opponent_score), 0) AS sofridos,
            COALESCE(SUM(IF(g.our_score > g.opponent_score, 2, 1)), 0) AS pontos
     FROM teams t
     LEFT JOIN games g ON g.team_id = t.id AND g.status = 'finished' AND g.escalao = '$escalao'
     WHERE t.status = 'active' AND FIND_IN_SET('$escalao', t.escaloes)
     GROUP BY t.id
     ORDER BY pontos DESC, (COALESCE(SUM(g.our_score), 0) - COALESCE(SUM(g.opponent_score), 0)) DESC, t.name"
)->fetchAll();

// Forma: últimos 5 resultados de cada equipa (do mais recente para o mais antigo)
$forma = [];
$rows = $pdo->query(
    "SELECT team_id, our_score > opponent_score AS ganhou
     FROM games WHERE status = 'finished' AND escalao = '$escalao'
     ORDER BY game_date DESC, id DESC"
)->fetchAll();
foreach ($rows as $row) {
    $tid = (int) $row['team_id'];
    if (count($forma[$tid] ?? []) < 5) {
        $forma[$tid][] = (bool) $row['ganhou'];
    }
}

$posicaoAtual = 0;
foreach ($tabela as $i => $linha) {
    if ((int) $linha['id'] === $teamId) {
        $posicaoAtual = $i + 1;
    }
}
$lider = $tabela[0] ?? null;

$pageTitle = 'Classificação';
$pageIcon  = 'fa-solid fa-ranking-star';
$activeNav = 'classificacao';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head('Liga Nacional · ' . date('Y'), 'Classificação',
        $posicaoAtual ? $team['name'] . ' ocupa o ' . $posicaoAtual . 'º lugar da tabela.' : 'Tabela atualizada após cada jogo.', [
        $teamId ? [$posicaoAtual ? $posicaoAtual . 'º' : '—', $team['name']] : [count($tabela), 'Clubes'],
        [$lider ? $lider['pontos'] : '—', 'Pontos do líder'],
    ]); ?>

    <nav class="lp-chips" aria-label="Escalão">
        <?php foreach (ESCALOES as $k => $v): ?><a class="lp-chip<?= $escalao === $k ? ' is-active' : '' ?>" href="?escalao=<?= $k ?>"<?= $escalao === $k ? ' aria-current="true"' : '' ?>><?= $v ?></a><?php endforeach; ?>
    </nav>

    <section class="lp-reveal">
        <div class="lp-card lp-table-wrap">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Equipa</th>
                        <th scope="col" title="Jogos">J</th>
                        <th scope="col" title="Vitórias">V</th>
                        <th scope="col" title="Derrotas">D</th>
                        <th scope="col" title="Pontos marcados">PM</th>
                        <th scope="col" title="Pontos sofridos">PS</th>
                        <th scope="col" title="Saldo de pontos">Saldo</th>
                        <th scope="col" title="Percentagem de vitórias">%</th>
                        <th scope="col">Forma</th>
                        <th scope="col" title="Pontos">Pts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tabela as $i => $linha):
                        $posicao = $i + 1;
                        $saldo   = (int) $linha['marcados'] - (int) $linha['sofridos'];
                        $pct     = (int) $linha['jogos'] ? round((int) $linha['vitorias'] / (int) $linha['jogos'] * 100) : 0;
                    ?>
                    <tr<?= (int) $linha['id'] === $teamId ? ' class="is-us"' : '' ?>>
                        <td><span class="lp-pos<?= $posicao <= 3 ? ' lp-pos--' . $posicao : '' ?>"><?= $posicao ?></span></td>
                        <td class="lp-table-team"><a href="?equipa=<?= (int) $linha['id'] ?>" title="Ver o site desta equipa"><?= pub_e($linha['name']) ?></a></td>
                        <td><?= (int) $linha['jogos'] ?></td>
                        <td><?= (int) $linha['vitorias'] ?></td>
                        <td><?= (int) $linha['derrotas'] ?></td>
                        <td><?= (int) $linha['marcados'] ?></td>
                        <td><?= (int) $linha['sofridos'] ?></td>
                        <td class="<?= $saldo >= 0 ? 'lp-up' : 'lp-down' ?>"><?= ($saldo > 0 ? '+' : '') . $saldo ?></td>
                        <td><?= $pct ?>%</td>
                        <td>
                            <span class="lp-form-pills" style="justify-content:center;">
                                <?php foreach ($forma[(int) $linha['id']] ?? [] as $ganhou): ?>
                                <span class="lp-pill lp-pill--<?= $ganhou ? 'win' : 'loss' ?>" title="<?= $ganhou ? 'Vitória' : 'Derrota' ?>"><?= $ganhou ? 'V' : 'D' ?></span>
                                <?php endforeach; ?>
                            </span>
                        </td>
                        <td class="lp-table-pts"><?= (int) $linha['pontos'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="margin-top:14px;font-size:13px;color:var(--muted);">
            <i class="fa-solid fa-circle-info" style="color:var(--accent);margin-right:6px;"></i>
            Vitória = 2 pontos, derrota = 1 ponto. Em caso de empate conta o saldo de pontos. A coluna Forma mostra os últimos jogos, do mais recente para o mais antigo.
        </p>
    </section>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
