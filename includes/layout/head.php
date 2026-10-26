<?php
declare(strict_types=1);

/**
 * layout/head.php — início de todas as páginas: <head>, tema e dados comuns.
 *
 * A moldura das páginas tem quatro peças, cada uma no seu ficheiro:
 *     head.php     este ficheiro
 *     sidebar.php  o menu lateral
 *     navbar.php   a barra de cima
 *     footer.php   o rodapé e o fecho da página
 *
 * Quem as usa são os ficheiros {painel}_top.php e {painel}_bottom.php, que só dizem
 * o que muda de painel para painel através de $layout:
 *     panel      'public' | 'admin' | 'treinador' | 'jogador' | 'superadmin'
 *     base       caminho até à raiz do site ('' na raiz, '../' nas pastas)
 *     title      texto antes do título da página no separador do browser
 *     brandSub   texto pequeno por baixo do logótipo (vazio = nada)
 *     roleLabel  papel mostrado no cartão do fundo da sidebar
 *     sections   menu: ['Secção' => ['chave' => ['href' => …, 'icon' => …, 'label' => …]]]
 *     css        folhas de estilo além das comuns (caminhos a partir da raiz)
 * As páginas definem $pageTitle e $activeNav (e, no site, $pageIcon) antes de incluir o topo.
 */

require_once __DIR__ . '/../dark_mode_helper.php';
require_once __DIR__ . '/../team_helper.php';
require_once __DIR__ . '/../public_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('flash_render')) {
    /** Mostra (uma vez) as mensagens guardadas com flash_set(). */
    function flash_render(): void
    {
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            return;
        }
        $messages = $_SESSION['flash'];
        unset($_SESSION['flash']);
        foreach ($messages as $type => $message) {
            $class = ['success' => 'alert-success', 'error' => 'alert-error', 'warning' => 'alert-warning'][$type] ?? 'alert-info';
            echo '<div class="flash-message ' . $class . '">' . htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

$layout = ($layout ?? []) + [
    'panel' => 'public', 'base' => '../', 'title' => '', 'brandSub' => '', 'roleLabel' => '', 'sections' => [], 'css' => [],
];
$basePath  = (string) $layout['base'];
$pageTitle = $pageTitle ?? 'Início';
$activeNav = $activeNav ?? '';

// ---- quem está a usar a página
$isLoggedIn = isset($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['id']);
$userId     = (int) ($_SESSION['user']['id'] ?? 0);
$userRole   = (string) ($_SESSION['user']['role'] ?? '');
$userName   = (string) ($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? '');
$logoutPath = $basePath . 'process/logout.php';

// ---- avisos e mensagens por ler (aparecem na navbar)
$totalNotificacoesNaoLidas = 0;
$mensagensNaoLidas = 0;
$layoutAvatar = '';
if ($isLoggedIn) {
    try {
        $layoutPdo = Database::getConnection();
        $stmt = $layoutPdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $totalNotificacoesNaoLidas = (int) $stmt->fetchColumn();

        $stmt = $layoutPdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE recipient_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $mensagensNaoLidas = (int) $stmt->fetchColumn();

        // fotografia: a do perfil da conta; um jogador sem ela usa a da sua ficha
        $layoutAvatar = pub_img($_SESSION['user']['avatar_url'] ?? null, $basePath);
        if ($layoutAvatar === '' && $userRole === 'jogador') {
            $stmt = $layoutPdo->prepare('SELECT photo_url FROM players WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $layoutAvatar = pub_img($stmt->fetchColumn() ?: null, $basePath);
        }
    } catch (Throwable $e) {
        error_log('[CourtIQ] layout: ' . $e->getMessage());
    }
}

$themeClass  = getThemeClass(getCurrentTheme());
$layoutCss   = array_merge(['assets/css/layout.css', 'assets/css/public.css'], $layout['panel'] === 'public' ? [] : ['assets/css/panel.css'], (array) $layout['css']);
$layoutTitle = 'CourtIQMZ - ' . ($layout['title'] !== '' ? $layout['title'] . ' | ' : '') . $pageTitle;

lang_start();   // com o inglês escolhido, a página inteira é traduzida ao ser enviada (includes/lang.php)
?>
<!DOCTYPE html>
<html lang="pt" class="<?= e($themeClass) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($layoutTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php foreach ($layoutCss as $css): ?>
    <link rel="stylesheet" href="<?= e($basePath . $css) ?>?v=26">
    <?php endforeach; ?>
    <script>
        // textos do JavaScript no idioma escolhido: T('Apagar esta mensagem?')
        window.CIQ_T = <?= json_encode((object) lang_js_dictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        window.T = function (s) { return window.CIQ_T[s] || s; };
    </script>
    <script>
        // o tema aplica-se antes de a página ser desenhada, para não piscar
        (function () {
            var html = document.documentElement;
            try {
                var m = document.cookie.match(/courtIQ_dark_mode=([^;]+)/);
                var theme = (m && (m[1] === 'light' || m[1] === 'dark')) ? m[1] : 'dark';
                html.classList.remove('dark-mode', 'light-mode');
                html.classList.add(theme === 'light' ? 'light-mode' : 'dark-mode');
            } catch (e) {
                html.classList.add('dark-mode');
            }
        })();
    </script>
</head>
<body data-panel="<?= e($layout['panel']) ?>">
