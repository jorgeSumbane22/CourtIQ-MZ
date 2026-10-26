<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: Configurações gerais
 * Valores globais do sistema (tabela system_config). Só as chaves desta lista são editáveis.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $user] = panel_boot_global();

/** chave => [rótulo, tipo, ajuda, grupo] */
$campos = [
    'site_name'        => ['Nome do site', 'text', '', 'Identidade'],
    'site_description' => ['Descrição', 'text', '', 'Identidade'],
    'league_name'      => ['Nome da liga', 'text', '', 'Competição'],
    'season_year'      => ['Ano da temporada', 'number', '', 'Competição'],
    'max_teams'        => ['Número máximo de equipas', 'number', 'Limite usado ao criar equipas. 0 = sem limite.', 'Competição'],
    'max_players'      => ['Máximo de jogadores por equipa', 'number', 'Valor de referência; ainda não é imposto no plantel.', 'Competição'],
    'contact_email'    => ['Email de contacto', 'email', '', 'Contacto'],
    'contact_phone'    => ['Telefone de contacto', 'text', '', 'Contacto'],
    'timezone'         => ['Fuso horário', 'text', 'Ex.: Africa/Maputo', 'Sistema'],
];

if (panel_is_post()) {
    try {
        $novos = [];
        foreach ($campos as $chave => [$rotulo, $tipo]) {
            $valor = trim((string) ($_POST[$chave] ?? ''));
            if ($tipo === 'number') {
                if ($valor === '' || !ctype_digit($valor)) throw new RuntimeException($rotulo . ': indique um número inteiro.');
            } elseif ($tipo === 'email') {
                if ($valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) throw new RuntimeException($rotulo . ': email inválido.');
            }
            if (mb_strlen($valor) > 255) throw new RuntimeException($rotulo . ': texto demasiado longo.');
            $novos[$chave] = $valor;
        }
        if ($novos['site_name'] === '') throw new RuntimeException('O nome do site não pode ficar vazio.');
        if ($novos['timezone'] !== '' && !in_array($novos['timezone'], timezone_identifiers_list(), true)) {
            throw new RuntimeException('Fuso horário desconhecido. Exemplo válido: Africa/Maputo');
        }
        $existentes = (int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
        if ((int) $novos['max_teams'] > 0 && (int) $novos['max_teams'] < $existentes) {
            throw new RuntimeException('Já existem ' . $existentes . ' equipas: o limite não pode ser inferior.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO system_config (config_key, config_value, description) VALUES (:k, :v, :d)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        foreach ($novos as $chave => $valor) {
            $stmt->execute(['k' => $chave, 'v' => $valor, 'd' => $campos[$chave][0]]);
        }
        panel_log_global($pdo, 'configuracao_alterada', 'Configurações gerais');
        flash_set('success', 'Configurações guardadas.');
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as configurações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/config-gerais: ' . $e->getMessage());
    }
    panel_redirect('config-gerais.php');
}

$valores = $pdo->query('SELECT config_key, config_value FROM system_config')->fetchAll(PDO::FETCH_KEY_PAIR);
$grupos  = [];
foreach ($campos as $chave => $def) {
    $grupos[$def[3]][$chave] = $def;
}

$pageTitle = 'Configurações';
$activeNav = 'config-gerais';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Configurações gerais', 'Valores que se aplicam a todo o sistema.'); ?>

    <form method="post" class="lp-stack" style="gap:24px;">
        <?= panel_csrf() ?>
        <div class="pn-cols pn-cols--even">
            <?php foreach ($grupos as $grupo => $lista): ?>
            <section class="lp-card">
                <div class="pn-section-title"><h2><i class="fa-solid fa-sliders"></i><?= pub_e($grupo) ?></h2></div>
                <div class="lp-pad lp-form">
                    <?php foreach ($lista as $chave => [$rotulo, $tipo, $ajuda]): ?>
                    <div class="lp-field">
                        <label for="c-<?= $chave ?>"><?= pub_e($rotulo) ?></label>
                        <input id="c-<?= $chave ?>" name="<?= $chave ?>" type="<?= $tipo ?>" maxlength="255" <?= $tipo === 'number' ? 'min="0" required' : '' ?> value="<?= pub_e($valores[$chave] ?? '') ?>">
                        <?php if ($ajuda !== ''): ?><p class="pn-help"><?= pub_e($ajuda) ?></p><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
        </div>
        <div class="pn-form-actions">
            <a class="lp-btn lp-btn--ghost lp-btn--sm" href="manutencao.php"><i class="fa-solid fa-screwdriver-wrench"></i> Modo de manutenção</a>
            <button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar configurações</button>
        </div>
    </form>
</div>

<?php require panel_layout('bottom'); ?>
