<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Recuperar palavra-passe
 *
 * Quem se esqueceu da palavra-passe indica o utilizador ou o email.
 *   - Com o email configurado (includes/Mailer.php): recebe uma ligação válida 1 hora.
 *   - Sem email: o pedido chega como notificação ao administrador da equipa (ou ao
 *     superadmin, se for um administrador), que repõe o acesso no painel.
 * A resposta é sempre a mesma, exista ou não a conta, para não revelar quem está registado.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth_page.php';
require_once __DIR__ . '/includes/password_reset.php';
require_once __DIR__ . '/includes/Mailer.php';

if (Auth::check()) {
    header('Location: login.php');
    exit;
}

$config   = require __DIR__ . '/config/config.php';
$temEmail = Mailer::isConfigured();
// só num computador de desenvolvimento, e só para quem está sentado nele
$modoLocal = !$temEmail && ($config['env'] ?? '') === 'development'
          && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

$erro = null;
$enviado = false;
$ligacaoLocal = null;
$valor = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valor = trim((string) ($_POST['identifier'] ?? ''));

    // no máximo 5 pedidos por sessão em 15 minutos
    $pedidos = array_filter((array) ($_SESSION['pr_requests'] ?? []), fn($t) => (int) $t > time() - 900);

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $erro = 'A sessão expirou. Tente novamente.';
    } elseif ($valor === '' || mb_strlen($valor) > 100) {
        $erro = 'Indique o seu utilizador ou email.';
    } elseif (count($pedidos) >= 5) {
        $erro = 'Fez demasiados pedidos seguidos. Aguarde alguns minutos.';
    } else {
        $pedidos[] = time();
        $_SESSION['pr_requests'] = array_values($pedidos);
        $enviado = true;

        try {
            $pdo  = Database::getConnection();
            $stmt = $pdo->prepare("SELECT id, name, username, email, role, team_id FROM users WHERE (username = :u OR email = :e) AND status = 'active' LIMIT 1");
            $stmt->execute(['u' => $valor, 'e' => $valor]);
            $conta = $stmt->fetch();

            if ($conta && pr_recent($pdo, (int) $conta['id']) < 3) {
                $token = pr_create($pdo, (int) $conta['id']);
                $link  = pr_link($token);
                pr_log($pdo, $conta, 'password_reset_pedido', $temEmail ? 'email' : 'aviso ao administrador');

                if ($temEmail) {
                    Mailer::send(
                        (string) $conta['email'],
                        'Recuperar a palavra-passe do CourtIQMZ',
                        Mailer::template(
                            'Recuperar palavra-passe',
                            'Olá ' . e($conta['name']) . ',<br><br>Recebemos um pedido para escolher uma palavra-passe nova para a conta <b>' . e($conta['username']) . '</b>. '
                            . 'A ligação é válida durante 1 hora e só pode ser usada uma vez.<br><br>Se não foi você, ignore este email: a sua palavra-passe continua igual.',
                            'Escolher palavra-passe nova',
                            $link
                        ),
                        (string) $conta['name']
                    );
                } else {
                    pr_notify_admins($pdo, $conta);
                    if ($modoLocal) $ligacaoLocal = $link;
                }
            }
        } catch (Throwable $e) {
            error_log('[CourtIQ] recuperar: ' . $e->getMessage());
        }
    }
}

auth_page_top('Recuperar palavra-passe', 'Recuperar acesso', 'Indique o utilizador ou o email da sua conta.');

if ($erro) auth_notice('error', $erro);

if ($enviado):
    auth_notice('success', $temEmail
        ? 'Se existir uma conta com esses dados, enviámos para o email dela uma ligação para escolher uma palavra-passe nova. É válida durante 1 hora.'
        : 'Se existir uma conta com esses dados, o administrador da equipa foi avisado e vai entrar em contacto para lhe repor o acesso.');

    if ($ligacaoLocal): ?>
            <div class="au-dev">
                <b><i class="fa-solid fa-screwdriver-wrench"></i> Modo de desenvolvimento</b>
                O envio de email não está configurado. Como está a aceder a partir deste computador, pode usar a ligação diretamente:
                <div class="au-copy"><input id="devLink" type="text" readonly value="<?= e($ligacaoLocal) ?>" aria-label="Ligação de recuperação"><button type="button" data-copy="devLink">Copiar</button></div>
                <p class="au-hint"><a href="<?= e($ligacaoLocal) ?>">Abrir a ligação</a></p>
            </div>
    <?php endif; ?>
            <a class="au-btn au-btn--ghost" href="login.php"><i class="fa-solid fa-arrow-left"></i> Voltar a entrar</a>
<?php else: ?>
            <form method="post" class="au-form" data-once novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="au-field">
                    <label for="identifier">Utilizador ou email</label>
                    <div class="au-input"><input type="text" id="identifier" name="identifier" value="<?= e($valor) ?>" maxlength="100" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></div>
                    <p class="au-hint"><?= $temEmail
                        ? 'Enviamos uma ligação para o email da conta.'
                        : 'O pedido segue para o administrador da sua equipa, que lhe repõe o acesso.' ?></p>
                </div>
                <button type="submit" class="au-btn"><i class="fa-solid fa-paper-plane"></i> Pedir recuperação</button>
            </form>
            <p class="au-alt"><a href="login.php">Lembrei-me, voltar a entrar</a></p>
<?php endif;

auth_page_bottom();
