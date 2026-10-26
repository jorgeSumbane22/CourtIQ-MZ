<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Backup
 * Cópia de segurança completa da base de dados (SQL), com todas as equipas.
 * As cópias guardadas no servidor ficam em /backups, que não é acessível pelo browser:
 * só se descarregam por esta página.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

$pasta = dirname(__DIR__) . '/backups';

/** Gera o SQL completo (estrutura + dados) e escreve-o no recurso indicado. */
$gerarSql = function ($destino) use ($pdo): int {
    $registos = 0;
    $base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    fwrite($destino, "-- CourtIQMZ — backup completo\n-- Base de dados: {$base}\n-- Criado em: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($destino, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabela) {
        $nome   = '`' . str_replace('`', '', $tabela) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $nome)->fetch(PDO::FETCH_NUM);
        fwrite($destino, "DROP TABLE IF EXISTS {$nome};\n{$create[1]};\n\n");

        $stmt = $pdo->query('SELECT * FROM ' . $nome);
        while ($linha = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $valores = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $linha);
            fwrite($destino, 'INSERT INTO ' . $nome . ' (`' . implode('`, `', array_keys($linha)) . '`) VALUES (' . implode(', ', $valores) . ");\n");
            $registos++;
        }
        fwrite($destino, "\n");
    }
    fwrite($destino, "SET FOREIGN_KEY_CHECKS = 1;\n");
    return $registos;
};

/** Ficheiros .sql existentes em /backups (nome => caminho). Só estes podem ser descarregados ou apagados. */
$listar = function () use ($pasta): array {
    $ficheiros = [];
    foreach (glob($pasta . '/*.sql') ?: [] as $caminho) {
        $ficheiros[basename($caminho)] = $caminho;
    }
    uasort($ficheiros, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $ficheiros;
};

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    try {
        if ($acao === 'download') {
            panel_log_global($pdo, 'backup_global_descarregado');
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="courtiq_backup_' . date('Y-m-d_His') . '.sql"');
            $out = fopen('php://output', 'w');
            $gerarSql($out);
            exit;

        } elseif ($acao === 'save') {
            if (!is_dir($pasta) && !mkdir($pasta, 0775, true)) throw new RuntimeException('Não foi possível criar a pasta de backups.');
            $nome = 'courtiq_backup_' . date('Y-m-d_His') . '.sql';
            $f = fopen($pasta . '/' . $nome, 'w');
            if (!$f) throw new RuntimeException('Não foi possível escrever na pasta de backups.');
            $n = $gerarSql($f);
            fclose($f);
            panel_log_global($pdo, 'backup_global_guardado', $nome . ' (' . $n . ' registos)');
            flash_set('success', 'Cópia guardada no servidor: ' . $nome);

        } elseif ($acao === 'file_download' || $acao === 'file_delete') {
            $nome = (string) ($_POST['file'] ?? '');
            $ficheiros = $listar();
            if (!isset($ficheiros[$nome])) throw new RuntimeException('Ficheiro não encontrado.');   // só nomes que existem na pasta
            if ($acao === 'file_download') {
                header('Content-Type: application/sql; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $nome . '"');
                header('Content-Length: ' . filesize($ficheiros[$nome]));
                readfile($ficheiros[$nome]);
                exit;
            }
            unlink($ficheiros[$nome]);
            panel_log_global($pdo, 'backup_apagado', $nome);
            flash_set('success', 'Cópia ' . $nome . ' apagada.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir o backup.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/backup-global: ' . $e->getMessage());
    }
    panel_redirect('backup-global.php');
}

$ficheiros = $listar();
$tabelas   = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$registos  = 0;
foreach ($tabelas as $t) {
    $registos += (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`')->fetchColumn();
}
$tamanho = fn(int $b) => $b >= 1048576 ? number_format($b / 1048576, 2, ',', '.') . ' MB' : number_format($b / 1024, 0, ',', '.') . ' KB';
$ultimo  = $ficheiros ? filemtime(reset($ficheiros)) : null;

$pageTitle = 'Backup';
$activeNav = 'backup-global';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Backup', 'Cópia de segurança completa: todas as equipas, ' . count($tabelas) . ' tabelas, ' . number_format($registos, 0, ',', '.') . ' registos.'); ?>

    <section class="lp-kpis" aria-label="Resumo">
        <div class="lp-card lp-kpi lp-kpi--accent"><b><?= count($ficheiros) ?></b><span>Cópias guardadas no servidor</span></div>
        <div class="lp-card lp-kpi lp-kpi--<?= $ultimo && $ultimo > strtotime('-7 days') ? 'up' : 'down' ?>"><b style="font-size:26px;"><?= $ultimo ? date('d/m/Y', $ultimo) : 'Nunca' ?></b><span>Última cópia guardada</span></div>
        <div class="lp-card lp-kpi"><b><?= number_format($registos, 0, ',', '.') ?></b><span>Registos na base de dados</span></div>
    </section>

    <div class="pn-cols">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-box-archive"></i>Cópias no servidor</h2><small>Pasta /backups</small></div>
            <div class="pn-list">
                <?php foreach ($ficheiros as $nome => $caminho): ?>
                <div>
                    <span class="lp-info-icon" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-file-code"></i></span>
                    <div class="grow"><b><?= pub_e($nome) ?></b><small><?= date('d/m/Y H:i', filemtime($caminho)) ?> · <?= $tamanho((int) filesize($caminho)) ?></small></div>
                    <span class="pn-row-actions">
                        <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="file_download"><input type="hidden" name="file" value="<?= pub_e($nome) ?>">
                            <button type="submit" class="pn-icon-btn" title="Descarregar" aria-label="Descarregar <?= pub_e($nome) ?>"><i class="fa-solid fa-download"></i></button></form>
                        <form method="post" data-confirm="Apagar a cópia <?= pub_e($nome) ?>?"><?= panel_csrf() ?><input type="hidden" name="action" value="file_delete"><input type="hidden" name="file" value="<?= pub_e($nome) ?>">
                            <button type="submit" class="pn-icon-btn pn-icon-btn--danger" title="Apagar" aria-label="Apagar <?= pub_e($nome) ?>"><i class="fa-solid fa-trash"></i></button></form>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$ficheiros): ?><div class="pn-empty"><i class="fa-solid fa-box-archive"></i>Ainda não há cópias guardadas no servidor.</div><?php endif; ?>
        </section>

        <div class="lp-stack" style="gap:24px;">
            <section class="lp-card lp-pad">
                <h2 class="lp-sub">Nova cópia</h2>
                <p style="font-size:14px;color:var(--text-secondary);line-height:1.6;margin-bottom:16px;">
                    Gera um ficheiro SQL com a estrutura e os dados de <b>todas as equipas</b>. Inclui os hashes das palavras-passe, por isso trate o ficheiro como confidencial.
                </p>
                <div class="lp-stack">
                    <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="download">
                        <button type="submit" class="lp-btn lp-btn--primary lp-btn--block"><i class="fa-solid fa-download"></i> Descarregar agora (.sql)</button></form>
                    <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="save">
                        <button type="submit" class="lp-btn lp-btn--ghost lp-btn--block"><i class="fa-solid fa-floppy-disk"></i> Guardar cópia no servidor</button></form>
                </div>
            </section>

            <section class="lp-card lp-pad">
                <h2 class="lp-sub">Repor uma cópia</h2>
                <p style="font-size:14px;color:var(--text-secondary);line-height:1.6;">
                    A reposição substitui <b>toda</b> a base de dados, por isso não se faz por aqui.
                    Importe o ficheiro .sql no phpMyAdmin (separador Importar) com o sistema em
                    <a href="manutencao.php" style="color:var(--accent-text);font-weight:600;">modo de manutenção</a>.
                </p>
            </section>
        </div>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
