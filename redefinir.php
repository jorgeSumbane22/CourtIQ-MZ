<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Escolher palavra-passe nova
 * Abre-se a partir de uma ligação de uso único (ver includes/password_reset.php).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth_page.php';
require_once __DIR__ . '/includes/password_reset.php';

// a ligação não deve ficar em caches nem seguir no cabeçalho Referer
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$pdo   = Database::getConnection();
$conta = pr_find($pdo, $token);
$erro  = null;

if ($conta && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova     = (string) ($_POST['password'] ?? '');
    $confirma = (string) ($_POST['password_confirm'] ?? '');

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $erro = 'A sessão expirou. Tente novamente.';
    } elseif (strlen($nova) < PR_MIN_LENGTH) {
        $erro = 'A palavra-passe deve ter pelo menos ' . PR_MIN_LENGTH . ' caracteres.';
    } elseif (strlen($nova) > 200) {
        $erro = 'A palavra-passe é demasiado longa.';
    } elseif ($nova !== $confirma) {
        $erro = 'As duas palavras-passe não coincidem.';
    } elseif (mb_strtolower($nova) === mb_strtolower((string) $conta['username'])) {
        $erro = 'A palavra-passe não pode ser igual ao nome de utilizador.';
    } else {
        try {
            pr_consume($pdo, $conta, $nova);
            pr_log($pdo, $conta, 'password_reset_concluido');
            // quem estava com sessão iniciada noutra conta neste browser sai
            if (Auth::check()) Auth::logout();
            if (session_status() === PHP_SESSION_NONE) session_start();
            Auth::clearAttempts((string) $conta['username']);   // a palavra-passe nova levanta o bloqueio da conta
            header('Location: login.php?ok=redefinida');
            exit;
        } catch (Throwable $e) {
            error_log('[CourtIQ] redefinir: ' . $e->getMessage());
            $erro = 'Não foi possível guardar a palavra-passe. Tente novamente.';
        }
    }
}

if (!$conta):
    auth_page_top('Ligação inválida', 'Ligação inválida', 'Esta ligação já foi usada, expirou ou está incompleta.');
    ?>
            <a class="au-btn" href="recuperar.php"><i class="fa-solid fa-rotate-right"></i> Pedir nova ligação</a>
            <p class="au-alt"><a href="login.php">Voltar a entrar</a></p>
    <?php
    auth_page_bottom();
    exit;
endif;

auth_page_top('Nova palavra-passe', 'Nova palavra-passe', 'Conta ' . $conta['username'] . ' — ' . $conta['name'] . '.');

if ($erro) auth_notice('error', $erro);
?>
            <form method="post" class="au-form" data-once novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="text" name="username" value="<?= e($conta['username']) ?>" autocomplete="username" hidden>

                <div class="au-field">
                    <label for="password">Palavra-passe nova</label>
                    <div class="au-input au-input--toggle">
                        <input type="password" id="password" name="password" minlength="<?= PR_MIN_LENGTH ?>" autocomplete="new-password" required autofocus>
                        <button type="button" data-toggle-password="password" aria-label="Mostrar palavra-passe"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <p class="au-hint">Pelo menos <?= PR_MIN_LENGTH ?> caracteres.</p>
                </div>

                <div class="au-field">
                    <label for="password_confirm">Repita a palavra-passe</label>
                    <div class="au-input"><input type="password" id="password_confirm" name="password_confirm" minlength="<?= PR_MIN_LENGTH ?>" autocomplete="new-password" required></div>
                </div>

                <button type="submit" class="au-btn"><i class="fa-solid fa-check"></i> Guardar palavra-passe</button>
            </form>
<?php
auth_page_bottom();
