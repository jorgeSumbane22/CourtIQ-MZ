<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Métricas
 * Tamanho da base de dados, volume de dados por equipa e ambiente do servidor.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

$tabelas = $pdo->query(
    'SELECT table_name AS nome, table_rows AS linhas, data_length + index_length AS bytes
     FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC'
)->fetchAll();
// table_rows é uma estimativa no InnoDB: conta-se a sério (as tabelas são pequenas)
foreach ($tabelas as &$t) {
    $t['linhas'] = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $t['nome']) . '`')->fetchColumn();
}
unset($t);
$bytesTotal  = array_sum(array_map(fn($t) => (int) $t['bytes'], $tabelas));
$linhasTotal = array_sum(array_column($tabelas, 'linhas'));
$maxBytes    = max(1, ...array_map(fn($t) => (int) $t['bytes'], $tabelas ?: [['bytes' => 1]]));

$porEquipa = $pdo->query(
    "SELECT t.name,
            (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id) AS contas,
            (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS jogadores,
            (SELECT COUNT(*) FROM games g WHERE g.team_id = t.id) AS jogos,
            (SELECT COUNT(*) FROM trainings tr WHERE tr.team_id = t.id) AS treinos,
            (SELECT COUNT(*) FROM finances f WHERE f.team_id = t.id) AS financas,
            (SELECT COUNT(*) FROM news n WHERE n.team_id = t.id) AS noticias,
            (SELECT COUNT(*) FROM audit_logs a WHERE a.team_id = t.id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS atividade
     FROM teams t ORDER BY t.name"
)->fetchAll();

$tamanho = function (int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
    return number_format($bytes / 1024, 0, ',', '.') . ' KB';
};

$pastaImagens = 0;
$nImagens = 0;
$dirImagens = dirname(__DIR__) . '/imagens';
if (is_dir($dirImagens)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirImagens, FilesystemIterator::SKIP_DOTS)) as $f) {
        $pastaImagens += $f->getSize();
        $nImagens++;
    }
}
$livre = @disk_free_space(dirname(__DIR__));

$ambiente = [
    'Versão do PHP'            => PHP_VERSION,
    'Servidor de base de dados'=> (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
    'Base de dados'            => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    'Servidor web'             => (string) ($_SERVER['SERVER_SOFTWARE'] ?? '—'),
    'Fuso horário do PHP'      => date_default_timezone_get(),
    'Hora do servidor'         => date('d/m/Y H:i:s'),
    'Limite de memória do PHP' => (string) ini_get('memory_limit'),
    'Envio máximo de ficheiros'=> (string) ini_get('upload_max_filesize'),
    'Espaço livre em disco'    => $livre !== false ? number_format($livre / 1073741824, 1, ',', '.') . ' GB' : '—',
];

$pageTitle = 'Métricas';
$activeNav = 'metricas-sistema';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Métricas do sistema', 'Volume de dados e ambiente do servidor.'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b style="font-size:30px;"><?= $tamanho($bytesTotal) ?></b><span>Tamanho da base de dados</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format($linhasTotal, 0, ',', '.') ?></b><span>Registos</span></div>
        <div class="lp-card lp-kpi"><b><?= count($tabelas) ?></b><span>Tabelas</span></div>
        <div class="lp-card lp-kpi"><b style="font-size:30px;"><?= $tamanho($pastaImagens) ?></b><span><?= $nImagens ?> ficheiros em /imagens</span></div>
    </section>

    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-building-shield"></i>Dados por equipa</h2><small>Atividade = ações nos últimos 7 dias</small></div>
        <div class="lp-table-wrap"><table class="lp-table pn-table">
            <thead><tr><th scope="col">Equipa</th><th scope="col" class="num">Contas</th><th scope="col" class="num">Jogadores</th><th scope="col" class="num">Jogos</th>
                <th scope="col" class="num">Treinos</th><th scope="col" class="num">Finanças</th><th scope="col" class="num">Notícias</th><th scope="col" class="num">Atividade</th></tr></thead>
            <tbody>
                <?php foreach ($porEquipa as $e): ?>
                <tr>
                    <td class="lp-table-team"><?= pub_e($e['name']) ?></td>
                    <?php foreach (['contas', 'jogadores', 'jogos', 'treinos', 'financas', 'noticias'] as $c): ?><td class="num"><?= (int) $e[$c] ?></td><?php endforeach; ?>
                    <td class="num lp-table-pts"><?= (int) $e['atividade'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>

    <div class="pn-cols">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-database"></i>Tabelas</h2><small>Da maior para a mais pequena</small></div>
            <div class="lp-table-wrap"><table class="lp-table pn-table">
                <thead><tr><th scope="col">Tabela</th><th scope="col" class="num">Registos</th><th scope="col" style="width:40%;">Tamanho</th><th scope="col" class="num"></th></tr></thead>
                <tbody>
                    <?php foreach ($tabelas as $t): ?>
                    <tr>
                        <td><code style="color:var(--text);"><?= pub_e($t['nome']) ?></code></td>
                        <td class="num"><?= number_format((int) $t['linhas'], 0, ',', '.') ?></td>
                        <td><span class="lp-bar" style="display:block;"><i style="width:<?= max(2, round((int) $t['bytes'] / $maxBytes * 100)) ?>%"></i></span></td>
                        <td class="num" style="white-space:nowrap;color:var(--muted);"><?= $tamanho((int) $t['bytes']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-server"></i>Ambiente</h2></div>
            <div class="pn-list">
                <?php foreach ($ambiente as $rotulo => $valor): ?>
                <div><div class="grow"><small><?= pub_e($rotulo) ?></small><b style="white-space:normal;"><?= pub_e($valor) ?></b></div></div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
