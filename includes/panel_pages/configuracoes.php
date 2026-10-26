<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Painéis (admin e treinador): Configurações
 * A conta do próprio administrador: dados pessoais e palavra-passe.
 */


require_once __DIR__ . '/../upload_helper.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$userId = (int) $user['id'];
// as fotografias de perfil ficam na pasta da equipa da própria conta
$pastaFotos = (int) ($user['team_id'] ?? 0) ?: $teamId;

if (panel_is_post()) {
    $acao = (string) ($_POST['action'] ?? '');

    try {
        if ($acao === 'profile') {
            $nome     = trim((string) ($_POST['name'] ?? ''));
            $email    = trim((string) ($_POST['email'] ?? ''));
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
            if (upload_has('avatar')) $foto = upload_image('avatar', 'contas', $pastaFotos);

            $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, avatar_initials = :initials, avatar_url = :avatar WHERE id = :id');
            $stmt->execute(['name' => $nome, 'email' => $email, 'phone' => $telefone ?: null, 'initials' => mb_strtoupper(mb_substr($nome, 0, 2)), 'avatar' => $foto, 'id' => $userId]);
            if ($foto !== $fotoAntiga) upload_delete($fotoAntiga, $pastaFotos);
            $_SESSION['user']['avatar_url'] = (string) $foto;

            // a sessão acompanha os novos dados
            $_SESSION['user']['name']  = $nome;
            $_SESSION['user']['nome']  = $nome;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['phone'] = $telefone;
            panel_log($pdo, $teamId, 'perfil_atualizado', $nome);
            flash_set('success', 'Dados da conta atualizados.');

        } elseif ($acao === 'password') {
            $atual = (string) ($_POST['current_password'] ?? '');
            $nova  = (string) ($_POST['new_password'] ?? '');
            $conf  = (string) ($_POST['confirm_password'] ?? '');

            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $hash = (string) $stmt->fetchColumn();

            if (!password_verify($atual, $hash)) throw new RuntimeException('A palavra-passe atual não está correta.');
            if (strlen($nova) < 8) throw new RuntimeException('A nova palavra-passe deve ter pelo menos 8 caracteres.');
            if ($nova !== $conf) throw new RuntimeException('A confirmação não coincide com a nova palavra-passe.');
            if ($nova === $atual) throw new RuntimeException('A nova palavra-passe tem de ser diferente da atual.');

            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => password_hash($nova, PASSWORD_DEFAULT), 'id' => $userId]);
            panel_log($pdo, $teamId, 'password_alterada', '');
            flash_set('success', 'Palavra-passe alterada.');

        } elseif ($acao === 'ticket') {
            // pedido de suporte ao superadmin (aparece em Superadmin > Tickets)
            $assunto  = trim((string) ($_POST['subject'] ?? ''));
            $mensagem = trim((string) ($_POST['message'] ?? ''));
            $prio     = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? (string) $_POST['priority'] : 'normal';
            if ($assunto === '' || mb_strlen($assunto) > 200) throw new RuntimeException('Indique o assunto do pedido.');
            if (mb_strlen($mensagem) < 10) throw new RuntimeException('Descreva o problema (pelo menos 10 caracteres).');
            $pdo->prepare('INSERT INTO support_tickets (user_id, team_id, subject, message, priority) VALUES (:u, :t, :s, :m, :p)')
                ->execute(['u' => $userId, 't' => $teamId, 's' => $assunto, 'm' => $mensagem, 'p' => $prio]);
            flash_set('success', 'Pedido de suporte enviado. A resposta chega pelas notificações.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível guardar as alterações.');
        if (!$e instanceof RuntimeException) error_log('[CourtIQ] admin/configuracoes: ' . $e->getMessage());
    }
    panel_redirect('configuracoes.php');
}

$stmt = $pdo->prepare('SELECT id, name, username, email, phone, role, avatar_url, last_login, created_at FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$conta = $stmt->fetch();

$papeis = ['admin' => 'Administrador', 'treinador' => 'Treinador', 'jogador' => 'Jogador', 'superadmin' => 'Superadministrador'];

// Os meus pedidos de suporte e as respostas
$stmt = $pdo->prepare('SELECT * FROM support_tickets WHERE user_id = :u ORDER BY created_at DESC LIMIT 10');
$stmt->execute(['u' => $userId]);
$pedidos = $stmt->fetchAll();
$respostasSuporte = [];
if ($pedidos) {
    $stmt = $pdo->prepare(
        'SELECT r.*, u.name AS user_name FROM ticket_replies r JOIN support_tickets s ON s.id = r.ticket_id LEFT JOIN users u ON u.id = r.user_id
         WHERE s.user_id = :u ORDER BY r.created_at, r.id'
    );
    $stmt->execute(['u' => $userId]);
    foreach ($stmt->fetchAll() as $r) {
        $respostasSuporte[(int) $r['ticket_id']][] = $r;
    }
}
$estadosPedido = ['open' => ['Aberto', 'gold'], 'answered' => ['Respondido', 'win'], 'closed' => ['Fechado', '']];

$pageTitle = 'Configurações';
$activeNav = 'configuracoes';
require panel_layout('top');
?>

<div class="lp pn">
    <?php panel_head('Configurações', 'A sua conta no CourtIQMZ.'); ?>

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
                <div class="lp-field"><label for="p-name">Nome</label><input id="p-name" name="name" type="text" maxlength="100" required autocomplete="name" value="<?= pub_e($conta['name']) ?>"></div>
                <div class="lp-field"><label for="p-email">Email</label><input id="p-email" name="email" type="email" maxlength="100" required autocomplete="email" value="<?= pub_e($conta['email']) ?>"></div>
                <div class="lp-field"><label for="p-phone">Telefone</label><input id="p-phone" name="phone" type="tel" maxlength="20" autocomplete="tel" value="<?= pub_e($conta['phone']) ?>"></div>
                <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-check"></i> Guardar dados</button></div>
            </div>
        </form>

        <div class="lp-stack" style="gap:24px;">
            <form method="post" class="lp-card" autocomplete="off">
                <div class="pn-section-title"><h2><i class="fa-solid fa-key"></i>Palavra-passe</h2></div>
                <div class="lp-pad lp-form">
                    <?= panel_csrf() ?><input type="hidden" name="action" value="password">
                    <div class="lp-field"><label for="w-current">Palavra-passe atual</label><input id="w-current" name="current_password" type="password" required autocomplete="current-password"></div>
                    <div class="lp-field"><label for="w-new">Nova palavra-passe (mín. 8 caracteres)</label><input id="w-new" name="new_password" type="password" minlength="8" required autocomplete="new-password"></div>
                    <div class="lp-field"><label for="w-confirm">Confirmar nova palavra-passe</label><input id="w-confirm" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
                    <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-key"></i> Alterar palavra-passe</button></div>
                </div>
            </form>

            <section class="lp-card lp-pad">
                <h2 class="lp-sub">Sobre esta conta</h2>
                <ul class="lp-info">
                    <li><span class="lp-info-icon"><i class="fa-solid fa-user"></i></span><div><small>Utilizador</small><b><?= pub_e($conta['username']) ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-id-badge"></i></span><div><small>Papel</small><b><?= pub_e($papeis[$conta['role']] ?? $conta['role']) ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-shield-halved"></i></span><div><small>Equipa</small><b><?= pub_e($team['name']) ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-calendar-plus"></i></span><div><small>Conta criada em</small><b><?= pub_e(pub_date($conta['created_at'])) ?></b></div></li>
                    <li><span class="lp-info-icon"><i class="fa-solid fa-circle-half-stroke"></i></span><div><small>Tema</small><b>Use o botão no canto inferior direito para alternar entre claro e escuro.</b></div></li>
                </ul>
            </section>
        </div>
    </div>
    <div class="pn-cols pn-cols--even" id="suporte">
        <form method="post" class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-headset"></i>Pedir suporte</h2><small>Vai para o superadministrador</small></div>
            <div class="lp-pad lp-form">
                <?= panel_csrf() ?><input type="hidden" name="action" value="ticket">
                <div class="pn-form-grid">
                    <div class="lp-field"><label for="s-subject">Assunto</label><input id="s-subject" name="subject" type="text" maxlength="200" required></div>
                    <div class="lp-field"><label for="s-priority">Prioridade</label>
                        <select id="s-priority" name="priority"><option value="normal">Normal</option><option value="low">Baixa</option><option value="high">Alta</option></select>
                    </div>
                    <div class="lp-field full"><label for="s-message">O que se passa?</label><textarea id="s-message" name="message" required minlength="10" style="min-height:110px;"></textarea></div>
                </div>
                <div class="pn-form-actions"><button type="submit" class="lp-btn lp-btn--primary lp-btn--sm"><i class="fa-solid fa-paper-plane"></i> Enviar pedido</button></div>
            </div>
        </form>

        <section class="lp-card">
            <div class="pn-section-title"><h2><i class="fa-solid fa-ticket"></i>Os meus pedidos</h2><small><?= count($pedidos) ?></small></div>
            <div class="pn-list">
                <?php foreach ($pedidos as $p): [$rotuloEstado, $tipoEstado] = $estadosPedido[$p['status']] ?? ['—', '']; ?>
                <div style="align-items:flex-start;">
                    <div class="grow">
                        <b style="white-space:normal;"><?= pub_e($p['subject']) ?></b>
                        <small><?= pub_e(pub_date($p['created_at'], 'd/m/Y H:i')) ?></small>
                        <?php foreach ($respostasSuporte[(int) $p['id']] ?? [] as $r): ?>
                        <small style="white-space:normal;font-size:13px;color:var(--text-secondary);margin-top:6px;padding:8px 12px;border-radius:10px;background:var(--glow);">
                            <b style="display:inline;font-size:12px;">Suporte, <?= pub_e(pub_date($r['created_at'], 'd/m H:i')) ?>:</b> <?= nl2br(pub_e($r['message'])) ?>
                        </small>
                        <?php endforeach; ?>
                    </div>
                    <span class="lp-badge lp-badge--<?= $tipoEstado ?>"><?= $rotuloEstado ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$pedidos): ?><div class="pn-empty"><i class="fa-solid fa-ticket"></i>Ainda não fez nenhum pedido de suporte.</div><?php endif; ?>
        </section>
    </div>
</div>

<?php require panel_layout('bottom'); ?>
