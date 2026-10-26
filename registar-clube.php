<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Inscrever um clube
 *
 * O responsável de um clube pede aqui a adesão: dados do clube, escalões que tem e a
 * conta de administrador que quer usar. O pedido fica à espera do superadministrador
 * (Superadmin → Pedidos de clubes); só depois de aprovado é que o clube e a conta
 * passam a existir. Até lá não se cria nenhuma conta com privilégios.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/team_helper.php';
require_once __DIR__ . '/includes/auth_page.php';
require_once __DIR__ . '/includes/clubs.php';

if (Auth::check()) {
    header('Location: conta.php');
    exit;
}

$pdo   = Database::getConnection();
$erros = [];
$feito = isset($_GET['ok']);
$form  = ['club_name' => '', 'city' => '', 'stadium' => '', 'founded_year' => '', 'escaloes' => array_keys(ESCALOES),
          'admin_name' => '', 'admin_username' => '', 'admin_email' => '', 'admin_phone' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['club_name', 'city', 'stadium', 'founded_year', 'admin_name', 'admin_phone', 'message'] as $campo) {
        $form[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
    $form['admin_username'] = mb_strtolower(trim((string) ($_POST['admin_username'] ?? '')));
    $form['admin_email']    = mb_strtolower(trim((string) ($_POST['admin_email'] ?? '')));
    $form['escaloes']       = array_values(array_intersect(array_keys(ESCALOES), array_map('strval', (array) ($_POST['escaloes'] ?? []))));
    $password = (string) ($_POST['password'] ?? '');
    $ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $erros[] = 'A sessão expirou. Tente novamente.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        $erros[] = 'Não foi possível enviar o pedido.';
    } else {
        if (mb_strlen($form['club_name']) < 3 || mb_strlen($form['club_name']) > 100) $erros[] = 'Indique o nome do clube.';
        else {
            try { team_name_check($form['club_name']); } catch (RuntimeException $e) { $erros[] = $e->getMessage(); }
        }
        if ($form['city'] === '' || mb_strlen($form['city']) > 100) $erros[] = 'Indique a cidade do clube.';
        if (mb_strlen($form['stadium']) > 100) $erros[] = 'O nome do pavilhão é demasiado longo.';
        if ($form['founded_year'] !== '' && (!ctype_digit($form['founded_year']) || (int) $form['founded_year'] < 1901 || (int) $form['founded_year'] > (int) date('Y'))) $erros[] = 'Ano de fundação inválido.';
        if (!$form['escaloes']) $erros[] = 'Escolha pelo menos um escalão.';
        if (mb_strlen($form['admin_name']) < 3 || mb_strlen($form['admin_name']) > 100) $erros[] = 'Indique o nome do responsável.';
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $form['admin_username'])) $erros[] = 'O utilizador deve ter 3 a 50 letras minúsculas, números, ponto ou hífen.';
        if (!filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['admin_email']) > 100) $erros[] = 'Indique um email válido.';
        if ($form['admin_phone'] !== '' && !preg_match('/^[0-9 +()-]{6,20}$/', $form['admin_phone'])) $erros[] = 'Telefone inválido.';
        if (strlen($password) < 8 || strlen($password) > 200) $erros[] = 'A palavra-passe deve ter pelo menos 8 caracteres.';
        elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) $erros[] = 'As duas palavras-passe não coincidem.';
        elseif (mb_strtolower($password) === $form['admin_username']) $erros[] = 'A palavra-passe não pode ser igual ao utilizador.';
        if (mb_strlen($form['message']) > 500) $erros[] = 'A mensagem é demasiado longa (máximo 500 caracteres).';

        if (!$erros) {
            // o nome, o utilizador e o email não podem colidir com o que já existe nem com outro pedido à espera
            $stmt = $pdo->prepare("SELECT 1 FROM teams WHERE name = :n UNION SELECT 1 FROM club_requests WHERE club_name = :n2 AND status = 'pending'");
            $stmt->execute(['n' => $form['club_name'], 'n2' => $form['club_name']]);
            if ($stmt->fetch()) $erros[] = 'Já existe um clube (ou um pedido à espera) com esse nome.';

            $stmt = $pdo->prepare(
                "SELECT 1 FROM users WHERE username = :u OR email = :e
                 UNION SELECT 1 FROM club_requests WHERE status = 'pending' AND (admin_username = :u2 OR admin_email = :e2)"
            );
            $stmt->execute(['u' => $form['admin_username'], 'e' => $form['admin_email'], 'u2' => $form['admin_username'], 'e2' => $form['admin_email']]);
            if ($stmt->fetch()) $erros[] = 'Esse utilizador ou email já está a ser usado. Escolha outro.';
        }
        if (!$erros) {
            // no máximo 3 pedidos por endereço IP em cada dia
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM club_requests WHERE ip_address = :ip AND created_at > :desde');
            $stmt->execute(['ip' => $ip, 'desde' => date('Y-m-d H:i:s', time() - 86400)]);
            if ((int) $stmt->fetchColumn() >= 3) $erros[] = 'Já foram enviados vários pedidos a partir deste endereço. Tente amanhã.';
        }
    }

    if (!$erros) {
        $pedidoId = 0;
        try {
            // com a aprovação automática ligada e vagas disponíveis, o clube fica ativo já e quem o
            // inscreveu entra como administrador; caso contrário o pedido espera pelo superadministrador
            $imediato = club_auto_approve($pdo) && club_slots($pdo) !== 0;

            $pdo->prepare(
                'INSERT INTO club_requests (club_name, city, stadium, founded_year, escaloes, admin_name, admin_username, admin_email, admin_phone, password_hash, message, ip_address)
                 VALUES (:club_name, :city, :stadium, :founded_year, :escaloes, :admin_name, :admin_username, :admin_email, :admin_phone, :hash, :message, :ip)'
            )->execute([
                'club_name' => $form['club_name'], 'city' => $form['city'], 'stadium' => $form['stadium'] ?: null,
                'founded_year' => $form['founded_year'] !== '' ? (int) $form['founded_year'] : null, 'escaloes' => implode(',', $form['escaloes']),
                'admin_name' => $form['admin_name'], 'admin_username' => $form['admin_username'], 'admin_email' => $form['admin_email'],
                'admin_phone' => $form['admin_phone'] ?: null, 'hash' => password_hash($password, PASSWORD_DEFAULT),
                'message' => $form['message'] ?: null, 'ip' => $ip ?: null,
            ]);
            $pedidoId = (int) $pdo->lastInsertId();
            $superadmins = $pdo->query("SELECT id FROM users WHERE role = 'superadmin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);

            if ($imediato) {
                $stmt = $pdo->prepare('SELECT * FROM club_requests WHERE id = :id');
                $stmt->execute(['id' => $pedidoId]);
                [$teamId, $adminId] = club_create($pdo, $stmt->fetch());
                $pdo->prepare("UPDATE club_requests SET status = 'approved', decided_at = :now, decision_note = 'Aprovação automática', team_id = :team_id, password_hash = '' WHERE id = :id")
                    ->execute(['now' => date('Y-m-d H:i:s'), 'team_id' => $teamId, 'id' => $pedidoId]);
                $pdo->prepare("INSERT INTO audit_logs (user_id, team_id, username, action, details, ip_address) VALUES (:u, :t, :n, 'clube_registado', :d, :ip)")
                    ->execute(['u' => $adminId, 't' => $teamId, 'n' => $form['admin_username'], 'd' => $form['club_name'], 'ip' => $ip ?: null]);
                foreach ($superadmins as $superId) {
                    notify_user($pdo, (int) $superId, 'Novo clube registado',
                        $form['club_name'] . ' (' . $form['city'] . ') inscreveu-se e já está ativo. Administrador: ' . $form['admin_name'] . ' (' . $form['admin_username'] . ').', 'info');
                }
                // entra logo como administrador do clube novo
                Auth::attempt($form['admin_username'], $password);
                header('Location: ' . (Auth::check() ? 'admin/equipas.php' : 'login.php'));
                exit;
            }

            foreach ($superadmins as $superId) {
                notify_user($pdo, (int) $superId, 'Novo pedido de inscrição de clube',
                    $form['club_name'] . ' (' . $form['city'] . ') pede para aderir. Responsável: ' . $form['admin_name'] . '. Decida em "Pedidos de clubes".', 'warning');
            }
            header('Location: registar-clube.php?ok=1');
            exit;
        } catch (RuntimeException $e) {
            // a criação imediata não foi possível: o pedido não fica a meio
            if ($pedidoId) $pdo->prepare('DELETE FROM club_requests WHERE id = :id')->execute(['id' => $pedidoId]);
            $erros[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[CourtIQ] registar-clube: ' . $e->getMessage());
            $erros[] = 'Não foi possível enviar o pedido. Tente novamente.';
        }
    }
}

if ($feito):
    auth_page_top('Pedido enviado', 'Pedido enviado', 'O seu clube está à espera de aprovação.');
    auth_notice('success', 'Recebemos o pedido. Quando for aprovado, entre com o utilizador e a palavra-passe que escolheu; até lá essa conta ainda não existe.');
    ?>
            <a class="au-btn au-btn--ghost" href="login.php"><i class="fa-solid fa-arrow-left"></i> Voltar a entrar</a>
    <?php
    auth_page_bottom();
    exit;
endif;

$entraJa   = club_auto_approve($pdo) && club_slots($pdo) !== 0;
$tipoConta = 'clube';
auth_page_top('Criar conta de clube', 'Criar conta', 'Faça a gestão do seu clube: plantel por escalões, jogos, treinos e site próprio.');

if ($erros) auth_notice('error', implode(' ', $erros));
?>
            <form method="post" class="au-form" data-once novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="au-trap" aria-hidden="true"><label>Não preencha este campo <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                <fieldset class="au-choice au-choice--3" data-tipo-conta>
                    <legend>Que conta quer criar?</legend>
                    <label><input type="radio" name="tipo" value="adepto" data-ir="registar.php?tipo=adepto"<?= $tipoConta === 'adepto' ? ' checked' : '' ?>>
                        <span><b><i class="fa-solid fa-heart"></i> Adepto</b><small>Acompanhar um clube</small></span></label>
                    <label><input type="radio" name="tipo" value="jogador" data-ir="registar.php?tipo=jogador"<?= $tipoConta === 'jogador' ? ' checked' : '' ?>>
                        <span><b><i class="fa-solid fa-basketball"></i> Jogador</b><small>Sou do plantel</small></span></label>
                    <label><input type="radio" name="tipo" value="clube" data-ir="registar-clube.php"<?= $tipoConta === 'clube' ? ' checked' : '' ?>>
                        <span><b><i class="fa-solid fa-shield-halved"></i> Clube</b><small>Gerir o meu clube</small></span></label>
                </fieldset>

                <p class="au-extra-title"><i class="fa-solid fa-shield-halved"></i> O clube</p>
                <div class="au-field"><label for="club_name">Nome do clube</label>
                    <div class="au-input"><input type="text" id="club_name" name="club_name" value="<?= e($form['club_name']) ?>" maxlength="100" required autofocus></div></div>
                <div class="au-two">
                    <div class="au-field"><label for="city">Cidade</label>
                        <div class="au-input"><input type="text" id="city" name="city" value="<?= e($form['city']) ?>" maxlength="100" required></div></div>
                    <div class="au-field"><label for="founded_year">Ano de fundação</label>
                        <div class="au-input"><input type="number" id="founded_year" name="founded_year" value="<?= e($form['founded_year']) ?>" min="1901" max="<?= date('Y') ?>" placeholder="Opcional"></div></div>
                </div>
                <div class="au-field"><label for="stadium">Pavilhão</label>
                    <div class="au-input"><input type="text" id="stadium" name="stadium" value="<?= e($form['stadium']) ?>" maxlength="100" placeholder="Opcional"></div></div>

                <fieldset class="au-choice au-choice--3">
                    <legend>Escalões do clube</legend>
                    <?php foreach (ESCALOES as $k => $v): ?>
                    <label><input type="checkbox" name="escaloes[]" value="<?= $k ?>" <?= in_array($k, $form['escaloes'], true) ? 'checked' : '' ?>>
                        <span><b><?= e($v) ?></b></span></label>
                    <?php endforeach; ?>
                </fieldset>

                <p class="au-extra-title" style="margin-top:8px;"><i class="fa-solid fa-user-tie"></i> O responsável (administrador do clube)</p>
                <div class="au-field"><label for="admin_name">Nome completo</label>
                    <div class="au-input"><input type="text" id="admin_name" name="admin_name" value="<?= e($form['admin_name']) ?>" maxlength="100" autocomplete="name" required></div></div>
                <div class="au-two">
                    <div class="au-field"><label for="admin_email">Email</label>
                        <div class="au-input"><input type="email" id="admin_email" name="admin_email" value="<?= e($form['admin_email']) ?>" maxlength="100" autocomplete="email" required></div></div>
                    <div class="au-field"><label for="admin_phone">Telefone</label>
                        <div class="au-input"><input type="tel" id="admin_phone" name="admin_phone" value="<?= e($form['admin_phone']) ?>" maxlength="20" autocomplete="tel" placeholder="Opcional"></div></div>
                </div>
                <div class="au-field"><label for="admin_username">Utilizador</label>
                    <div class="au-input"><input type="text" id="admin_username" name="admin_username" value="<?= e($form['admin_username']) ?>" maxlength="50" autocomplete="username" autocapitalize="none" spellcheck="false" required></div>
                    <p class="au-hint"><?= $entraJa ? 'É com ele que entra como administrador do clube.' : 'É com ele que vai entrar depois de o pedido ser aprovado.' ?></p></div>
                <div class="au-field"><label for="password">Palavra-passe</label>
                    <div class="au-input au-input--toggle">
                        <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                        <button type="button" data-toggle-password="password" aria-label="Mostrar palavra-passe"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <p class="au-hint">Pelo menos 8 caracteres.</p></div>
                <div class="au-field"><label for="password_confirm">Repita a palavra-passe</label>
                    <div class="au-input"><input type="password" id="password_confirm" name="password_confirm" minlength="8" autocomplete="new-password" required></div></div>
                <div class="au-field"><label for="message">Mensagem (opcional)</label>
                    <div class="au-input"><textarea id="message" name="message" maxlength="500" rows="3" placeholder="Ex.: competimos na liga provincial desde 2019."><?= e($form['message']) ?></textarea></div></div>

                <button type="submit" class="au-btn"><i class="fa-solid fa-<?= $entraJa ? 'user-plus' : 'paper-plane' ?>"></i> <?= $entraJa ? 'Criar clube e entrar' : 'Enviar pedido' ?></button>
            </form>

            <p class="au-alt"><?= $entraJa
                ? 'Os seus dados ficam como administrador do clube: entra logo e pode começar a geri-lo.'
                : 'O pedido é analisado pela equipa do CourtIQMZ antes de o clube ficar ativo.' ?></p>
            <p class="au-alt" style="margin-top:8px;">Já tem conta? <a href="login.php">Entrar</a></p>

            <script>
                // Adepto e Jogador têm o formulário em registar.php
                document.querySelectorAll('[data-tipo-conta] input').forEach(function (radio) {
                    radio.addEventListener('change', function () { if (radio.value !== 'clube') window.location.href = radio.getAttribute('data-ir'); });
                });
            </script>
<?php
auth_page_bottom();
