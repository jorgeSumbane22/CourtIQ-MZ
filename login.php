<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Entrar
 * Um só formulário para todos: utilizador (ou email) e palavra-passe. O tipo da conta
 * (superadmin, admin, treinador, jogador ou adepto) é lido da base de dados (users.role)
 * e cada um vai para a sua área. O clube e o escalão também vêm da conta, não se perguntam.
 * O formulário envia para esta mesma página.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/team_helper.php';
require_once __DIR__ . '/includes/auth_page.php';

/** Para onde vai cada tipo de conta depois de entrar (o adepto não tem painel: vai para a sua conta). */
$destino = function (string $role): string {
    $paineis = ['superadmin' => 'superadmin', 'admin' => 'admin', 'treinador' => 'treinador', 'jogador' => 'jogador'];
    return isset($paineis[$role]) ? $paineis[$role] . '/dashboard.php' : 'conta.php';
};

if (Auth::check()) {
    header('Location: ' . $destino(Auth::role()));
    exit;
}

$error = null;
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $usernameValue = $username;

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'csrf';
    } elseif ($username === '' || $password === '') {
        $error = 'vazio';
    } elseif (Auth::tooManyAttempts($username)) {
        $error = 'bloqueado';
    } elseif (Auth::verifyCredentials($username, $password) === null || !Auth::attempt($username, $password)) {
        // conta inexistente, inativa ou palavra-passe errada: a mensagem é sempre a mesma
        Auth::registerFailedAttempt($username);
        $error = Auth::tooManyAttempts($username) ? 'bloqueado' : 'credenciais';
    } else {
        Auth::clearAttempts($username);
        // o jogador vê sempre o seu escalão; o resto do painel escolhe o escalão dentro da própria página
        unset($_SESSION['escalao']);
        header('Location: ' . $destino(Auth::role()));
        exit;
    }
}

$minutos = max(1, (int) ceil(Auth::lockoutRemaining($usernameValue) / 60));
$errorMessages = [
    'vazio'       => 'Preencha o utilizador e a palavra-passe.',
    'credenciais' => 'Utilizador ou palavra-passe incorretos.',
    'csrf'        => 'A sessão expirou. Tente novamente.',
    'bloqueado'   => 'Demasiadas tentativas falhadas. Tente de novo dentro de ' . $minutos . ' minuto' . ($minutos > 1 ? 's' : '') . ', ou recupere a palavra-passe.',
    'sessao'      => 'Inicie sessão para continuar.',
];
$errorMessage = $errorMessages[$error ?? (string) ($_GET['erro'] ?? '')] ?? null;

$okMessages = [
    'redefinida' => 'Palavra-passe alterada. Já pode entrar com a nova.',
    'saiu'       => 'Sessão terminada.',
];
$okMessage = $okMessages[(string) ($_GET['ok'] ?? '')] ?? null;

auth_page_top('Entrar', 'Entrar', 'Use o seu utilizador e a sua palavra-passe. O sistema reconhece o tipo da sua conta e leva-o para a sua área.');

if ($okMessage)    auth_notice('success', $okMessage);
if ($errorMessage) auth_notice('error', $errorMessage);
?>
            <form method="post" class="au-form" data-once novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="au-field">
                    <label for="username">Utilizador ou email</label>
                    <div class="au-input"><input type="text" id="username" name="username" value="<?= e($usernameValue) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></div>
                </div>

                <div class="au-field">
                    <div class="au-row"><label for="password">Palavra-passe</label><a href="recuperar.php">Esqueceu-se?</a></div>
                    <div class="au-input au-input--toggle">
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                        <button type="button" data-toggle-password="password" aria-label="Mostrar palavra-passe"><i class="fa-solid fa-eye"></i></button>
                    </div>
                </div>

                <button type="submit" class="au-btn"><i class="fa-solid fa-right-to-bracket"></i> Entrar</button>
                <a class="au-btn au-btn--ghost" href="visitante/inicio.php"><i class="fa-solid fa-eye"></i> Entrar como visitante</a>
            </form>

            <p class="au-alt">Não tem conta? <a href="registar.php">Crie a sua conta</a> · <a href="registar.php?tipo=clube">Inscreva o seu clube</a></p>
<?php
auth_page_bottom();
