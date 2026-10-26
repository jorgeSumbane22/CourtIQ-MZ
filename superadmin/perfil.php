<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Superadmin: O meu perfil
 * Dados, fotografia e palavra-passe da conta do superadministrador
 * (os outros perfis têm isto em Configurações, no painel do seu clube).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require_once __DIR__ . '/../includes/upload_helper.php';

[$pdo, $user] = panel_boot_global();
$userId = (int) $user['id'];
const PERFIL_PASTA = 0;   // as fotografias de quem não tem clube ficam em uploads/equipa_0/

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');

    try {
        if ($acao === 'profile') {
            $nome     = trim((string) ($_POST['name'] ?? ''));
            $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $telefone = trim((string) ($_POST['phone'] ?? ''));
            if ($nome === '' || mb_strlen($nome) > 100) throw new RuntimeException('Indique o seu nome.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) throw new RuntimeException('Indique um email válido.');
            if ($telefone !== '' && !preg_match('/^[0-9 +()-]{6,20}$/', $telefone)) throw new RuntimeException('Telefone inválido.');

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id');
            $stmt->execute(['email' => $email, 'id' => $userId]);
            if ($stmt->fetch()) throw new RuntimeException('Esse email já está a ser usado por outra conta.');

            // fotografia: sem ficheiro novo mantém-se a atual
            $stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $fotoAntiga = $stmt->fetchColumn() ?: null;
            $foto = $fotoAntiga;
            if (!empty($_POST['remove_avatar'])) $foto = null;
            if (upload_has('avatar')) $foto = upload_image('avatar', 'contas', PERFIL_PASTA);

            $pdo->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, avatar_initials = :initials, avatar_url = :avatar WHERE id = :id')
                ->execute(['name' => $nome, 'email' => $email, 'phone' => $telefone ?: null, 'initials' => mb_strtoupper(mb_substr($nome, 0, 2)), 'avatar' => $foto, 'id' => $userId]);
            if ($foto !== $fotoAntiga) upload_delete($fotoAntiga, PERFIL_PASTA);

            // a sessão acompanha os novos dados
            $_SESSION['user']['name']  = $_SESSION['user']['nome'] = $nome;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['phone'] = $telefone;
            $_SESSION['user']['avatar_url'] = (string) $foto;
            panel_log_global($pdo, 'perfil_atualizado', $nome);
            flash_set('success', 'Dados da conta atualizados.');

        } elseif ($acao === 'password') {
            $atual = (string) ($_POST['current_password'] ?? '');
            $nova  = (string) ($_POST['new_password'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            if (!password_verify($atual, (string) $stmt->fetchColumn())) throw new RuntimeException('A palavra-passe atual não está correta.');
            if (strlen($nova) < 8 || strlen($nova) > 200) throw new RuntimeException('A nova palavra-passe deve ter pelo menos 8 caracteres.');
            if ($nova !== (string) ($_POST['confirm_password'] ?? '')) throw new RuntimeException('A confirmação não coincide com a nova palavra-passe.');
            if ($nova === $atual) throw new RuntimeException('A nova palavra-passe tem de ser diferente da atual.');

            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute(['hash' => password_hash($nova, PASSWORD_DEFAULT), 'id' => $userId]);
            panel_log_global($pdo, 'password_alterada', '');
            flash_set('success', 'Palavra-passe alterada.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] superadmin/perfil: ' . $e->getMessage());
    }
    panel_redirect('perfil.php');
}

$stmt = $pdo->prepare('SELECT id, name, username, email, phone, avatar_url, last_login, created_at FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$conta = $stmt->fetch();

$pageTitle = 'O meu perfil';
$activeNav = 'perfil';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('O meu perfil', 'A sua conta de superadministrador.'); ?>

    <div class="pn-cols pn-cols--even">
        <form method="post" class="lp-card" enctype="multipart/form-data">
            <div class="pn-section-title"><h2><i class="fa-solid fa-user-gear"></i>Dados da conta</h2></div>
            <div class="lp-pad lp-form">
                <?= panel_csrf() ?><input type="hidden" name="action" value="profile">
                <div class="pn-user" style="gap:16px;">
                    <?= pn_avatar($conta['avatar_url'] ?? null, $conta['name'], '../', 'lg') ?>
                    <div class="lp-field" style="flex:1;min-width:0;"><label for="p-avatar">Fotografia de perfil</label>
                        <input id="p-avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                        <p class="pn-help">JPG, PNG, WEBP ou GIF até 4 MB.</p></div>
                </div>
                <?php if (!empty($conta['avatar_url'])): ?><label class="lp-check"><input type="checkbox" name="remove_avatar" value="1"><span>Remover a fotografia atual</span></label><?php endif; ?>
                <div class="lp-field"><label for="p-user">Utilizador</label><input id="p-user" type="text" value="<?= pub_e($conta['username']) ?>" disabled></div>
                <div class="lp-field"><label for="p-name">Nome</label><input id="p-name" name="name" type="text" maxlength="100" required autocomplete="name" value="<?= pub_e($conta['name']) ?>"></div>
                <div class="lp-field"><label for="p-email">Email</label><input id="p-email" name="email" type="email" maxlength="100" required autocomplete="email" value="<?= pub_e($conta['email']) ?>"></div>
                <div class="lp-field"><label for="p-phone">Telefone</label><input id="p-phone" name="phone" type="tel" maxlength="20" autocomplete="tel" value="<?= pub_e($conta['phone']) ?>"></div>
                <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar dados</button></div>
            </div>
        </form>

        <form method="post" class="lp-card" autocomplete="off">
            <div class="pn-section-title"><h2><i class="fa-solid fa-key"></i>Palavra-passe</h2></div>
            <div class="lp-pad lp-form">
                <?= panel_csrf() ?><input type="hidden" name="action" value="password">
                <div class="lp-field"><label for="w-cur">Palavra-passe atual</label><input id="w-cur" name="current_password" type="password" required autocomplete="current-password"></div>
                <div class="lp-field"><label for="w-new">Nova palavra-passe (mín. 8 caracteres)</label><input id="w-new" name="new_password" type="password" minlength="8" required autocomplete="new-password"></div>
                <div class="lp-field"><label for="w-conf">Confirmar nova palavra-passe</label><input id="w-conf" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
                <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-key"></i> Alterar palavra-passe</button></div>
                <p class="pn-help">É a única conta de superadministrador: guarde a palavra-passe num local seguro. Último acesso: <?= $conta['last_login'] ? pub_e(pub_date($conta['last_login'], 'd/m/Y H:i')) : '—' ?>.</p>
            </div>
        </form>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
