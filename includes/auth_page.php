<?php
declare(strict_types=1);

/**
 * auth_page.php — moldura comum às páginas sem sessão iniciada:
 * login.php, recuperar.php e redefinir.php.
 */

require_once __DIR__ . '/dark_mode_helper.php';

function auth_page_top(string $title, string $heading, string $sub): void
{
    $themeClass = getThemeClass(getCurrentTheme());
    ?>
<?php lang_start();   // com o inglês escolhido, a página inteira é traduzida ao ser enviada (includes/lang.php) ?>
<!DOCTYPE html>
<html lang="pt" class="<?= e($themeClass) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> — CourtIQMZ</title>
    <script>
        // sem preferência guardada segue o sistema; aplica-se antes de pintar para não piscar
        (function () {
            var h = document.documentElement;
            if (h.classList.contains('dark-mode') || h.classList.contains('light-mode')) return;
            h.classList.add(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light-mode' : 'dark-mode');
        })();
    </script>
    <script>
        // textos do JavaScript no idioma escolhido: T('Copiado')
        window.CIQ_T = <?= json_encode((object) lang_js_dictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        window.T = function (s) { return window.CIQ_T[s] || s; };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/auth.css?v=24">
</head>
<body>
<div class="au">
    <aside class="au-brand" aria-hidden="true">
        <svg class="au-court" viewBox="0 0 600 800" preserveAspectRatio="xMidYMid slice" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="40" y="40" width="520" height="720" rx="4"/>
            <line x1="40" y1="400" x2="560" y2="400"/>
            <circle cx="300" cy="400" r="72"/>
            <rect x="210" y="40" width="180" height="210"/>
            <path d="M210 250a90 90 0 0 0 180 0"/>
            <path d="M90 40v110a210 210 0 0 0 420 0V40"/>
            <rect x="210" y="550" width="180" height="210"/>
            <path d="M210 550a90 90 0 0 1 180 0"/>
            <path d="M90 760V650a210 210 0 0 1 420 0v110"/>
        </svg>
        <div class="au-brand-in">
            <a class="au-logo" href="index.php"><span><i class="fa-solid fa-basketball"></i></span>Court<em>IQ</em><strong>MZ</strong></a>
            <p class="au-tagline">O clube inteiro<br>num só <em>campo</em>.</p>
            <ul class="au-points">
                <li><i class="fa-solid fa-user-group"></i>Plantel, jogos e treinos de cada equipa</li>
                <li><i class="fa-solid fa-chart-simple"></i>Estatísticas e presenças sempre atualizadas</li>
                <li><i class="fa-solid fa-shield-halved"></i>Cada equipa vê apenas os seus dados</li>
            </ul>
        </div>
    </aside>

    <main class="au-main">
        <div class="au-lang"><?= lang_switcher() ?></div>
        <div class="au-card">
            <a class="au-logo au-logo--mobile" href="index.php"><span><i class="fa-solid fa-basketball"></i></span>Court<em>IQ</em><strong>MZ</strong></a>
            <h1><?= e($heading) ?></h1>
            <p class="au-sub"><?= e($sub) ?></p>
    <?php
}

function auth_page_bottom(): void
{
    ?>
        </div>
        <p class="au-foot"><a href="index.php"><i class="fa-solid fa-arrow-left"></i> Voltar ao site</a><span>&copy; <?= date('Y') ?> CourtIQMZ</span></p>
    </main>
</div>
<script>
    // mostrar/esconder palavra-passe
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
            if (!input) return;
            var ver = input.type === 'password';
            input.type = ver ? 'text' : 'password';
            btn.setAttribute('aria-label', T(ver ? 'Esconder palavra-passe' : 'Mostrar palavra-passe'));
            btn.querySelector('i').className = 'fa-solid ' + (ver ? 'fa-eye-slash' : 'fa-eye');
        });
    });
    // evita envios repetidos
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
        });
    });
    // copiar ligação
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-copy'));
            if (!input) return;
            input.select();
            (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject()).catch(function () { document.execCommand('copy'); });
            btn.textContent = T('Copiado');
        });
    });
</script>
</body>
</html>
    <?php
}

/** Aviso (erro, sucesso ou informação) dentro do cartão. */
function auth_notice(string $type, string $message): void
{
    $icons = ['error' => 'circle-exclamation', 'success' => 'circle-check', 'info' => 'circle-info'];
    echo '<div class="au-notice au-notice--' . e($type) . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">'
       . '<i class="fa-solid fa-' . ($icons[$type] ?? 'circle-info') . '"></i><div>' . e($message) . '</div></div>';
}
