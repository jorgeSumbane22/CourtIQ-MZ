<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Manutenção
 *   - modo de manutenção: fecha o site público e os painéis a toda a gente menos ao superadmin
 *   - limpezas: notificações lidas antigas, histórico antigo, sessões de chat, otimizar tabelas
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

$contar = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

/** limpeza => [título, descrição, SQL de contagem, SQL de remoção] */
$limpezas = [
    'notificacoes' => [
        'Notificações lidas com mais de 30 dias', 'Remove só notificações já lidas.',
        'SELECT COUNT(*) FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)',
        'DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)',
    ],
    'acessos' => [
        'Registos de início de sessão com mais de 90 dias', 'O histórico de alterações (auditoria) não é tocado.',
        "SELECT COUNT(*) FROM audit_logs WHERE action = 'login' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
        "DELETE FROM audit_logs WHERE action = 'login' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
    ],
    'auditoria' => [
        'Auditoria com mais de 1 ano', 'Remove ações antigas de todas as equipas.',
        'SELECT COUNT(*) FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)',
        'DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)',
    ],
    'contactos' => [
        'Mensagens de contacto respondidas com mais de 90 dias', 'Só mensagens já marcadas como respondidas.',
        "SELECT COUNT(*) FROM contact_messages WHERE status = 'replied' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
        "DELETE FROM contact_messages WHERE status = 'replied' AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
    ],
];

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');
    try {
        if ($acao === 'maintenance') {
            $ligar = ($_POST['state'] ?? '') === 'on' ? '1' : '0';
            $pdo->prepare(
                "INSERT INTO system_config (config_key, config_value, description) VALUES ('maintenance_mode', :v, 'Modo de manutenção (0=off, 1=on)')
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
            )->execute(['v' => $ligar]);
            panel_log_global($pdo, $ligar === '1' ? 'manutencao_ligada' : 'manutencao_desligada');
            flash_set('success', $ligar === '1'
                ? 'Modo de manutenção ligado: só o superadmin consegue entrar.'
                : 'Modo de manutenção desligado: o sistema está aberto.');

        } elseif ($acao === 'cleanup') {
            $qual = (string) ($_POST['what'] ?? '');
            if (!isset($limpezas[$qual])) throw new RuntimeException('Limpeza desconhecida.');
            $removidos = $pdo->exec($limpezas[$qual][3]);
            panel_log_global($pdo, 'limpeza', $limpezas[$qual][0] . ': ' . (int) $removidos . ' registos');
            flash_set('success', (int) $removidos . ' registo(s) removido(s).');

        } elseif ($acao === 'optimize') {
            $tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tabelas as $tabela) {
                $pdo->query('OPTIMIZE TABLE `' . str_replace('`', '', $tabela) . '`')->fetchAll();
            }
            panel_log_global($pdo, 'tabelas_otimizadas', count($tabelas) . ' tabelas');
            flash_set('success', count($tabelas) . ' tabelas otimizadas.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a operação.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/manutencao: ' . $e->getMessage());
    }
    panel_redirect('manutencao.php');
}

$ligado = TeamHelper::maintenanceOn();
$pendentes = [];
foreach ($limpezas as $chave => [$titulo, $descricao, $sqlContar]) {
    $pendentes[$chave] = $contar($sqlContar);
}

// Verificações de integridade (devem dar todas zero)
$verificacoes = [
    'Contas sem equipa (que não são superadmin)'      => $contar("SELECT COUNT(*) FROM users WHERE team_id IS NULL AND role <> 'superadmin'"),
    'Contas de jogador sem ficha de jogador'          => $contar("SELECT COUNT(*) FROM users u WHERE u.role = 'jogador' AND NOT EXISTS (SELECT 1 FROM players p WHERE p.user_id = u.id)"),
    'Jogadores ligados a uma conta de outra equipa'   => $contar('SELECT COUNT(*) FROM players p JOIN users u ON u.id = p.user_id WHERE u.team_id IS NULL OR u.team_id <> p.team_id'),
    'Equipas ativas sem administrador'                => $contar("SELECT COUNT(*) FROM teams t WHERE t.status = 'active' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.team_id = t.id AND u.role = 'admin' AND u.status = 'active')"),
    'Estatísticas de um jogador noutro jogo que não da sua equipa' => $contar('SELECT COUNT(*) FROM game_player_stats s JOIN games g ON g.id = s.game_id JOIN players p ON p.id = s.player_id WHERE g.team_id <> p.team_id'),
    'Jogos terminados sem resultado'                  => $contar("SELECT COUNT(*) FROM games WHERE status = 'finished' AND (our_score IS NULL OR opponent_score IS NULL)"),
];
$problemas = count(array_filter($verificacoes));

$pageTitle = 'Manutenção';
$activeNav = 'manutencao';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Manutenção', 'Fechar o sistema temporariamente, limpar dados antigos e verificar a coerência dos dados.'); ?>

    <section class="lp-card lp-pad" style="border-color:var(--<?= $ligado ? 'red' : 'border' ?>);">
        <div style="display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;align-items:center;">
            <div style="max-width:60ch;">
                <span class="lp-badge lp-badge--<?= $ligado ? 'loss' : 'win' ?>" style="margin-bottom:10px;"><i class="fa-solid fa-<?= $ligado ? 'lock' : 'lock-open' ?>"></i> <?= $ligado ? 'Manutenção ligada' : 'Sistema aberto' ?></span>
                <h2 style="font-family:var(--display);font-size:26px;font-weight:800;color:var(--text);">Modo de manutenção</h2>
                <p style="margin-top:6px;font-size:14px;color:var(--text-secondary);line-height:1.6;">
                    Quando está ligado, o site público e os painéis de admin, treinador e jogador mostram um aviso de manutenção.
                    Só o superadmin continua a conseguir entrar e trabalhar.
                </p>
            </div>
            <form method="post" data-confirm="<?= $ligado ? 'Reabrir o sistema a todas as equipas?' : 'Fechar o sistema? Todas as equipas e visitantes deixam de conseguir entrar.' ?>">
                <?= panel_csrf() ?><input type="hidden" name="action" value="maintenance"><input type="hidden" name="state" value="<?= $ligado ? 'off' : 'on' ?>">
                <button type="submit" class="lp-btn <?= $ligado ? 'lp-btn--primary' : 'lp-btn--danger' ?>"><i class="fa-solid fa-<?= $ligado ? 'lock-open' : 'lock' ?>"></i> <?= $ligado ? 'Desligar manutenção' : 'Ligar manutenção' ?></button>
            </form>
        </div>
    </section>

    <div class="pn-cols pn-cols--even">
        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-broom"></i>Limpeza de dados antigos</h2></div>
            <div class="pn-list">
                <?php foreach ($limpezas as $chave => [$titulo, $descricao]): $n = $pendentes[$chave]; ?>
                <div>
                    <div class="grow"><b style="white-space:normal;"><?= pub_e($titulo) ?></b><small style="white-space:normal;"><?= pub_e($descricao) ?></small></div>
                    <span class="lp-badge lp-badge--<?= $n ? 'gold' : '' ?>"><?= $n ?> registos</span>
                    <form method="post" data-confirm="Remover <?= $n ?> registo(s)? Não é possível desfazer.">
                        <?= panel_csrf() ?><input type="hidden" name="action" value="cleanup"><input type="hidden" name="what" value="<?= $chave ?>">
                        <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm" <?= $n ? '' : 'disabled style="opacity:0.5;cursor:not-allowed;"' ?>><i class="fa-solid fa-trash"></i> Limpar</button>
                    </form>
                </div>
                <?php endforeach; ?>
                <div>
                    <div class="grow"><b>Otimizar tabelas</b><small style="white-space:normal;">Recupera espaço e reorganiza os índices. Não apaga dados.</small></div>
                    <form method="post"><?= panel_csrf() ?><input type="hidden" name="action" value="optimize">
                        <button type="submit" class="lp-btn lp-btn--ghost lp-btn--sm"><i class="fa-solid fa-gauge-high"></i> Otimizar</button></form>
                </div>
            </div>
        </section>

        <section class="lp-card">
            <div class="pn-section-title">
                <h2><i class="fa-solid fa-stethoscope"></i>Coerência dos dados</h2>
                <span class="lp-badge lp-badge--<?= $problemas ? 'loss' : 'win' ?>"><?= $problemas ? $problemas . ' a rever' : 'Tudo certo' ?></span>
            </div>
            <div class="pn-list">
                <?php foreach ($verificacoes as $rotulo => $n): ?>
                <div>
                    <span class="lp-info-icon" style="width:34px;height:34px;font-size:13px;<?= $n ? 'background:rgba(248,113,113,0.16);color:var(--red);' : 'background:rgba(52,211,153,0.16);color:var(--green);' ?>"><i class="fa-solid fa-<?= $n ? 'triangle-exclamation' : 'check' ?>"></i></span>
                    <div class="grow"><b style="white-space:normal;"><?= pub_e($rotulo) ?></b></div>
                    <b style="font-family:var(--display);font-size:20px;color:var(--<?= $n ? 'red' : 'muted' ?>);"><?= $n ?></b>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="lp-pad" style="padding-top:0;"><p class="pn-help">As contas corrigem-se em <a href="utilizadores.php" style="color:var(--accent-text);font-weight:600;">Utilizadores</a>; as fichas de jogador, no Plantel de cada equipa.</p></div>
        </section>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
