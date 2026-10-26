<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Criar conta
 *
 * Qualquer pessoa pode criar uma conta de ADEPTO para acompanhar um clube.
 * Quem é jogador do clube indica-o aqui: a conta nasce como adepto e o pedido segue
 * para os treinadores desse clube, que o aceitam (passa a jogador) ou recusam
 * (continua adepto). Contas de treinador e de administrador não se criam aqui.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/team_helper.php';
require_once __DIR__ . '/includes/auth_page.php';

if (Auth::check()) {
    header('Location: conta.php');
    exit;
}

// "Clube" tem o seu próprio formulário (dados do clube + administrador)
if (($_GET['tipo'] ?? '') === 'clube') {
    header('Location: registar-clube.php');
    exit;
}

$pdo      = Database::getConnection();
$equipas  = array_column(TeamHelper::activeTeams(), null, 'id');
$posicoes = ['PG' => 'Base (PG)', 'SG' => 'Extremo-base (SG)', 'SF' => 'Extremo (SF)', 'PF' => 'Extremo-poste (PF)', 'C' => 'Poste (C)'];

$erros = [];
$form  = [
    'name' => '', 'username' => '', 'email' => '', 'team_id' => (int) ($_GET['equipa'] ?? 0),
    'tipo' => ($_GET['tipo'] ?? '') === 'jogador' ? 'jogador' : 'adepto',
    'position' => 'PG', 'birth_date' => '', 'jersey_number' => '', 'message' => '', 'escalao' => 'seniores',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name']          = trim((string) ($_POST['name'] ?? ''));
    $form['username']      = mb_strtolower(trim((string) ($_POST['username'] ?? '')));
    $form['email']         = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $form['team_id']       = (int) ($_POST['team_id'] ?? 0);
    $form['tipo']          = ($_POST['tipo'] ?? '') === 'jogador' ? 'jogador' : 'adepto';
    $form['position']      = (string) ($_POST['position'] ?? 'PG');
    $form['birth_date']    = trim((string) ($_POST['birth_date'] ?? ''));
    $form['jersey_number'] = trim((string) ($_POST['jersey_number'] ?? ''));
    $form['message']       = trim((string) ($_POST['message'] ?? ''));
    $form['escalao']       = escalao_from_post($equipas[$form['team_id']] ?? null, $_POST['escalao'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirma = (string) ($_POST['password_confirm'] ?? '');
    $ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $erros[] = 'A sessão expirou. Tente novamente.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        // campo-armadilha: as pessoas não o veem, os robôs preenchem-no
        $erros[] = 'Não foi possível criar a conta.';
    } else {
        if ($form['name'] === '' || mb_strlen($form['name']) < 3 || mb_strlen($form['name']) > 100) $erros[] = 'Indique o seu nome completo.';
        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $form['username'])) $erros[] = 'O utilizador deve ter 3 a 50 letras minúsculas, números, ponto ou hífen.';
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 100) $erros[] = 'Indique um email válido.';
        if (strlen($password) < 8) $erros[] = 'A palavra-passe deve ter pelo menos 8 caracteres.';
        elseif (strlen($password) > 200) $erros[] = 'A palavra-passe é demasiado longa.';
        elseif ($password !== $confirma) $erros[] = 'As duas palavras-passe não coincidem.';
        elseif (mb_strtolower($password) === $form['username']) $erros[] = 'A palavra-passe não pode ser igual ao utilizador.';
        if (!isset($equipas[$form['team_id']])) $erros[] = 'Escolha o clube.';

        if ($form['tipo'] === 'jogador') {
            if (!isset($posicoes[$form['position']])) $erros[] = 'Escolha a sua posição.';
            $nasc = $form['birth_date'] !== '' ? strtotime($form['birth_date']) : false;
            if (!$nasc || $nasc > strtotime('-8 years') || $nasc < strtotime('-70 years')) $erros[] = 'Indique a sua data de nascimento.';
            if ($form['jersey_number'] !== '' && (!ctype_digit($form['jersey_number']) || (int) $form['jersey_number'] > 99)) $erros[] = 'O número da camisola deve estar entre 0 e 99.';
            if (mb_strlen($form['message']) > 500) $erros[] = 'A mensagem ao treinador é demasiado longa (máximo 500 caracteres).';
        }

        if (!$erros) {
            $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = :u OR email = :e');
            $stmt->execute(['u' => $form['username'], 'e' => $form['email']]);
            foreach ($stmt->fetchAll() as $existente) {
                if (mb_strtolower((string) $existente['username']) === $form['username']) $erros[] = 'Esse nome de utilizador já está a ser usado.';
                if (mb_strtolower((string) $existente['email']) === $form['email']) $erros[] = 'Já existe uma conta com esse email. Se é sua, recupere a palavra-passe.';
            }
        }

        // no máximo 5 contas novas por endereço IP em cada hora
        if (!$erros) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'conta_registada' AND ip_address = :ip AND created_at > :desde");
            $stmt->execute(['ip' => $ip, 'desde' => date('Y-m-d H:i:s', time() - 3600)]);
            if ((int) $stmt->fetchColumn() >= 5) $erros[] = 'Foram criadas demasiadas contas a partir deste endereço. Tente mais tarde.';
        }
    }

    if (!$erros) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO users (name, username, email, password_hash, role, team_id, status, avatar_initials)
                 VALUES (:name, :username, :email, :hash, 'adepto', :team_id, 'active', :initials)"
            )->execute([
                'name' => $form['name'], 'username' => $form['username'], 'email' => $form['email'],
                'hash' => password_hash($password, PASSWORD_DEFAULT), 'team_id' => $form['team_id'],
                'initials' => mb_strtoupper(mb_substr($form['name'], 0, 2)),
            ]);
            $userId = (int) $pdo->lastInsertId();

            if ($form['tipo'] === 'jogador') {
                $pdo->prepare(
                    'INSERT INTO player_requests (user_id, team_id, position, escalao, birth_date, jersey_number, message)
                     VALUES (:user_id, :team_id, :position, :escalao, :birth_date, :jersey, :message)'
                )->execute([
                    'user_id' => $userId, 'team_id' => $form['team_id'], 'position' => $form['position'], 'escalao' => $form['escalao'], 'birth_date' => $form['birth_date'],
                    'jersey' => $form['jersey_number'] !== '' ? (int) $form['jersey_number'] : null, 'message' => $form['message'] ?: null,
                ]);

                // avisa os treinadores do clube; se o clube ainda não tiver treinador, avisa o administrador
                $stmt = $pdo->prepare("SELECT id, role FROM users WHERE team_id = :team_id AND role IN ('treinador', 'admin') AND status = 'active'");
                $stmt->execute(['team_id' => $form['team_id']]);
                $staff = $stmt->fetchAll();
                $treinadores = array_filter($staff, fn($s) => $s['role'] === 'treinador');
                foreach ($treinadores ?: $staff as $s) {
                    notify_user($pdo, (int) $s['id'], 'Novo pedido de inscrição de jogador',
                        $form['name'] . ' criou conta e diz ser jogador de ' . $equipas[$form['team_id']]['name'] . '. Aceite ou recuse o pedido em "Pedidos de inscrição".', 'warning');
                }
            }

            $pdo->prepare("INSERT INTO audit_logs (user_id, team_id, username, action, details, ip_address) VALUES (:user_id, :team_id, :username, 'conta_registada', :details, :ip)")
                ->execute(['user_id' => $userId, 'team_id' => $form['team_id'], 'username' => $form['username'],
                           'details' => $form['tipo'] === 'jogador' ? 'adepto + pedido de jogador' : 'adepto', 'ip' => $ip ?: null]);
            $pdo->commit();

            // entra logo com a conta nova
            Auth::attempt($form['username'], $password);
            header('Location: conta.php?ok=' . ($form['tipo'] === 'jogador' ? 'pedido' : 'bemvindo'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[CourtIQ] registar: ' . $e->getMessage());
            $erros[] = 'Não foi possível criar a conta. Tente novamente.';
        }
    }
}

auth_page_top('Criar conta', 'Criar conta', 'Acompanhe o seu clube: jogos, resultados, notícias e bilhetes.');

if ($erros) auth_notice('error', implode(' ', $erros));

if (!$equipas): auth_notice('info', 'Ainda não há clubes disponíveis para se inscrever.'); else: ?>
            <form method="post" class="au-form" data-once novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="au-trap" aria-hidden="true"><label>Não preencha este campo <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                <?php $tipoConta = $form['tipo']; ?>
                <fieldset class="au-choice au-choice--3" data-tipo-conta>
                    <legend>Que conta quer criar?</legend>
                    <label><input type="radio" name="tipo" value="adepto" data-ir="registar.php?tipo=adepto"<?= $tipoConta === 'adepto' ? ' checked' : '' ?>>
                        <span><b><i class="fa-solid fa-heart"></i> Adepto</b><small>Acompanhar um clube</small></span></label>
                    <label><input type="radio" name="tipo" value="jogador" data-ir="registar.php?tipo=jogador"<?= $tipoConta === 'jogador' ? ' checked' : '' ?>>
                        <span><b><i class="fa-solid fa-basketball"></i> Jogador</b><small>Sou do plantel</small></span></label>
                    <label><input type="radio" name="tipo" value="clube" data-ir="registar-clube.php"<?= $tipoConta === 'clube' ? ' checked' : '' ?>>
                        <span><b><i class="fa-solid fa-shield-halved"></i> Clube</b><small>Gerir o meu clube</small></span></label>
                </fieldset>

                <div class="au-field">
                    <label for="team_id">Clube</label>
                    <div class="au-input"><select id="team_id" name="team_id" required>
                        <option value="">Escolha o clube…</option>
                        <?php foreach ($equipas as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $form['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= !empty($t['city']) ? ' — ' . e($t['city']) : '' ?></option><?php endforeach; ?>
                    </select></div>
                </div>

                <div class="au-field"><label for="name">Nome completo</label>
                    <div class="au-input"><input type="text" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="100" autocomplete="name" required></div></div>

                <div class="au-field"><label for="username">Utilizador</label>
                    <div class="au-input"><input type="text" id="username" name="username" value="<?= e($form['username']) ?>" maxlength="50" autocomplete="username" autocapitalize="none" spellcheck="false" required></div>
                    <p class="au-hint">É com ele que entra. Letras minúsculas, números, ponto ou hífen.</p></div>

                <div class="au-field"><label for="email">Email</label>
                    <div class="au-input"><input type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="100" autocomplete="email" required></div></div>

                <div class="au-field"><label for="password">Palavra-passe</label>
                    <div class="au-input au-input--toggle">
                        <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                        <button type="button" data-toggle-password="password" aria-label="Mostrar palavra-passe"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <p class="au-hint">Pelo menos 8 caracteres.</p></div>

                <div class="au-field"><label for="password_confirm">Repita a palavra-passe</label>
                    <div class="au-input"><input type="password" id="password_confirm" name="password_confirm" minlength="8" autocomplete="new-password" required></div></div>

                <div class="au-extra" id="dadosJogador" <?= $form['tipo'] === 'jogador' ? '' : 'hidden' ?>>
                    <p class="au-extra-title"><i class="fa-solid fa-clipboard-user"></i> Dados para o treinador</p>
                    <p class="au-hint" style="margin:0 0 4px;">O treinador do clube recebe o pedido e confirma se faz parte do plantel. Até lá, a conta funciona como adepto.</p>
                    <div class="au-two">
                        <div class="au-field"><label for="position">Posição</label>
                            <div class="au-input"><select id="position" name="position"><?php foreach ($posicoes as $k => $v): ?><option value="<?= $k ?>" <?= $form['position'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div></div>
                        <div class="au-field"><label for="jersey_number">N.º da camisola</label>
                            <div class="au-input"><input type="number" id="jersey_number" name="jersey_number" value="<?= e($form['jersey_number']) ?>" min="0" max="99" placeholder="Opcional"></div></div>
                    </div>
                    <div class="au-field"><label for="escalao">Escalão</label>
                        <div class="au-input"><select id="escalao" name="escalao"><?php foreach (ESCALOES as $k => $v): ?><option value="<?= $k ?>" <?= $form['escalao'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div></div>
                    <div class="au-field"><label for="birth_date">Data de nascimento</label>
                        <div class="au-input"><input type="date" id="birth_date" name="birth_date" value="<?= e($form['birth_date']) ?>" max="<?= date('Y-m-d', strtotime('-8 years')) ?>"></div></div>
                    <div class="au-field"><label for="message">Mensagem ao treinador (opcional)</label>
                        <div class="au-input"><textarea id="message" name="message" maxlength="500" rows="3" placeholder="Ex.: sou do escalão sénior, treino às terças e quintas."><?= e($form['message']) ?></textarea></div></div>
                </div>

                <button type="submit" class="au-btn"><i class="fa-solid fa-user-plus"></i> Criar conta</button>
            </form>

            <p class="au-alt">Já tem conta? <a href="login.php">Entrar</a></p>
            <p class="au-alt" style="margin-top:8px;font-size:12.5px;">Os treinadores recebem a conta do administrador do seu clube.</p>

            <script>
                // os dados de jogador só aparecem quando se escolhe "Jogador"
                document.querySelectorAll('input[name="tipo"]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        var tipo = document.querySelector('input[name="tipo"]:checked').value;
                        if (tipo === 'clube') { window.location.href = 'registar-clube.php'; return; }   // o clube tem formulário próprio
                        document.getElementById('dadosJogador').hidden = tipo !== 'jogador';
                    });
                });
            </script>
<?php endif;

auth_page_bottom();
